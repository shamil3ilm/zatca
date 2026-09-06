<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesSecrets;
use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;

/**
 * Generate CSR and Private Key for ZATCA onboarding.
 *
 * This command generates a ZATCA-compliant CSR without requiring the SDK.
 * The generated CSR can be used with the fatoora:onboard command.
 *
 * Usage:
 *   php artisan fatoora:generate-csr
 *   php artisan fatoora:generate-csr --vat=399999999900003 --org="My Company"
 */
class FatooraGenerateCsr extends Command
{
    use WritesSecrets;

    protected $signature = 'fatoora:generate-csr
                            {--vat=399999999900003 : 15-digit VAT number (starts/ends with 3)}
                            {--org=Maximum Speed Tech Supply LTD : Organization name}
                            {--unit=IT Department : Organization unit/branch}
                            {--cn=EGS1-TEST-001 : Common name (EGS serial number)}
                            {--sn=1-Solution_2-1.0_3-ed22f1d8-e6a2-1118-9b58-d9a8195e990f : Solution serial number}
                            {--location=Riyadh : Branch location}
                            {--industry=Information Technology : Business category}
                            {--standard : Support standard invoices (B2B)}
                            {--simplified : Support simplified invoices (B2C)}
                            {--output= : Output directory (default: storage/app/zatca)}';

    protected $description = 'Generate ZATCA-compliant CSR and private key using PHP OpenSSL';

    /**
     * Where ZATCA's Java SDK is unpacked, if it is.
     *
     * This was one developer's Downloads folder, hardcoded — so on any other
     * machine, and on that one after the folder moved, file_exists() on the jar
     * returned false and CSR generation fell through to phpseclib without
     * saying why. The SDK is a licensed download that cannot live in the
     * repository, so it is named by environment or not at all.
     *
     * Set ZATCA_SDK_PATH to the directory holding Apps/ and Data/. The same
     * variable drives the conformance tests in tests/Fixtures/ZatcaSdk.php.
     */
    private function sdkRoot(): ?string
    {
        $path = getenv('ZATCA_SDK_PATH') ?: null;

        return $path === null ? null : rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The SDK's executables, which is where the jar and fatoora.bat live.
     */
    private function sdkApps(): ?string
    {
        $root = $this->sdkRoot();

        return $root === null ? null : $root.'/Apps';
    }

    public function handle(): int
    {
        $this->info('Generating ZATCA-compliant CSR and Private Key...');
        $this->newLine();

        // Determine invoice types - default to both if neither specified
        $invoiceTypesStandard = $this->option('standard');
        $invoiceTypesSimplified = $this->option('simplified');
        if (! $invoiceTypesStandard && ! $invoiceTypesSimplified) {
            $invoiceTypesStandard = true;
            $invoiceTypesSimplified = true;
        }

        // Build CSR data
        $csrData = new CsrData(
            organizationName: $this->option('org'),
            organizationUnit: $this->option('unit'),
            commonName: $this->option('cn'),
            vatNumber: $this->option('vat'),
            serialNumber: str_replace('_', '|', $this->option('sn')),
            location: $this->option('location'),
            industry: $this->option('industry'),
            invoiceTypesStandard: $invoiceTypesStandard,
            invoiceTypesSimplified: $invoiceTypesSimplified,
        );

        // Validate VAT number
        $vatNumber = $csrData->vatNumber;
        if (strlen($vatNumber) !== 15 || ! preg_match('/^3\d{13}3$/', $vatNumber)) {
            $this->error('VAT number must be 15 digits starting and ending with 3');
            $this->line('Example: 399999999900003');

            return Command::FAILURE;
        }

        // Display configuration
        $this->info('CSR Configuration:');
        $this->table(['Field', 'Value'], [
            ['Organization', $csrData->organizationName],
            ['Unit', $csrData->organizationUnit],
            ['Common Name', $csrData->commonName],
            ['VAT Number', $csrData->vatNumber],
            ['Location', $csrData->location],
            ['Industry', $csrData->industry],
            ['Invoice Types', $this->getInvoiceTypesDescription($invoiceTypesStandard, $invoiceTypesSimplified)],
        ]);
        $this->newLine();

        try {
            // Check if ZATCA SDK is available
            $apps = $this->sdkApps();
            $sdkJar = $apps === null ? null : $apps.'/zatca-einvoicing-sdk-238-R3.4.8.jar';

            if ($sdkJar !== null && file_exists($sdkJar)) {
                $this->info('Using ZATCA SDK for CSR generation (recommended)...');
                $result = $this->generateCsrWithSdk($csrData);
            } else {
                $this->warn('ZATCA SDK not found, falling back to phpseclib...');
                $this->line($apps === null
                    ? 'Set ZATCA_SDK_PATH to the unpacked SDK to use it instead.'
                    : "No SDK jar under {$apps}.");
                $result = $this->generateCsrWithPhpseclib($csrData);
            }

            $outputDir = $this->secretDir($this->option('output'));

            // The CSR carries the public key and is not secret.
            $csrPath = $outputDir.'/taxpayer.csr';
            File::put($csrPath, $result['csr']);
            $this->info("CSR saved to: {$csrPath}");

            // The key that will sign this taxpayer's invoices is.
            $keyPath = $outputDir.'/taxpayer.key';
            $this->putSecret($keyPath, $result['privateKey']);
            $this->info("Private key saved to: {$keyPath}");

            $this->newLine();
            $this->info('CSR and Private Key generated successfully!');
            $this->newLine();

            // Display next steps
            $this->info('Next Steps:');
            $this->line('1. Request CCSID with the generated CSR and key:');
            $this->line("   php artisan fatoora:onboard --step=ccsid --otp=<your-otp> --target=sandbox --csr={$csrPath} --key={$keyPath}");
            $this->newLine();
            $this->line('2. Run compliance check (invoices will be signed):');
            $this->line('   php artisan fatoora:onboard --step=compliance --target=sandbox');
            $this->newLine();

            // Show CSR content (first few lines)
            $this->info('CSR Preview:');
            $csrLines = explode("\n", $result['csr']);
            foreach (array_slice($csrLines, 0, 5) as $line) {
                $this->line($line);
            }
            $this->line('...');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to generate CSR: '.$e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting:');
            $this->line('1. Ensure OpenSSL extension is enabled in PHP');
            $this->line('2. Check that openssl.cnf path is configured in php.ini');
            $this->line('3. On Windows, you may need to set OPENSSL_CONF environment variable');

            return Command::FAILURE;
        }
    }

