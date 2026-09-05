<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What we send is what we kept.
 *
 * Issuance signs the document and archives it. Submission is transport, and
 * transport does not get to produce a different document — the archive is the
 * evidence of what was issued, and if the bytes ZATCA saw are not those bytes,
 * the evidence is of something else.
 *
 * They used to differ. submit() called generateComplianceData() a second time
 * and sent that instead of what generate() had stored, so at minimum the two
 * carried different XAdES SigningTime values, and any input that had moved in
 * between — the predecessor, before the counter was fixed at issuance — made
 * them differ in ways that matter.
 */
class SubmittedDocumentTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    /** @var array{xml: string, hash: string} */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = $this->selfSignedCredentials();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']],
        );

        $client = Mockery::mock(FatooraClient::class);
        $client->shouldReceive('getEnvironment')->andReturn('sandbox');

        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturnUsing(
                function (string $xml, string $hash): FatooraResponse {
                    $this->sent = ['xml' => $xml, 'hash' => $hash];

                    return new FatooraResponse(
                        success: true,
                        clearanceStatus: 'CLEARED',
                        reportingStatus: null,
                        validationStatus: 'PASS',
                        clearedInvoice: null,
                        validationResults: [],
                        warningMessages: [],
                        errorMessages: [],
                        rawResponse: null,
                    );
                }
            );
        }

        $this->app->instance(FatooraClient::class, $client);
    }

    /**
     * The bytes ZATCA receives are the bytes on the invoice.
     */
    public function test_submitted_xml_is_the_archived_xml(): void
    {
        $invoice = $this->issue('INV-1');

        app(Submitter::class)->submit($invoice->fresh(['lines']), $this->organization);

        $this->assertSame($invoice->fresh()->signed_xml, $this->sent['xml']);
    }

    /**
     * And the hash sent alongside them describes them.
     */
    public function test_submitted_hash_is_the_archived_hash(): void
    {
        $invoice = $this->issue('INV-1');

        app(Submitter::class)->submit($invoice->fresh(['lines']), $this->organization);

        $this->assertSame($invoice->fresh()->hash, $this->sent['hash']);
    }

    /**
     * Submitting does not re-sign, so the document does not change under a
     * caller who submits twice.
     */
    public function test_submitting_does_not_change_the_document(): void
    {
        $invoice = $this->issue('INV-1');
        $archived = $invoice->fresh()->signed_xml;

        app(Submitter::class)->submit($invoice->fresh(['lines']), $this->organization);

        $this->assertSame($archived, $invoice->fresh()->signed_xml);
    }

    private function issue(string $number): Invoice
    {
        $invoice = Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        return $invoice;
    }
}
