<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\Services\XmlBuilder;
use App\Support\Xml;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generate a sample ZATCA-compliant invoice XML for validation.
 *
 * Usage:
 *   php artisan fatoora:validate
 *   php artisan fatoora:validate --output=/path/to/invoice.xml
 *
 * Then validate with ZATCA SDK:
 *   fatoora -validate -invoice storage/app/zatca/sample_invoice.xml
 */
class FatooraValidate extends Command
{
    protected $signature = 'fatoora:validate
                            {--output= : Output path for the XML file}
                            {--type=standard : Invoice type (standard or simplified)}';

    protected $description = 'Generate a sample ZATCA-compliant invoice XML for validation testing';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /**
     * The elements worth showing, and where they live in the document.
     *
     * Values are read from the XML rather than restated here, so this names
     * what to look at and nothing about what it should say.
     */
    private const CHECKS = [
        'UBLVersionID' => '/*/cbc:UBLVersionID',
        'CustomizationID' => '/*/cbc:CustomizationID',
        'ProfileID' => '/*/cbc:ProfileID',
        'Invoice ID' => '/*/cbc:ID',
        'UUID' => '/*/cbc:UUID',
        'Issue Date' => '/*/cbc:IssueDate',
        'Issue Time' => '/*/cbc:IssueTime',
        'Invoice Type Code' => '/*/cbc:InvoiceTypeCode',
        'BT-3 Sub-type' => '/*/cbc:InvoiceTypeCode/@name',
        'Currency' => '/*/cbc:DocumentCurrencyCode',
        'ICV' => '//cac:AdditionalDocumentReference[cbc:ID="ICV"]/cbc:UUID',
        'PIH' => '//cac:AdditionalDocumentReference[cbc:ID="PIH"]//cbc:EmbeddedDocumentBinaryObject',
        'Seller VAT' => '//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID',
        'Seller Street' => '//cac:AccountingSupplierParty//cac:PostalAddress/cbc:StreetName',
        'Seller City' => '//cac:AccountingSupplierParty//cac:PostalAddress/cbc:CityName',
        'Buyer VAT' => '//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID',
        'Supply Date' => '//cac:Delivery/cbc:ActualDeliveryDate',
        'Tax Total' => '/*/cac:TaxTotal/cbc:TaxAmount',
        'Payable' => '/*/cac:LegalMonetaryTotal/cbc:PayableAmount',
    ];

    /**
     * Absent from a simplified invoice by design, not by omission.
     */
    private const B2C_OPTIONAL = ['Buyer VAT', 'Supply Date'];

