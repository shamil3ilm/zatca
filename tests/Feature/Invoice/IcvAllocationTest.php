<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ICV is the ZATCA invoice counter. It must be strictly sequential per
 * taxpayer, and the previous-invoice-hash chain is built on it, so a
 * duplicate or a gap is a compliance failure rather than a cosmetic one.
 *
 * The counter is taken at issuance, not at creation — Submitter::generate()
 * allocates it under the organization-row lock that also reads the
 * predecessor, so the two are decided together and a draft holds no position
 * in the chain. These tests drive the allocator the way issuance does.
 * ChainForkTest covers what that ordering buys.
 */
class IcvAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_icv_is_one(): void
    {
        $invoice = $this->issue($this->makeOrganization()->id, 'INV-1');

        $this->assertSame(1, $invoice->icv);
    }

    /**
     * Creation alone takes no counter. A draft is not in the chain.
     */
    public function test_draft_has_no_icv(): void
    {
        $invoice = $this->makeInvoice($this->makeOrganization()->id, 'DRAFT-1');

        $this->assertNull($invoice->icv);
    }

    public function test_icv_increments(): void
    {
        $organizationId = $this->makeOrganization()->id;

        $icvs = collect(range(1, 5))
            ->map(fn (int $n) => $this->issue($organizationId, "INV-{$n}")->icv)
            ->all();

        $this->assertSame([1, 2, 3, 4, 5], $icvs);
    }

    /**
     * The counter is per taxpayer, so one organization's invoices must not
     * advance another's.
     */
    public function test_counter_is_per_org(): void
    {
        $first = $this->makeOrganization('First Co')->id;
        $second = $this->makeOrganization('Second Co')->id;

        $this->issue($first, 'A-1');
        $this->issue($first, 'A-2');
        $freshOrgInvoice = $this->issue($second, 'B-1');

        $this->assertSame(1, $freshOrgInvoice->icv);
        $this->assertSame(3, $this->issue($first, 'A-3')->icv);
    }

    public function test_explicit_icv_kept(): void
    {
        $invoice = $this->makeInvoice($this->makeOrganization()->id, 'INV-9', ['icv' => 42]);

        $this->assertSame(42, $invoice->icv);
    }

    /**
     * The database constraint is the backstop when two writers race past the
     * application lock. Without it a collision would silently break the chain
     * instead of failing the insert.
     */
    public function test_duplicate_icv_rejected(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite'
            && ! $this->uniqueIndexExists('invoices', 'invoices_org_icv_unique')) {
            $this->markTestSkipped('Unique index not present on this connection.');
        }

        $organizationId = $this->makeOrganization()->id;
        $this->issue($organizationId, 'INV-1');

        $this->expectException(QueryException::class);

        $this->makeInvoice($organizationId, 'INV-2', ['icv' => 1]);
    }

    private function uniqueIndexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i) => $i['name'] === $index);
    }

    private function makeOrganization(string $name = 'Acme'): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA']);
    }

    /**
     * Create and number a document, the way issuance does.
     */
    private function issue(string $organizationId, string $number): Invoice
    {
        return $this->makeInvoice($organizationId, $number, [
            'icv' => Invoice::generateNextIcv($organizationId),
        ]);
    }

    private function makeInvoice(string $organizationId, string $number, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'org_id' => $organizationId,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ], $overrides));
    }
}
