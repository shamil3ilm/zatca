<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The two ZATCA spec identifiers the Saudi builder emits, pinned.
 *
 * The UAE path pins its equivalents — FtaXmlBuilderTest asserts the PINT AE
 * CustomizationID and the Peppol ProfileID by value. The Saudi path, which is
 * older and matters more, asserted neither, so either could have been changed
 * or been wrong from the start without anything failing.
 *
 * ProfileID is no longer a guess. ZATCA's own SDK validator names the rule —
 * BR-KSA-EN16931-01 — and rejected the document until it read reporting:1.0,
 * which is also what all nineteen sample invoices shipped with the SDK carry.
 *
 * CustomizationID is still unconfirmed. No rule fired on it and ZATCA's
 * samples omit the element entirely, so what is pinned below is what we emit
 * rather than what is required. Removing it is a separate question with an
 * answer worth having before anyone touches it.
 */
class XmlProfileTest extends TestCase
{
    /**
     * ZATCA's own string, if it is one. Recorded so a change has to argue with
     * a test rather than pass unnoticed.
     */
    private const CUSTOMIZATION = 'urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod';

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/zatca/profile-test.xml');
        File::delete($this->path);
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    /**
     * BR-KSA-EN16931-01: business process (BT-23) must be "reporting:1.0".
     *
     * For every document type. The builder used to emit "clearance:1.0" for
     * standard invoices, reasoning that a standard invoice is cleared rather
     * than reported — true of the transaction, and not what BT-23 records.
     * ZATCA's validator rejects it by name, and all nineteen sample invoices
     * in the SDK carry reporting:1.0. Clearance is chosen by the endpoint the
     * document goes to.
     */
    public function test_every_invoice_reports(): void
    {
        foreach (['standard', 'simplified'] as $type) {
            $this->assertStringContainsString(
                '<cbc:ProfileID>reporting:1.0</cbc:ProfileID>',
                $this->generate($type),
                "BR-KSA-EN16931-01 rejects a {$type} invoice with any other BT-23."
            );
        }
    }

    public function test_clearance_is_never_a_profile(): void
    {
        $this->assertStringNotContainsString('clearance:1.0', $this->generate('standard'));
    }

    public function test_customization_id_is_pinned(): void
    {
        foreach (['standard', 'simplified'] as $type) {
            $this->assertStringContainsString(
                '<cbc:CustomizationID>'.self::CUSTOMIZATION.'</cbc:CustomizationID>',
                $this->generate($type),
                "The {$type} CustomizationID changed."
            );
        }
    }

    /**
     * BT-121, pinned to what ZATCA's BR-KSA-CL-04 assertion accepts.
     *
     * The allowlist had drifted both ways: three codes the authority does not
     * recognise, and two valid ones it refused. A code list is exactly the
     * kind of thing that is copied from prose once and never checked against
     * the rule file that decides.
     */
    public function test_exemption_codes_match_the_authority(): void
    {
        $codes = (new \ReflectionClass(CreateInvoiceRequest::class))
            ->getConstant('VALID_EXEMPTION_CODES');

        sort($codes);

        $this->assertSame([
            'VATEX-SA-29',
            'VATEX-SA-29-7',
            'VATEX-SA-30',
            'VATEX-SA-32',
            'VATEX-SA-33',
            'VATEX-SA-34-1',
            'VATEX-SA-34-2',
            'VATEX-SA-34-3',
            'VATEX-SA-34-4',
            'VATEX-SA-34-5',
            'VATEX-SA-35',
            'VATEX-SA-36',
            'VATEX-SA-EDU',
            'VATEX-SA-HEA',
            'VATEX-SA-MLTRY',
            'VATEX-SA-OOS',
        ], $codes, 'The exemption allowlist no longer matches BR-KSA-CL-04.');
    }

    public function test_ubl_version_is_pinned(): void
    {
        $this->assertStringContainsString(
            '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>',
            $this->generate('standard')
        );
    }

    /**
     * The checklist this command prints used to be eighteen hardcoded ticks,
     * so it could not report a missing element. It reads the document now,
     * which means it has to be able to say no.
     */
    public function test_checklist_can_report_absence(): void
    {
        $this->artisan('fatoora:validate', ['--type' => 'simplified', '--output' => $this->path])
            ->expectsOutputToContain('not required for B2C')
            ->assertSuccessful();
    }

    public function test_checklist_reads_the_profile(): void
    {
        $this->artisan('fatoora:validate', ['--type' => 'standard', '--output' => $this->path])
            ->expectsOutputToContain('reporting:1.0')
            ->assertSuccessful();
    }

    private function generate(string $type): string
    {
        $this->artisan('fatoora:validate', ['--type' => $type, '--output' => $this->path])
            ->assertSuccessful();

        $this->assertFileExists($this->path);

        return (string) File::get($this->path);
    }
}