    private function getInvoiceTypesDescription(bool $standard, bool $simplified): string
    {
        $types = [];
        if ($standard) {
            $types[] = 'Standard (B2B)';
        }
        if ($simplified) {
            $types[] = 'Simplified (B2C)';
        }

        return implode(', ', $types);
    }

    /**
     * Generate CSR using the official ZATCA SDK.
     * This is the recommended approach as it produces CSRs that are guaranteed to be accepted.
     */
    private function generateCsrWithSdk(CsrData $csrData): array
    {
        $outputDir = $this->secretDir();

        // Create CSR config file for SDK
        $invoiceType = $csrData->getInvoiceTypeCode();
        $configPath = $outputDir.'/csr-config.properties';
        $configContent = <<<EOT
csr.common.name=TST-886431145-{$csrData->vatNumber}
csr.serial.number={$csrData->serialNumber}
csr.organization.identifier={$csrData->vatNumber}
csr.organization.unit.name={$csrData->organizationUnit}
csr.organization.name={$csrData->organizationName}
csr.country.name=SA
csr.invoice.type={$invoiceType}
csr.location.address={$csrData->location}
csr.industry.business.category={$csrData->industry}
EOT;
        file_put_contents($configPath, $configContent);

        $this->info('Created CSR config: '.$configPath);

        // Set up SDK environment
        $sdkConfigPath = $this->sdkRoot().'/Configuration/config.json';
        $sdkJar = $this->sdkApps().'/zatca-einvoicing-sdk-238-R3.4.8.jar';

        // Ensure SDK config exists
        if (! file_exists($sdkConfigPath)) {
            $this->createSdkConfig($sdkConfigPath);
        }

        // Run SDK to generate CSR
        $csrOutput = $outputDir.'/taxpayer-sdk.csr';
        $keyOutput = $outputDir.'/taxpayer-sdk.key';

        $cmd = sprintf(
            'java -Djdk.module.illegalAccess=deny -Dfile.encoding=UTF-8 -jar "%s" --globalVersion 238-R3.4.8 -csr -csrConfig "%s" -generatedCsr "%s" -privateKey "%s" -sim 2>&1',
            $sdkJar,
            $configPath,
            basename($csrOutput),
            basename($keyOutput)
        );

        // Change to output directory and run
        $cwd = getcwd();
        chdir($outputDir);
        putenv('SDK_CONFIG='.$sdkConfigPath);

        $this->line('Running ZATCA SDK...');
        exec($cmd, $output, $returnCode);
        chdir($cwd);

        if ($returnCode !== 0) {
            throw new \RuntimeException('SDK CSR generation failed: '.implode("\n", $output));
        }

        $this->info('✓ CSR generated by ZATCA SDK');

        // SDK outputs:
        // - CSR: base64(PEM content) → decode to get PEM
        // - Key: base64(DER content) → needs PEM wrapping
        $csrBase64 = trim(file_get_contents($csrOutput));
        $keyBase64 = trim(file_get_contents($keyOutput));

        // CSR: decode base64 to get PEM
        $csrPem = base64_decode($csrBase64);

        // Key: base64 content is the DER, wrap with PEM headers
        // The SDK key is already base64-encoded DER, so wrap it directly
        $keyPem = "-----BEGIN EC PRIVATE KEY-----\n".
            chunk_split($keyBase64, 64, "\n").
            '-----END EC PRIVATE KEY-----';

        // Save PEM files
        $csrPath = $outputDir.'/taxpayer.csr';
        $keyPath = $outputDir.'/taxpayer.key';
        file_put_contents($csrPath, $csrPem);
        $this->putSecret($keyPath, $keyPem);

        $this->line("  serialNumber: {$csrData->serialNumber}");
        $this->line("  organizationIdentifier: VATSA-{$csrData->vatNumber}");

        return [
            'csr' => $csrPem,
            'privateKey' => $keyPem,
        ];
    }

