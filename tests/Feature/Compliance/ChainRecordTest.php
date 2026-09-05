<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Compliance\Fatoora\Models\ChainState;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What each issued document was built with, kept beside the document.
 *
 * Invoice::getPreviousInvoiceHashAttribute() derives the chain by re-walking
 * the invoices table, and a derivation agrees with itself no matter what has
 * happened to the rows underneath it. hash_chain_history is the independent
 * record VerifyHashChain compares against, so it is only worth having if
 * something writes it — a comparison against an empty table reports every
 * invoice as missing its entry, which reads as a broken chain rather than an
 * unwritten one.
 */
class ChainRecordTest extends TestCase
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
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $this->credentials['privateKey'], 'pcsid' => $this->credentials['certificate']]
        );
    }

    public function test_issuing_records_a_chain_entry(): void
    {
        $invoice = $this->issue('INV-1');

        $entry = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::where('invoice_id', $invoice->id)->first()
        );

        $this->assertNotNull($entry, 'the issued document recorded no chain entry');
        $this->assertSame($invoice->fresh()->hash, $entry->invoice_hash);
        $this->assertSame((int) $invoice->fresh()->icv, (int) $entry->icv);
    }

    /**
     * The first document has no predecessor, and says so with the genesis PIH
     * rather than with a null — the same value XmlBuilder emits into it.
     */
    public function test_first_entry_carries_the_genesis_hash(): void
    {
        $invoice = $this->issue('INV-1');

        $entry = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::where('invoice_id', $invoice->id)->first()
        );

        $this->assertSame(base64_encode(str_repeat("\0", 32)), $entry->previous_hash);
    }

    /**
     * Each entry names the document before it. This is the assertion the
     * authority makes about the chain, recorded at the moment it was true.
     */
    public function test_entries_link_to_the_preceding_document(): void
    {
        $first = $this->issue('INV-1');
        $second = $this->issue('INV-2');

        $entry = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::where('invoice_id', $second->id)->first()
        );

        $this->assertSame($first->fresh()->hash, $entry->previous_hash);
    }

    public function test_chain_head_follows_the_latest_document(): void
    {
        $this->issue('INV-1');
        $second = $this->issue('INV-2');

        $state = ChainState::withoutTenantScope(
            fn () => ChainState::query()->find($this->organization->id)
        );

        $this->assertNotNull($state, 'no chain head was recorded');
        $this->assertSame($second->id, $state->last_invoice_id);
        $this->assertSame((int) $second->fresh()->icv, (int) $state->last_icv);
        $this->assertSame($second->fresh()->hash, $state->last_hash);
    }

    /**
     * Every document signed by the same certificate carries the same
     * identifier, which is what makes a certificate change visible.
     */
    public function test_entries_name_the_signing_certificate(): void
    {
        $first = $this->issue('INV-1');
        $second = $this->issue('INV-2');

        $entries = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::whereIn('invoice_id', [$first->id, $second->id])->pluck('certificate_id')
        );

        $this->assertCount(2, $entries);
        $this->assertCount(1, array_unique($entries->all()));
        $this->assertSame(hash('sha256', $this->credentials['certificate']), $entries->first());
    }

    /**
     * The recorded chain is what VerifyHashChain reads. An entry that no longer
     * matches its document is the case the command exists to find, and it
     * cannot find it if the table is empty.
     */
    public function test_a_tampered_entry_is_visible(): void
    {
        $this->issue('INV-1');
        $second = $this->issue('INV-2');

        ChainEntry::withoutTenantScope(
            fn () => ChainEntry::where('invoice_id', $second->id)
                ->update(['previous_hash' => base64_encode(str_repeat('x', 32))])
        );

        $recorded = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::where('invoice_id', $second->id)->value('previous_hash')
        );

        $this->assertNotSame(
            $second->fresh()->previous_invoice_hash,
            $recorded,
            'a changed entry still matched the derived chain, so nothing could detect it'
        );
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
            'buyer_vat_number' => '300000000000003',
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
