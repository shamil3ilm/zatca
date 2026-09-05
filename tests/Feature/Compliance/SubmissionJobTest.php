<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Events\InvoiceCleared;
use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The queue job that puts documents in front of ZATCA, run for real.
 *
 * It is the path the pipeline uses, and nothing executed it. Three defects
 * were in it, each invisible without running it:
 *
 *   - the private key and certificate were read from model attributes that do
 *     not exist, so DocumentBuilder had nothing to sign with and the document
 *     went out unsigned;
 *   - the previous invoice hash came from another absent attribute, so every
 *     document claimed to be first in its chain;
 *   - any successful call marked a B2B document 'cleared', when ZATCA's
 *     "REPORTED" means received and not yet cleared.
 *
 * ZATCA itself is the boundary, so FatooraClient is a double. Everything on
 * this side of it — credentials, signing, the chain, state, events — is real.
 */
class SubmissionJobTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );
    }

    /**
     * The document ZATCA receives is signed. Unsigned, it is refused — and the
     * job silently produced unsigned documents for as long as it read the key
     * from an attribute that does not exist.
     */
    public function test_submitted_document_is_signed(): void
    {
        $sent = $this->runJob($this->clearedResponse());

        $this->assertStringContainsString('<ds:Signature', $sent['xml']);
        $this->assertStringContainsString('UBLExtension', $sent['xml']);
    }

    /**
     * The second document in a chain carries the first one's hash.
     */
    public function test_submitted_document_chains(): void
    {
        $first = $this->invoice('INV-1');
        // An issued document holds a counter as well as a hash: the counter is what
        // gives it a position for the next one to chain to.
        $first->forceFill(['icv' => 1, 'hash' => 'PRIOR-HASH'])->save();

        $sent = $this->runJob($this->clearedResponse(), $this->invoice('INV-2'));

        $this->assertStringContainsString('PRIOR-HASH', $sent['xml']);
    }

    /**
     * Only what ZATCA returns decides this. A B2B document the authority has
     * merely acknowledged is reported, not cleared.
     */
    public function test_reported_is_not_recorded_as_cleared(): void
    {
        $submission = $this->submission($this->invoice('INV-1'));

        $this->runJob(
            $this->response(null, 'REPORTED'),
            submission: $submission
        );

        $this->assertNotSame('cleared', $submission->fresh()->state);
    }

    public function test_cleared_is_recorded_as_cleared(): void
    {
        $submission = $this->submission($this->invoice('INV-1'));

        $this->runJob($this->clearedResponse(), submission: $submission);

        $this->assertSame('cleared', $submission->fresh()->state);
    }

    /**
     * The webhook an integrator acts on must follow the same truth, or a
     * document merely acknowledged is announced as cleared.
     */
    public function test_cleared_event_needs_real_clearance(): void
    {
        Event::fake([InvoiceCleared::class]);

        $this->runJob($this->response(null, 'REPORTED'));

        Event::assertNotDispatched(InvoiceCleared::class);
    }

    /**
     * Every field is required, so a small helper keeps the intent of each test
     * visible: only the two status fields differ between them.
     */
    private function response(?string $clearanceStatus, ?string $reportingStatus = null): FatooraResponse
    {
        return new FatooraResponse(
            success: true,
            clearanceStatus: $clearanceStatus,
            reportingStatus: $reportingStatus,
            validationStatus: 'PASS',
            clearedInvoice: null,
            validationResults: [],
            warningMessages: [],
            errorMessages: [],
            rawResponse: null,
        );
    }

    private function clearedResponse(): FatooraResponse
    {
        return $this->response('CLEARED');
    }

    /**
     * Runs the job with ZATCA doubled, returning what was sent to it.
     *
     * @return array{xml: string, hash: string}
     */
    private function runJob(FatooraResponse $response, ?Invoice $invoice = null, ?InvoiceSubmission $submission = null): array
    {
        $sent = [];

        $client = \Mockery::mock(FatooraClient::class);
        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)
                ->andReturnUsing(function (string $xml, string $hash) use (&$sent, $response) {
                    $sent = ['xml' => $xml, 'hash' => $hash];

                    return $response;
                });
        }
        $this->app->instance(FatooraClient::class, $client);

        $submission ??= $this->submission($invoice ?? $this->invoice('INV-1'));

        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        return $sent;
    }

    private function submission(Invoice $invoice): InvoiceSubmission
    {
        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->organization->id,
            'state' => 'queued',
            'submission_type' => 'clearance',
        ]));
    }

    private function invoice(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        return $invoice->fresh(['lines']);
    }
}