    /**
     * Create SDK configuration file if it doesn't exist.
     */
    private function createSdkConfig(string $configPath): void
    {
        // One dirname, not two. Data/ sits beside Apps/ inside the SDK root,
        // so climbing twice landed on the wrapper directory above it and every
        // path written here pointed at a Data/ that does not exist — which the
        // SDK reports as a NullPointerException about resource paths, not as a
        // missing file.
        $sdkRoot = $this->sdkRoot();
        $config = [
            'xsdPath' => $sdkRoot.'/Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd',
            'enSchematron' => $sdkRoot.'/Data/Rules/schematrons/CEN-EN16931-UBL.xsl',
            'zatcaSchematron' => $sdkRoot.'/Data/Rules/schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl',
            'certPath' => $sdkRoot.'/Data/Certificates/cert.pem',
            'privateKeyPath' => $sdkRoot.'/Data/Certificates/ec-secp256k1-priv-key.pem',
            'pihPath' => $sdkRoot.'/Data/PIH/pih.txt',
            'inputPath' => $sdkRoot.'/Data/Input',
            'usagePathFile' => dirname($configPath).'/usage.txt',
        ];

        // Convert to forward slashes for cross-platform compatibility
        foreach ($config as $key => $value) {
            $config[$key] = str_replace('\\', '/', $value);
        }

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Generate CSR using phpseclib for ZATCA compliance.
     * This method properly includes serialNumber and organizationIdentifier in subject DN
     * with UTF8String encoding that supports pipe characters (|).
     * Note: This is a fallback - the SDK method is recommended for production use.
     */
    private function generateCsrWithPhpseclib(CsrData $csrData): array
    {
        // Generate EC private key with secp256k1 curve (ZATCA requirement)
        $privateKey = EC::createKey('secp256k1');

        $this->info('✓ EC private key generated (secp256k1)');

        // Organization identifier in ZATCA format: VATSA-{VAT number}
        $orgIdentifier = 'VATSA-'.$csrData->vatNumber;

        // Create X509 CSR
        $x509 = new X509;
        $x509->setPrivateKey($privateKey);

        // Set Distinguished Name with ZATCA-required fields
        // phpseclib handles UTF8String encoding properly for pipe characters
        $x509->setDN([
            'rdnSequence' => [
                [['type' => 'id-at-countryName', 'value' => ['printableString' => 'SA']]],
                [['type' => 'id-at-organizationName', 'value' => ['utf8String' => $csrData->organizationName]]],
                [['type' => 'id-at-organizationalUnitName', 'value' => ['utf8String' => $csrData->organizationUnit]]],
                [['type' => 'id-at-commonName', 'value' => ['utf8String' => $csrData->commonName]]],
                [['type' => 'id-at-serialNumber', 'value' => ['utf8String' => $csrData->serialNumber]]],
                [['type' => '2.5.4.97', 'value' => ['utf8String' => $orgIdentifier]]],
            ],
        ]);

        // Generate CSR with ECDSA signature
        // Note: Extensions are not included as phpseclib CSR doesn't support them easily
        // For full ZATCA compliance with extensions, use the SDK method
        $csr = $x509->signCSR();
        $csrPem = $x509->saveCSR($csr);
        $privateKeyPem = $privateKey->toString('PKCS8');

        $this->info('✓ CSR generated (without extensions - use SDK for full compliance)');
        $this->line("  serialNumber: {$csrData->serialNumber}");
        $this->line("  organizationIdentifier: {$orgIdentifier}");

        $outputDir = $this->secretDir();
        file_put_contents($outputDir.'/taxpayer.csr', $csrPem);
        $this->putSecret($outputDir.'/taxpayer.key', $privateKeyPem);

        return [
            'csr' => $csrPem,
            'privateKey' => $privateKeyPem,
        ];
    }
}
