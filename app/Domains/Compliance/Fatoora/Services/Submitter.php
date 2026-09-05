<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA submission service.
 *
 * Orchestrates the full invoice submission workflow:
 * - Generate compliance data
 * - Choose clearance vs reporting
 * - Submit to ZATCA
 * - Update invoice state
 *
 * This is the single entry point for ZATCA submissions.
 */
class Submitter
{
    /**
     * Every dependency is required, none optional.
     *
     * The container skips optional constructor parameters, so an optional
     * BranchService resolves to null and the branch credential lookup below
     * becomes unreachable — signing a branch's invoice with the organization's
     * certificate, which ZATCA reads as a different device. A missing binding
     * should fail at boot instead.
     */
    public function __construct(
        private readonly DocumentBuilder $compliance,
        private readonly FatooraClient $client,
        private readonly AuditService $audit,
        private readonly CertificateService $certificateService,
        private readonly BranchService $branchService,
        private readonly CredentialStore $credentials,
        private readonly KillSwitch $killSwitch,
        private readonly ChainRecorder $chain,
    ) {}

    /**
     * Generate compliance data and issue invoice.
     *
     * @return array{hash: string, qr_code: string}
     */
    public function generate(Invoice $invoice, Organization $organization): array
    {
        // Issuance is what this method does: it signs the document and marks
        // the invoice Issued, which is the irreversible step. KillSwitch offers
        // an issuance stop for a signing defect caught in production, and
        // nothing consulted it — so the switch could be thrown while documents
        // continued to be signed and issued.
        $this->killSwitch->assertNotEnabled(KillSwitch::SWITCH_ISSUANCE, (string) $organization->id);
        $this->killSwitch->assertNotEnabled(KillSwitch::SWITCH_SIGNING, (string) $organization->id);

        // Get signing credentials if available
        $credentials = $this->getSigningCredentials($organization->id);

        // Counter, predecessor, document and chain entry are decided together,
        // inside one transaction holding the organization row.
        //
        // Reading the PIH outside this block is what let the chain fork: two
        // issuances for one tenant both read the head before either wrote, and
        // both claimed it. Allocating the counter here as well closes the
        // other way in — a document issued ahead of a lower-numbered draft,
        // which needs no concurrency at all to produce the same break.
        //
        // The lock is the organization row rather than the invoice rows, for
        // the reason generateNextIcv() gives: there are no invoice rows to
        // lock for a tenant's first document, so two requests would both find
        // nothing and both allocate 1. The organization row always exists.
        //
        // Signing happens under that lock, which is the cost. It serialises
        // issuance per tenant — correct, and worth measuring if a tenant ever
        // issues fast enough to feel it. An advisory lock keyed on org_id
        // would free the row without changing the guarantee.
        $complianceData = DB::transaction(function () use ($invoice, $organization, $credentials): array {
            DB::table('organizations')
                ->where('id', $invoice->org_id)
                ->lockForUpdate()
                ->first();

            // A draft carries no counter. Anything already numbered keeps its
            // number, so re-issuing a document does not move it in the chain.
            if ($invoice->icv === null) {
                $invoice->icv = Invoice::generateNextIcv((string) $invoice->org_id);
            }

            $previousHash = $invoice->previous_invoice_hash;

            $complianceData = $this->compliance->generateComplianceData(
                invoice: $invoice,
                organization: $organization,
                previousInvoiceHash: $previousHash,
                privateKey: $credentials['privateKey'] ?? null,
                certificate: $credentials['certificate'] ?? null,
            );

            $invoice->update([
                'icv' => $invoice->icv,
                'hash' => $complianceData['hash'],
                'qr_code' => $complianceData['qr_code'],
                'signed_xml' => $complianceData['signed_xml'] ?? null,
                'status' => InvoiceStatus::Issued,
            ]);

            $this->chain->record(
                invoice: $invoice,
                invoiceHash: $complianceData['hash'],
                previousHash: $previousHash,
                certificate: $credentials['certificate'] ?? null,
            );

            return $complianceData;
        });

        return [
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
            'signed_xml' => $complianceData['signed_xml'] ?? null,
        ];
    }

    /**
     * Validate invoice with ZATCA (without submission).
     */
    public function validate(Invoice $invoice, Organization $organization): FatooraResponse
    {
        $previousHash = $invoice->previous_invoice_hash;
        $credentials = $this->getSigningCredentials($organization->id);

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            previousInvoiceHash: $previousHash,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        return $this->client->checkCompliance(
            invoiceXml: $complianceData['xml'],
            invoiceHash: $complianceData['hash'],
            uuid: $invoice->id,
        );
    }

