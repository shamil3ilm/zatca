<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\InvoiceValidator;
use Tests\TestCase;

/**
 * What validateXml() actually checked, and saying so.
 *
 * It was documented as validating against the ZATCA XSD and did not: the
 * schemaValidate call was commented out, so it returned only well-formedness
 * errors. For any well-formed document that is an empty array, which reads as
 * "this document satisfies the specification" when nothing of the sort was
 * established.
 *
 * The schema is a licensed download and cannot be committed, so the method now
 * reports which of the two checks it performed. A caller can tell an empty
 * error list from a document that was actually validated.
 *
 * These use a small local schema. The ZATCA set changes the configured path,
 * not the mechanism.
 */
class SchemaValidationTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../Fixtures/xsd/note.xsd';

    private InvoiceValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(InvoiceValidator::class);
    }

    public function test_malformed_xml_is_reported(): void
    {
        $result = $this->validator->validateXml('<note><to>a</to>');

        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($result['schema_checked']);
    }

    /**
     * The point of the whole change: without a schema installed, a well-formed
     * document must not come back looking validated.
     */
    public function test_without_a_schema_nothing_is_claimed(): void
    {
        config(['fatoora.validation.schema_path' => null]);

        $result = $this->validator->validateXml('<note><to>a</to><body>b</body></note>');

        $this->assertSame([], $result['errors']);
        $this->assertFalse($result['schema_checked'], 'A document was reported as schema-checked with no schema installed.');
    }

    public function test_a_conforming_document_passes_the_schema(): void
    {
        config(['fatoora.validation.schema_path' => self::SCHEMA]);

        $result = $this->validator->validateXml('<note><to>a</to><body>b</body></note>');

        $this->assertTrue($result['schema_checked']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * Well formed and still wrong: the document parses, and omits an element
     * the schema requires. This is the case a well-formedness check cannot see.
     */
    public function test_schema_violations_are_reported(): void
    {
        config(['fatoora.validation.schema_path' => self::SCHEMA]);

        $result = $this->validator->validateXml('<note><to>a</to></note>');

        $this->assertTrue($result['schema_checked']);
        $this->assertNotEmpty($result['errors'], 'A document violating the schema was accepted.');
    }

    /**
     * A configured path is a statement of intent, not a schema. If it points at
     * nothing - a typo, or an SDK that was never unpacked - the document is
     * still only well-formedness checked, and must not be reported otherwise.
     */
    public function test_an_uninstalled_schema_is_not_used(): void
    {
        config(['fatoora.validation.schema_path' => 'resources/zatca/not-installed.xsd']);

        $result = $this->validator->validateXml('<note><to>a</to><body>b</body></note>');

        $this->assertFalse($result['schema_checked']);
        $this->assertSame([], $result['errors']);
    }
}
