<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Xml;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * BT-3 tells ZATCA what kind of document this is.
 *
 * It is a seven-character string on cbc:InvoiceTypeCode/@name: the first two
 * characters say standard (01) or simplified (02), and the remaining five are
 * flags for third-party, nominal, export, summary and self-billed. Getting a
 * digit wrong does not fail anywhere — the document is well-formed, the
 * signature is valid, and the authority files it as something it is not. An
 * export invoice recorded as a domestic one is a misstatement to the tax
 * authority.
 *
 * The flags travel from columns on the invoice, through buildXmlData, into a
 * DTO, and out through getInvoiceTypeName(). Nothing asserted they arrive, and
 * a value that quietly stops being carried is exactly the failure this codebase
 * keeps producing.
 */
class InvoiceTypeCodeTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    /** @var array{privateKey: string, certificate: string} */
    private array $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentials = $this->selfSignedCredentials();

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
    }

    public function test_standard_invoice_is_zero_one(): void
    {
        $this->assertSame('0100000', $this->typeNameFor([]));
    }

    public function test_simplified_invoice_is_zero_two(): void
    {
        $this->assertSame('0200000', $this->typeNameFor(['type' => 'simplified']));
    }

    /**
     * Each flag owns one position, so a misplaced one relabels the document as
     * a different kind entirely — an export invoice filed as domestic.
     */
    public function test_each_flag_has_its_position(): void
    {
        $positions = [
            'is_third_party' => '0110000',
            'is_nominal' => '0101000',
            'is_export' => '0100100',
            'is_summary' => '0100010',
            'is_self_billed' => '0100001',
        ];

        foreach ($positions as $column => $expected) {
            $this->assertSame($expected, $this->typeNameFor([$column => true]), $column);
        }
    }

    public function test_flags_combine(): void
    {
        $this->assertSame('0210101', $this->typeNameFor([
            'type' => 'simplified',
            'is_third_party' => true,
            'is_export' => true,
            'is_self_billed' => true,
        ]));
    }

    /**
     * The seller's VAT number is what ties the document to a taxpayer, and it
     * comes from the organization rather than the invoice.
     */
    public function test_seller_vat_reaches_the_document(): void
    {
        $xpath = $this->xpathFor($this->generate([]));

        $node = $xpath->query('//cac:AccountingSupplierParty//cbc:CompanyID')->item(0);

        $this->assertNotNull($node, 'The document carries no seller VAT number (BT-31).');
        $this->assertSame('300000000000003', trim($node->textContent));
    }

    /**
     * ICV is the counter the PIH chain is built on, so a document that does not
     * carry it cannot be placed in the sequence.
     */
    public function test_icv_reaches_the_document(): void
    {
        // Numbered explicitly: the counter is taken at issuance, and this
        // builds a document straight from DocumentBuilder without going
        // through it. An unnumbered draft has no position to carry.
        $invoice = $this->draft(['icv' => 7]);
        $xpath = $this->xpathFor(app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            privateKey: $this->credentials['privateKey'],
            certificate: $this->credentials['certificate'],
        )['xml']);

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID");

        $this->assertGreaterThan(0, $nodes->length, 'The document carries no ICV.');
        $this->assertSame((string) $invoice->fresh()->icv, trim($nodes->item(0)->textContent));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function typeNameFor(array $overrides): string
    {
        $xpath = $this->xpathFor($this->generate($overrides));

        $node = $xpath->query('//cbc:InvoiceTypeCode')->item(0);

        $this->assertNotNull($node, 'The document carries no InvoiceTypeCode.');

        return $node->getAttribute('name');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function generate(array $overrides): string
    {
        return app(DocumentBuilder::class)->generateComplianceData(
            invoice: $this->draft($overrides),
            organization: $this->organization,
            privateKey: $this->credentials['privateKey'],
            certificate: $this->credentials['certificate'],
        )['xml'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create(array_merge([
            'org_id' => $this->organization->id,
            'invoice_number' => 'INV-'.bin2hex(random_bytes(4)),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ], $overrides)));

        $invoice->load('lines');

        return $invoice;
    }

    private function xpathFor(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        Xml::load($document, $xml);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        return $xpath;
    }
}