    /**
     * Submit invoice to ZATCA (clearance or reporting).
     *
     * COMPLIANCE: Validates license environment matches ZATCA environment.
     * Sandbox licenses cannot submit to production ZATCA.
     *
     * Supports both branch-level and organization-level credentials.
     * If invoice has a branch_id, uses branch credentials; otherwise falls back to org credentials.
     *
     * @throws FatooraException If organization not onboarded or credentials missing
     */
    public function submit(Invoice $invoice, Organization $organization): FatooraResponse
    {
        // CRITICAL: Validate organization has completed ZATCA onboarding
        $this->validateOnboarding($organization);

        // Validate environment before submission
        $this->validateEnvironment();

        // Issuance is what allocates the counter and fixes the predecessor,
        // and generate() is the only place that happens. A document submitted
        // without having been issued used to reach the authority carrying
        // ICV 0 and the genesis PIH, because this method builds its own
        // document and nothing here had assigned either.
        //
        // Calling it here rather than throwing keeps the direct submit path
        // working for callers that never asked for a separate issuance step.
        // Already-issued documents are left alone: generate() keeps a counter
        // it finds, so this does not move anything in the chain.
        if ($invoice->icv === null || $invoice->hash === null) {
            $this->generate($invoice, $organization);
            $invoice->refresh();
        }

        // A branch signs with its own credentials; an invoice that names no
        // branch signs with the organization's. invoices.branch_id is nullable
        // rather than absent, so isset() is asking whether this document came
        // from a particular EGS unit, not whether the column exists.
        $branch = isset($invoice->branch_id) ? $invoice->branch : null;
        $credentials = $this->getSigningCredentials($organization->id, $branch, required: true);

        // Validate certificate is valid and not revoked before submission
        $this->validateCertificate($credentials['certificate']);

        // Validate branch is active if invoice has branch
        if ($branch && ! $branch->isFatooraReady()) {
            throw FatooraException::notOnboarded(
                'Branch is not ready for invoice submission. '.
                'Status: '.$branch->onboarding_status,
                [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'onboarding_status' => $branch->onboarding_status,
                ]
            );
        }

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            // Passed explicitly: the parameter defaults to null and XmlBuilder
            // reads a null as the genesis PIH, so omitting it makes every
            // document claim to be the first in its chain.
            previousInvoiceHash: $invoice->previous_invoice_hash,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        // Choose clearance (B2B) or reporting (B2C) based on invoice type
        if ($invoice->requiresClearance()) {
            // B2B: Submit for clearance (no deadline)
            $response = $this->client->clearInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );
        } else {
            // B2C: Report invoice - ZATCA requires reporting within 24 hours
            $this->validateReportingDeadline($invoice);
            $response = $this->client->reportInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );
        }

        // Update invoice status based on response
        $this->updateInvoiceStatus($invoice, $response);

        // Audit log the ZATCA submission
        $this->audit->logZatcaSubmission($invoice, $response->success, [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
            'errors' => $response->errorMessages,
        ]);

        return $response;
    }

    /**
     * Validate certificate is valid and not revoked.
     *
     * COMPLIANCE: Checks certificate expiry and revocation status before submission.
     * Prevents submission with expired or revoked certificates.
     *
     * @throws FatooraException If certificate is invalid, expired, or revoked
     */
    private function validateCertificate(?string $certificate): void
    {
        if (empty($certificate)) {
            return; // Already handled by getSigningCredentials
        }

        // Check if certificate validation is enabled
        if (! config('fatoora.features.certificate_revocation_check', true)) {
            return;
        }

        try {
            $validation = $this->certificateService->validateForSubmission($certificate);

            if (! $validation['valid']) {
                $errors = $validation['errors'] ?? [];
                throw FatooraException::certificate(
                    'Certificate validation failed: '.implode('; ', $errors),
                    context: [
                        'errors' => $errors,
                        'warnings' => $validation['warnings'] ?? [],
                        'days_until_expiry' => $validation['days_until_expiry'] ?? null,
                    ]
                );
            }

            // Log warning if certificate is expiring soon
            $daysUntilExpiry = $validation['days_until_expiry'] ?? null;
            if ($daysUntilExpiry !== null && $daysUntilExpiry <= 30) {
                Log::warning('Certificate expiring soon', [
                    'days_until_expiry' => $daysUntilExpiry,
                    'warnings' => $validation['warnings'] ?? [],
                ]);
            }
        } catch (FatooraException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Log but don't block submission if validation fails unexpectedly
            Log::warning('Certificate validation failed unexpectedly', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate B2C invoice reporting deadline.
     *
     * COMPLIANCE: ZATCA requires simplified (B2C) invoices to be reported
     * within 24 hours of issuance. This method enforces that deadline.
     *
     * @throws FatooraException If invoice is older than 24 hours
     */
    private function validateReportingDeadline(Invoice $invoice): void
    {
        // Get the deadline hours from config (default 24 hours per ZATCA)
        $deadlineHours = config('fatoora.reporting.deadline_hours', 24);

        // Skip deadline check if explicitly disabled
        if (! config('fatoora.reporting.enforce_deadline', true)) {
            return;
        }

        $issueDate = $invoice->issue_date;
        if (! $issueDate instanceof \DateTimeInterface) {
            $issueDate = new \DateTime($issueDate);
        }

        $now = new \DateTime;
        $ageHours = ($now->getTimestamp() - $issueDate->getTimestamp()) / 3600;

        if ($ageHours > $deadlineHours) {
            throw FatooraException::validation(
                sprintf(
                    'B2C invoice reporting deadline exceeded. Invoice was issued %.1f hours ago. '.
                    'ZATCA requires simplified invoices to be reported within %d hours of issuance.',
                    $ageHours,
                    $deadlineHours
                ),
                context: [
                    'invoice_id' => $invoice->id,
                    'issue_date' => $issueDate->format('Y-m-d H:i:s'),
                    'age_hours' => round($ageHours, 2),
                    'deadline_hours' => $deadlineHours,
                ]
            );
        }

        // Log warning if approaching deadline (>20 hours)
        if ($ageHours > ($deadlineHours * 0.8)) {
            Log::warning('B2C invoice approaching reporting deadline', [
                'invoice_id' => $invoice->id,
                'age_hours' => round($ageHours, 2),
                'deadline_hours' => $deadlineHours,
                'remaining_hours' => round($deadlineHours - $ageHours, 2),
            ]);
        }
    }

    /**
     * Validate organization has completed ZATCA onboarding.
     *
     * COMPLIANCE: Organizations must complete the full onboarding process
     * (CSR generation, compliance check, PCSID acquisition) before submitting invoices.
     *
     * @throws FatooraException If organization not onboarded
     */
    private function validateOnboarding(Organization $organization): void
    {
        if (! $organization->zatca_onboarded) {
            throw FatooraException::notOnboarded(
                'Organization has not completed ZATCA onboarding. '.
                'Complete the 3-step onboarding process before submitting invoices: '.
                '1) Generate CSR and get CCSID, 2) Pass compliance checks, 3) Get PCSID.',
                [
                    'org_id' => $organization->id,
                    'zatca_onboarded' => false,
                ]
            );
        }
    }

    /**
     * Validate license environment matches ZATCA environment.
     *
     * COMPLIANCE: Prevents sandbox API keys from submitting to production ZATCA.
     * This is a critical safety check to avoid test data in production.
     *
     * @throws FatooraException If environment mismatch detected
     */
    private function validateEnvironment(): void
    {
        $zatcaEnvironment = $this->client->getEnvironment();

        // If ZATCA is configured for production, verify license allows production
        if ($zatcaEnvironment === 'production') {
            $license = request()->attributes->get('license');

            if ($license !== null) {
                $licenseEnv = $license->environment;

                // Sandbox licenses cannot submit to production ZATCA
                if ($licenseEnv === LicenseEnvironment::Sandbox) {
                    throw FatooraException::environmentMismatch(
                        'Sandbox API keys cannot submit invoices to production ZATCA. '.
                        'Please use a production API key (cp_live_*) for real invoice submissions.',
                        [
                            'license_environment' => $licenseEnv->value,
                            'zatca_environment' => $zatcaEnvironment,
                        ]
                    );
                }
            }
        }

        // If license is production but ZATCA is sandbox, log warning but allow
        // (useful for testing production keys against sandbox)
        $license = request()->attributes->get('license');
        if ($license !== null && $license->environment === LicenseEnvironment::Production) {
            if ($zatcaEnvironment === 'sandbox') {
                Log::info('Production license submitting to sandbox ZATCA', [
                    'license_id' => $license->id,
                    'zatca_environment' => $zatcaEnvironment,
                ]);
            }
        }
    }

    /**
     * Update invoice status after ZATCA response.
     */
    private function updateInvoiceStatus(Invoice $invoice, FatooraResponse $response): void
    {
        $changes = [
            'status' => $response->success ? InvoiceStatus::Accepted : InvoiceStatus::Rejected,
            'zatca_response' => [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
                'validation_status' => $response->validationStatus,
                'warnings' => $response->warningMessages,
                'errors' => $response->errorMessages,
            ],
        ];

        // Keep the document the authority cleared.
        //
        // ZATCA stamps the invoice it clears and returns it. That stamped
        // copy is the legal invoice; the one submitted is only what was asked
        // for. Only clearance returns a document — reporting acknowledges one,
        // so cleared_xml stays null for a simplified invoice.
        if ($cleared = $this->clearedXml($response)) {
            $changes['cleared_xml'] = $cleared;
        }

        $invoice->update($changes);

        // Increment branch invoice count if successful
        if ($response->success) {
            $this->incrementBranchInvoiceCount($invoice);
        }
    }

    /**
     * The cleared document from a response, as XML.
     *
     * ZATCA returns it base64-encoded. Anything that does not decode to a
     * document is kept verbatim rather than discarded — losing the authority's
     * copy because it arrived in an unexpected shape is the worse failure, and
     * it is visible either way.
     */
    private function clearedXml(FatooraResponse $response): ?string
    {
        if (empty($response->clearedInvoice)) {
            return null;
        }

        $decoded = base64_decode($response->clearedInvoice, true);

        if ($decoded === false || ! str_contains($decoded, '<')) {
            Log::warning('Cleared invoice did not decode as XML; keeping it as sent.', [
                'length' => strlen($response->clearedInvoice),
            ]);

            return $response->clearedInvoice;
        }

        return $decoded;
    }

    /**
     * The certificate this invoice is signed with.
     *
     * Credentials live at one of two levels. BranchOnboardingController
     * stores them under a branch; OnboardingController stores them under the
     * organization, with a null branch id.
     *
     * A branch is its own EGS unit holding its own certificate, so an invoice
     * that names a branch signs with that branch's. An organization that has
     * not divided itself into EGS units has a single certificate and no branch
     * to hold it, and its invoices sign with that.
     *
     * @param  string  $organizationId  The organization ID
     * @param  Branch|null  $branch  The branch (optional)
     * @param  bool  $required  If true, throws exception when credentials are missing
     * @return array{privateKey: ?string, certificate: ?string}
     *
     * @throws FatooraException If required is true and credentials are missing/invalid
     */
    private function getSigningCredentials(string $organizationId, ?Branch $branch = null, bool $required = false): array
    {
        // A branch is an EGS unit with its own certificate, so its credentials
        // take precedence over the organization's.
        if ($branch) {
            $branchCredentials = $this->branchService->getCredentials($branch, 'pcsid');

            if ($branchCredentials) {
                $privateKey = $branchCredentials['privateKey'] ?? null;
                $certificate = $branchCredentials['pcsid'] ?? null;

                if (! empty($privateKey) && ! empty($certificate)) {
                    Log::debug('Using branch-level credentials', [
                        'org_id' => $organizationId,
                        'branch_id' => $branch->id,
                    ]);

                    return [
                        'privateKey' => $privateKey,
                        'certificate' => $certificate,
                    ];
                }
            }
        }

        // The organization's own certificate: used when the invoice names no
        // branch, or the branch it names holds no credentials of its own.
        $path = "zatca/{$organizationId}/pcsid.json";
        $organizationCredentials = $this->credentials->get($organizationId, null, CredentialStore::PCSID);

        if ($organizationCredentials === null) {
            if ($required) {
                $errorContext = [
                    'org_id' => $organizationId,
                    'expected_path' => $path,
                ];

                if ($branch) {
                    $errorContext['branch_id'] = $branch->id;
                    $errorContext['branch_name'] = $branch->name;
                }

                throw FatooraException::missingCredentials(
                    'PCSID credentials not found. '.
                    ($branch ? 'Branch' : 'Organization').' must complete ZATCA onboarding '.
                    'to obtain Production CSID (PCSID) before submitting invoices.',
                    $errorContext
                );
            }

            return ['privateKey' => null, 'certificate' => null];
        }

        try {
            $data = $organizationCredentials;

            $privateKey = $data['privateKey'] ?? null;
            $certificate = $data['pcsid'] ?? null;

            // Validate credentials are not empty when required
            if ($required && (empty($privateKey) || empty($certificate))) {
                throw FatooraException::invalidCredentials(
                    'PCSID credentials are incomplete or corrupted. '.
                    'Please re-run Step 3 of ZATCA onboarding to obtain valid PCSID.',
                    [
                        'org_id' => $organizationId,
                        'has_private_key' => ! empty($privateKey),
                        'has_certificate' => ! empty($certificate),
                    ]
                );
            }

            Log::debug('Using organization-level credentials', [
                'org_id' => $organizationId,
            ]);

            return [
                'privateKey' => $privateKey,
                'certificate' => $certificate,
            ];
        } catch (FatooraException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($required) {
                throw FatooraException::invalidCredentials(
                    'Failed to decrypt PCSID credentials. The credentials may be corrupted '.
                    'or the application encryption key has changed.',
                    [
                        'org_id' => $organizationId,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            return ['privateKey' => null, 'certificate' => null];
        }
    }

    /**
     * Increment branch invoice count after successful submission.
     */
    private function incrementBranchInvoiceCount(Invoice $invoice): void
    {
        if ($invoice->branch_id && $invoice->branch) {
            $invoice->branch->incrementInvoiceCount();
        }
    }
}
