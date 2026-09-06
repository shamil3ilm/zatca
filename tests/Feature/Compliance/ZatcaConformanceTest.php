<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;

/**
 * Documents this platform produces, judged by ZATCA rather than by us.
 *
 * Every other test in this suite asserts that the code does what the code
 * intends. That is worth having and it is not conformance: it cannot tell you
 * that BT-23 must read "reporting:1.0" on a standard invoice, because nothing
 * in this repository knew. ZATCA's validator knows — it rejected the document
 * as BR-KSA-EN16931-01 — and this runs it.
 *
 * Four validators: the UBL 2.1 schema, the CEN EN16931 rules, ZATCA's own
 * Schematron, and the PIH chain check.
 *
 * Set ZATCA_SDK_PATH to run these. They skip without it, because the SDK is a
 * licensed download that cannot live in the repository.
 */
class ZatcaConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            // BR-KSA-08 wants a seller identification with a scheme ID, and a
            // commercial registration number is the first of the six it
            // accepts. Without one the document still passes, with an advisory.
            'cr_number' => '1010101010',
            'compliance_profile' => ['zatca_onboarded' => true],
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
     * The schema is the floor. A document that fails it is not an invoice, and
     * no amount of correct business logic above it matters.
     */
    public function test_standard_invoice_matches_the_schema(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame(
            'PASSED',
            $result['stages']['XSD'] ?? 'ABSENT',
            "UBL 2.1 schema rejected the document:\n".implode("\n", $result['errors'])
        );
    }

    public function test_standard_invoice_passes_zatca_rules(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame(
            'PASSED',
            $result['stages']['KSA'] ?? 'ABSENT',
            "ZATCA rules rejected the document:\n".implode("\n", $result['errors'])
        );
    }

    public function test_standard_invoice_passes_en16931(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame(
            'PASSED',
            $result['stages']['EN'] ?? 'ABSENT',
            "EN 16931 rejected the document:\n".implode("\n", $result['errors'])
        );
    }

    public function test_simplified_invoice_passes_zatca_rules(): void
    {
        $result = $this->validate($this->signed('simplified'));

        $this->assertSame(
            'PASSED',
            $result['stages']['KSA'] ?? 'ABSENT',
            "ZATCA rules rejected the simplified document:\n".implode("\n", $result['errors'])
        );
    }

    /**
     * The six documents ZATCA's compliance check requires.
     *
     * Onboarding is not granted on a tax invoice alone: a taxpayer must submit
     * a standard and a simplified invoice, and a credit and a debit note of
     * each, before the production CSID is issued. Two of the six were checked
     * here; the notes had never been put in front of the authority at all.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        foreach (['standard', 'simplified'] as $type) {
            foreach (['invoice', 'credit_note', 'debit_note'] as $document) {
                yield "{$type} {$document}" => [$type, $document];
            }
        }
    }

    #[DataProvider('documents')]
    public function test_every_compliance_document_validates(string $type, string $document): void
    {
        $result = $this->validate($this->signed($type, $document));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            "ZATCA rejected the {$type} {$document}."
        );

        $this->assertSame([], $result['warnings'], "an advisory fired on the {$type} {$document}.");
    }

    /**
     * Lines that are not standard-rated.
     *
     * ZATCA ships a sample for each of these, and each carries its own rules:
     * an exempt or zero-rated line must name the reason it is not taxed, and
     * the totals still have to add up with a zero in them. The tax path had
     * already been wrong twice here — a line's amount-with-VAT declared as
     * zero, and exempt lines filed as standard-rated — so these are the shapes
     * most worth putting in front of the authority.
     *
     * @return iterable<string, array{string, string, float}>
     */
    public static function taxCategories(): iterable
    {
        // Exported goods rather than healthcare: BR-KSA-49 requires a buyer
        // national ID (BT-46 with schemeID NAT) alongside VATEX-SA-HEA or
        // VATEX-SA-EDU, and the invoice has nowhere to put one. That is a real
        // limit on which zero-rated supplies this platform can bill for, and
        // it is a schema question rather than something to paper over here.
        yield 'zero rated' => ['Z', 'VATEX-SA-34-3', 0.0];

        // The code ZATCA's own exempt sample carries.
        yield 'exempt' => ['E', 'VATEX-SA-29-7', 0.0];

        yield 'out of scope' => ['O', 'VATEX-SA-OOS', 0.0];
    }

    /**
     * A healthcare supply billed to a citizen, which is the shape BR-KSA-49
     * governs and the platform could not produce.
     *
     * The rule makes BT-46 mandatory with schemeID NAT whenever the exemption
     * is VATEX-SA-HEA or VATEX-SA-EDU. Private healthcare and private
     * education to citizens are among the most common zero-rated supplies in
     * the Kingdom, so "cannot be filed" was not a corner.
     */
    public function test_healthcare_to_a_citizen_validates(): void
    {
        $result = $this->validate($this->signed('standard', 'invoice', [], [
            'category' => 'Z',
            'code' => 'VATEX-SA-HEA',
            'rate' => 0.0,
            'buyer_id' => '2345678901',
            'buyer_id_scheme' => 'NAT',
        ]));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            'ZATCA rejected a healthcare supply billed to a citizen.'
        );
    }

    /**
     * A foreign-currency invoice, which ZATCA reads in two currencies at once.
     *
     * BR-KSA-CU-01: VAT is reported in SAR whatever the invoice is billed in,
     * so the document carries the total twice — once in its own currency and
     * once converted. The exchange_rate column that makes the conversion
     * possible was added recently, and InvoiceValidator had been demanding a
     * field nothing accepted or stored, so every non-SAR invoice was refused
     * at compliance with an error naming something the caller could not send.
     * Nothing had put the result in front of the authority.
     */
    public function test_foreign_currency_validates(): void
    {
        $result = $this->validate($this->signed('standard', 'invoice', [], null, [
            'currency' => 'USD',
            'exchange_rate' => '3.750000',
        ]));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            'ZATCA rejected a foreign-currency invoice.'
        );

        $this->assertSame([], $result['warnings'], 'an advisory fired on a foreign-currency invoice.');
    }

    /**
     * A discount on the whole invoice, which has to reduce the tax it is
     * meant to reduce.
     *
     * The apportionment across tax categories was reworked recently and the
     * line amount-with-VAT was wrong before that. Both are arithmetic ZATCA
     * checks first, so this is the shape most worth re-asking about.
     */
    public function test_document_level_discount_validates(): void
    {
        $result = $this->validate($this->signed('standard', 'invoice', [], null, [
            'discount_amount' => '100.00',
        ]));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            'ZATCA rejected an invoice carrying a document-level discount.'
        );

        $this->assertSame([], $result['warnings'], 'an advisory fired on a discounted invoice.');
    }

    /**
     * A standard-rated and a zero-rated line on one invoice, with a discount
     * across both.
     *
     * This is the only shape that exercises apportionAllowance() for what it
     * was written to do. With a single category the split is the whole
     * discount and any arithmetic passes; with two it has to reduce each
     * category's base in proportion and then recompute the tax on what is
     * left — including the zero-rated half, where the correct answer is still
     * zero. Every other case here would look identical if that function
     * returned nonsense.
     */
    public function test_mixed_categories_with_a_discount_validate(): void
    {
        $result = $this->validate($this->mixed(discount: 200.0));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            'ZATCA rejected an invoice mixing tax categories under a discount.'
        );

        $this->assertSame([], $result['warnings'], 'an advisory fired on a mixed-category invoice.');
    }

    #[DataProvider('taxCategories')]
    public function test_untaxed_lines_validate(string $category, string $code, float $rate): void
    {
        $result = $this->validate($this->signed('standard', 'invoice', [], [
            'category' => $category,
            'code' => $code,
            'rate' => $rate,
        ]));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            "ZATCA rejected a {$category} line."
        );

        $this->assertSame([], $result['warnings'], "an advisory fired on a {$category} line.");
    }

    /**
     * BT-3's five sub-type bits, and the flag string ZATCA's own samples carry.
     *
     * The platform models all five. Nothing had ever checked the bit positions
     * against the authority, and FatooraConfig once declared a second set of
     * constants that disagreed with the builder — third party as 0100001
     * against the 0110000 it actually emits. Those constants were removed as
     * dead; these samples say the builder was the one that had it right.
     *
     * @return iterable<string, array{string, array<string, bool>, string}>
     */
    public static function subtypes(): iterable
    {
        yield 'plain standard' => ['standard', [], '0100000'];
        yield 'third party' => ['standard', ['is_third_party' => true], '0110000'];
        yield 'nominal' => ['simplified', ['is_nominal' => true], '0201000'];
        yield 'export' => ['standard', ['is_export' => true], '0100100'];
        yield 'summary' => ['standard', ['is_summary' => true], '0100010'];
        yield 'self billed' => ['standard', ['is_self_billed' => true], '0100001'];
    }

    /**
     * @param  array<string, bool>  $flags
     */
    #[DataProvider('subtypes')]
    public function test_subtype_flags_match_the_authority(string $type, array $flags, string $expected): void
    {
        $xml = $this->signed($type, 'invoice', $flags);

        $this->assertStringContainsString(
            '<cbc:InvoiceTypeCode name="'.$expected.'">',
            $xml,
            "BT-3 does not carry {$expected}, which is what ZATCA's sample for this shape does."
        );

        $result = $this->validate($xml);

        $this->assertSame([], $this->businessRules($result['errors']), 'ZATCA rejected the document.');
        $this->assertSame([], $result['warnings'], 'an advisory fired.');
    }

    /**
     * The rule violations a document can fix, separated from the ones only a
     * real CSID can.
     *
     * Four of the SDK's checks — the certificate, the QR that embeds it, the
     * signature over both, and the PIH chain it compares against its own
     * configured file — cannot pass with the self-signed key these tests
     * generate. They are not findings about the document, and asserting on
     * them would mean this suite could only ever run for someone holding a
     * production certificate.
     *
     * What is left is the business rules, which are about what the invoice
     * says. Those must be clean.
     *
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function businessRules(array $errors): array
    {
        return array_values(array_filter(
            $errors,
            static fn (string $error): bool => str_starts_with($error, 'BR-')
        ));
    }

    /**
     * Advisories are not failures, which is exactly why they need a test.
     *
     * BR-KSA-51 sat here reporting that every line's amount-with-VAT was zero,
     * and the document cleared anyway. A rule ZATCA is willing to overlook is
     * still a rule about what the invoice says.
     */
    public function test_no_advisories(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame([], $result['warnings'], 'a ZATCA advisory rule fired');
    }

    /**
     * The harness itself, checked against a document known to be good.
     *
     * If ZATCA's own sample fails here, the finding is about this test — a
     * stale config path, a missing schema, the wrong version — and not about
     * the platform. Without this, a broken harness reads as a broken invoice.
     */
    public function test_the_authority_own_sample_passes(): void
    {
        $sdk = $this->requireSdk();

        $sample = $sdk.'/Data/Samples/Standard/Invoice/Standard_Invoice.xml';

        if (! is_file($sample)) {
            $this->markTestSkipped('The SDK carries no standard invoice sample.');
        }

        $result = $this->validate((string) file_get_contents($sample));

        $this->assertSame(
            'PASSED',
            $result['global'],
            "The harness is wrong, not the platform:\n".implode("\n", $result['errors'])
        );
    }

    /**
     * One invoice carrying a standard-rated line and a zero-rated one.
     */
    private function mixed(float $discount): string
    {
        $standardNet = 1000.0;
        $zeroNet = 500.0;

        // The discount reduces each category's base in proportion, so the tax
        // is what is left of the standard half after its share comes off.
        $standardShare = round($discount * $standardNet / ($standardNet + $zeroNet), 2);
        $vat = round(($standardNet - $standardShare) * 0.15, 2);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'MIXED-1',
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Beta Industries',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ],
            'discount_amount' => number_format($discount, 2, '.', ''),
            'subtotal' => number_format($standardNet + $zeroNet, 2, '.', ''),
            'tax_amount' => number_format($vat, 2, '.', ''),
            'total' => number_format($standardNet + $zeroNet - $discount + $vat, 2, '.', ''),
        ]));

        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1.000',
            'unit_price' => number_format($standardNet, 2, '.', ''),
            'tax_rate' => '15.00',
            'tax_category' => 'S',
            'tax_amount' => number_format(round($standardNet * 0.15, 2), 2, '.', ''),
            'line_total' => number_format($standardNet * 1.15, 2, '.', ''),
        ]);

        $invoice->lines()->create([
            'description' => 'Exported goods',
            'quantity' => '1.000',
            'unit_price' => number_format($zeroNet, 2, '.', ''),
            'tax_rate' => '0.00',
            'tax_category' => 'Z',
            'exempt_code' => 'VATEX-SA-34-3',
            'exempt_reason' => 'Exported goods',
            'tax_amount' => '0.00',
            'line_total' => number_format($zeroNet, 2, '.', ''),
        ]);

        $result = app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        $this->assertNotEmpty($result['signed_xml'], 'the invoice was not signed');

        return $result['signed_xml'];
    }

    /**
     * @param  array<string, bool>  $subtype  BT-3 sub-type flags
     * @param  array{category: string, code: string, rate: float}|null  $tax
     */
    private function signed(
        string $type,
        string $document = 'invoice',
        array $subtype = [],
        ?array $tax = null,
        array $overrides = [],
    ): string {
        $isNote = $document !== 'invoice';

        $rate = $tax['rate'] ?? 15.0;
        $net = 1000.0;
        $discount = (float) ($overrides['discount_amount'] ?? 0);
        $vat = round(($net - $discount) * $rate / 100, 2);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => strtoupper($type.'-'.$document).'-'.substr(md5(serialize($subtype)), 0, 6),
            'type' => $type,
            'document_type' => $document,
            'is_third_party' => $subtype['is_third_party'] ?? false,
            'is_nominal' => $subtype['is_nominal'] ?? false,
            'is_export' => $subtype['is_export'] ?? false,
            'is_summary' => $subtype['is_summary'] ?? false,
            'is_self_billed' => $subtype['is_self_billed'] ?? false,
            // A credit or debit note corrects an earlier invoice, and BR-KSA-56
            // wants to know which one.
            'billing_ref' => $isNote ? 'INV-ORIGINAL-1' : null,
            'adjustment_reason' => $isNote ? 'Correction of quantity' : null,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Beta Industries',
            // A buyer identified by a national ID has no VAT number — BT-46 is
            // the alternative to BT-48, not a companion to it.
            'buyer_vat_number' => isset($tax['buyer_id']) || $type !== 'standard'
                ? null
                : '399999999800003',
            'buyer_id' => $tax['buyer_id'] ?? null,
            'buyer_id_scheme' => $tax['buyer_id_scheme'] ?? null,
            // BR-KSA-10 and the BR-KSA-F-06 family read the buyer address.
            // A standard invoice without one still validates, and reports an
            // advisory per missing field — which is noise this test would
            // otherwise have to carry as expected.
            'buyer_address' => $type === 'standard' ? [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ] : null,
            'subtotal' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($vat, 2, '.', ''),
            'total' => number_format($net - $discount + $vat, 2, '.', ''),
            ...$overrides,
        ]));

        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1.000',
            'unit_price' => number_format($net, 2, '.', ''),
            'tax_rate' => number_format($rate, 2, '.', ''),
            'tax_category' => $tax['category'] ?? 'S',
            'exempt_code' => $tax['code'] ?? null,
            // BR-KSA-49 wants the reason in words beside the code.
            'exempt_reason' => isset($tax['code']) ? 'Exempt under the cited article' : null,
            'tax_amount' => number_format($vat, 2, '.', ''),
            'line_total' => number_format($net + $vat, 2, '.', ''),
        ]);

        $result = app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        $this->assertNotEmpty($result['signed_xml'], 'the invoice was not signed');

        return $result['signed_xml'];
    }
}