    public function handle(): int
    {
        $this->info('Generating ZATCA-compliant invoice XML...');
        $this->newLine();

        $type = $this->option('type');
        $isStandard = $type === 'standard';

        // ZATCA SDK default PIH for testing (hex hash base64 encoded)
        $defaultPih = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

        // Create sample invoice data matching the updated DTO structure
        $invoiceData = new InvoiceXmlData(
            uuid: $this->generateUuid(),
            invoiceNumber: 'INV-'.date('Ymd').'-001',
            icv: 1,
            issueDate: date('Y-m-d'),
            issueTime: date('H:i:s'),
            invoiceTypeCode: '388', // 388 = Tax Invoice
            invoiceSubtype: $isStandard ? '01' : '02', // 01 = Standard (B2B), 02 = Simplified (B2C)
            currency: 'SAR',
            sellerName: 'Maximum Speed Tech Supply LTD',
            sellerVatNumber: '399999999900003', // Test VAT number (15 digits starting/ending with 3)
            sellerAddress: new AddressData(
                street: 'King Fahd Road',
                buildingNumber: '1234',
                plotIdentification: '5678',
                district: 'Al Olaya',
                city: 'Riyadh',
                postalCode: '12345',
                countrySubentity: 'Riyadh Region',
                countryCode: 'SA',
            ),
            buyerName: 'Customer Company',
            subtotal: 1500.00,
            taxAmount: 225.00,
            total: 1725.00,
            lines: [
                [
                    'description' => 'Consulting Services',
                    'quantity' => 10,
                    'unitPrice' => 100.00,
                    'taxRate' => 15.0,
                    'taxCategory' => 'S',
                    'lineTotal' => 1000.00,
                    'taxAmount' => 150.00,
                ],
                [
                    'description' => 'Software License',
                    'quantity' => 1,
                    'unitPrice' => 500.00,
                    'taxRate' => 15.0,
                    'taxCategory' => 'S',
                    'lineTotal' => 500.00,
                    'taxAmount' => 75.00,
                ],
            ],
            supplyDate: $isStandard ? date('Y-m-d') : null, // Supply date required for standard invoices
            sellerCrNumber: '1010010000', // 10-digit CRN
            buyerVatNumber: $isStandard ? '399999999800003' : null, // B2B needs VAT (15 digits, starts/ends with 3)
            buyerAddress: $isStandard ? new AddressData(
                street: 'Prince Sultan Road',
                buildingNumber: '5678',
                district: 'Al Malaz',
                city: 'Riyadh',
                postalCode: '54321',
                countryCode: 'SA',
            ) : null,
            previousInvoiceHash: $defaultPih, // ZATCA SDK default PIH
        );

        // Build XML
        $builder = new XmlBuilder;
        $xml = $builder->build($invoiceData);

        // Output path
        $outputPath = $this->option('output')
            ?? storage_path('app/zatca/sample_invoice.xml');

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Save XML
        File::put($outputPath, $xml);

        $this->info("✓ Invoice XML generated: {$outputPath}");
        $this->newLine();

        // Display validation checklist
        $this->displayValidationChecklist($xml, $invoiceData, $isStandard);

        // Display next steps
        $this->newLine();
        $sdk = getenv('ZATCA_SDK_PATH') ?: null;

        if ($sdk === null) {
            $this->info('To check this against the ZATCA validator:');
            $this->line('  Set ZATCA_SDK_PATH to the unpacked SDK (the directory holding Apps/ and Data/),');
            $this->line('  then run: fatoora -validate -invoice '.$outputPath);
            $this->line('  It also turns on the conformance suite, which skips without it.');
        } else {
            $this->info('Check it against the ZATCA validator:');
            $this->line('  '.rtrim(str_replace('\\', '/', $sdk), '/').'/Apps/fatoora -validate -invoice '.$outputPath);
            $this->line('  Expected: GLOBALVALIDATIONRESULT = PASSED');
        }

        return Command::SUCCESS;
    }

    /**
     * Report what the generated document actually contains.
     *
     * Every row is read from the XML that was just written, never restated
     * from what the builder is expected to emit. A checklist that cannot fail
     * is worse than none, because it is read as confirmation: a row that
     * hardcodes its own tick reports agreement with itself, and one phrased as
     * "Base64 encoded" or "Complete with all fields" asserts nothing testable.
     *
     * What it cannot tell you is whether those values are the ones ZATCA
     * wants. That needs the schema and the authority, not this command.
     */
    private function displayValidationChecklist(string $xml, InvoiceXmlData $data, bool $isStandard): void
    {
        $document = Xml::load(new \DOMDocument, $xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        $rows = [];

        foreach (self::CHECKS as $label => $path) {
            $found = $xpath->evaluate("string({$path})");
            $found = is_string($found) ? trim($found) : '';

            if ($found !== '') {
                $rows[] = [$label, '✓', $found];

                continue;
            }

            // A simplified invoice is B2C: there is no buyer to identify and
            // no delivery to date, so their absence is the document being
            // right rather than incomplete.
            $optional = ! $isStandard && in_array($label, self::B2C_OPTIONAL, true);

            $rows[] = [$label, $optional ? '–' : '✗', $optional ? 'not required for B2C' : 'missing'];
        }

        // Counted rather than located: a line the builder dropped is the
        // failure this catches, and the count is the thing to compare.
        $lines = $xpath->evaluate('count(//cac:InvoiceLine)');
        $expected = count($data->lines);

        $rows[] = [
            'Invoice Lines',
            (int) $lines === $expected ? '✓' : '✗',
            sprintf('%d of %d', $lines, $expected),
        ];

        $this->info('What the generated document contains:');
        $this->table(['Element', 'Present', 'Value'], $rows);

        $this->newLine();
        $this->warn('Presence is not conformance. Only the schema and ZATCA can tell you that.');
    }

    private function generateUuid(): string
    {
        // Generate UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
