<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Invoice aggregate root.
 *
 * Core business entity for ZATCA compliance.
 * Immutable after status changes from Draft.
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'org_id',
        // The EGS unit that issued this invoice. A branch signs with its own
        // ZATCA certificate, so this decides which credentials are used.
        'branch_id',
        'profile_id',
        'invoice_number',
        'type',
        'document_type',
        'status',
        'issue_date',
        'supply_date',
        'currency',
        'exchange_rate',
        'buyer_name',
        'buyer_vat_number',
        'buyer_id',
        'buyer_id_scheme',
        'buyer_address',
        'payment_means_code',
        'billing_ref',
        'adjustment_reason',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'hash',
        'qr_code',
        'signed_xml',
        'cleared_xml',
        'icv',
        'zatca_response',
        'notes',
        'erp_reference_id',
        // ZATCA BT-3 invoice sub-type flags (bits 3-7)
        'is_third_party',
        'is_nominal',
        'is_export',
        'is_summary',
        'is_self_billed',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'document_type' => DocumentType::class,
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'supply_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'icv' => 'integer',
            'zatca_response' => 'array',
            'buyer_address' => 'array',
            'is_third_party' => 'boolean',
            'is_nominal' => 'boolean',
            'is_export' => 'boolean',
            'is_summary' => 'boolean',
            'is_self_billed' => 'boolean',
        ];
    }

    /**
     * Immutable fields after invoice is finalized (status != draft).
     * These fields cannot be changed once invoice leaves draft status.
     */
    public const IMMUTABLE_FIELDS = [
        'org_id',
        // Which EGS unit issued it is bound into the signature and the
        // certificate that produced it, so it cannot move after issue.
        'branch_id',
        'invoice_number',
        'type',
        'document_type',
        'issue_date',
        'supply_date',
        'currency',
        // The rate the VAT was reported in SAR at. Changing it after issue
        // restates the tax without restating the document.
        'exchange_rate',
        'buyer_name',
        'buyer_vat_number',
        'buyer_id',
        'buyer_id_scheme',
        'buyer_address',
        'payment_means_code',
        'billing_ref',
        'adjustment_reason',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'icv',
    ];

    /**
     * Fields that can be updated after finalization (ZATCA response data).
     */
    public const MUTABLE_AFTER_FINALIZED = [
        'status',
        'hash',
        'qr_code',
        'signed_xml',
        // Clearance happens after issue by definition, so the document the
        // authority returns arrives on an already-finalized invoice.
        'cleared_xml',
        'zatca_response',
        'notes',
        'erp_reference_id',
        'updated_at',
    ];

    /**
     * Boot method for ICV generation and immutability enforcement.
     *
     * COMPLIANCE: Invoices are immutable after leaving Draft status.
     * This is a core ZATCA requirement - issued invoices cannot be modified.
     */
    protected static function boot(): void
    {
        parent::boot();

        // ICV is not allocated here.
        //
        // It used to be, and that is what let the chain fork. The counter was
        // taken when the row was created and the hash written later, at
        // issuance, so the two moments could interleave. The PIH accessor
        // skips anything unhashed, so a document issued while a lower-numbered
        // draft was still unsigned chained straight past it — and when that
        // draft was issued in turn, both named the same predecessor. Neither
        // unique index catches that: they constrain (org_id, icv) and
        // invoice_id, and two rows at different positions may share a
        // previous_hash.
        //
        // Allocating at issuance instead makes the chain contiguous by
        // construction. A draft carries no counter, so an abandoned one costs
        // nothing and cannot block the tenant — which matters because there is
        // no cancelled status to release a number with.
        //
        // Submitter::generate() allocates, under the same organization-row
        // lock that reads the PIH, so the pair is decided together.
        // ChainForkTest holds this.

        // Prevent deletion of finalized invoices
        static::deleting(function (Invoice $invoice) {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new \RuntimeException(
                    'Finalized invoices cannot be deleted. '.
                    'This is a ZATCA compliance requirement. '.
                    'Use credit/debit notes for corrections.'
                );
            }
        });

        // Enforce immutability on finalized invoices
        static::updating(function (Invoice $invoice) {
            $originalStatus = $invoice->getOriginal('status');

            // If invoice was/is in draft, allow all changes
            if ($originalStatus === InvoiceStatus::Draft || $originalStatus === null) {
                return;
            }

            // Invoice is finalized - check for immutable field changes
            $changedFields = array_keys($invoice->getDirty());
            $immutableChanges = array_intersect($changedFields, self::IMMUTABLE_FIELDS);

            if (! empty($immutableChanges)) {
                throw new \RuntimeException(
                    'Finalized invoice fields cannot be modified. '.
                    'This is a ZATCA compliance requirement. '.
                    'Attempted to change: '.implode(', ', $immutableChanges).'. '.
                    'Use credit/debit notes for corrections.'
                );
            }
        });
    }

    /**
     * Allocate the next ICV for an organization.
     *
     * ZATCA requires the invoice counter to be strictly sequential per
     * taxpayer, and the PIH chain is built on it, so two invoices must never
     * receive the same value.
     *
     * The lock is taken on the organization row rather than on the invoice
     * rows. Locking `SELECT MAX(icv) ... FOR UPDATE` looks equivalent but has
     * a hole: for an organization's first invoice there are no invoice rows to
     * lock, so two concurrent requests both read no rows and both allocate 1.
     * The organization row always exists, so it serialises every case.
     *
     * Callers must create the invoice inside a transaction. Laravel turns this
     * nested transaction into a savepoint, which keeps the lock held until the
     * outer commit — that is, until after the INSERT. Without an outer
     * transaction the lock is released here and the window reopens.
     * `invoices_org_icv_unique` is the backstop either way: a collision fails
     * the insert rather than corrupting the chain.
     */
    public static function generateNextIcv(string $organizationId): int
    {
        return DB::transaction(function () use ($organizationId) {
            DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first();

            // Outside the tenant scope: the organization is given explicitly,
            // and the counter must reflect every invoice that organization
            // holds regardless of who is asking. Left scoped, a request with
            // no tenant context reads zero rows, restarts the counter at 1,
            // and the unique index rejects the insert.
            $highest = static::withoutTenantScope(
                fn () => static::query()->where('org_id', $organizationId)->max('icv')
            );

            return ((int) $highest) + 1;
        });
    }

    /**
     * Organization that owns this invoice.
     */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The EGS unit that issued this invoice, if any.
     *
     * Submitter reads this to choose signing credentials: a branch holds its
     * own ZATCA certificate, and ZATCA treats each as a separate device. Null
     * means the organization's own credentials are used.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Compliance profile used for this invoice's jurisdiction.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ComplianceProfile::class);
    }

    /**
     * Invoice line items.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Check if invoice can be edited.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Check if invoice requires ZATCA clearance.
     */
    public function requiresClearance(): bool
    {
        return $this->type->requiresClearance();
    }

    /**
     * The document of record: what to archive, and what to hand anyone who
     * asks for this invoice.
     *
     * For a standard invoice, ZATCA clears it and returns its own stamped
     * copy. That copy is the legal invoice; the one we submitted is a
     * proposal the authority has since signed off. For a simplified invoice
     * there is no clearance — it is reported after the fact — so what we
     * signed is what stands.
     *
     * Both are kept. signed_xml is what we sent, which is the evidence of what
     * we asked for; cleared_xml is what came back, which is the invoice.
     */
    public function getLegalXmlAttribute(): ?string
    {
        return $this->cleared_xml ?? $this->signed_xml;
    }

    /**
     * Check if invoice is B2B (Standard - requires clearance).
     */
    public function isB2B(): bool
    {
        return $this->type === InvoiceType::Standard;
    }

    /**
     * Check if invoice is B2C (Simplified - reporting only).
     */
    public function isB2C(): bool
    {
        return $this->type === InvoiceType::Simplified;
    }

    /**
     * Get invoice submissions.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(InvoiceSubmission::class);
    }

    /**
     * Get the latest submission.
     */
    public function latestSubmission()
    {
        return $this->hasOne(InvoiceSubmission::class)->latestOfMany();
    }

    /**
     * Check if invoice has been successfully submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->submissions()
            ->whereIn('state', ['cleared', 'reported', 'warning'])
            ->exists();
    }

    /**
     * Check if invoice has pending submission.
     */
    public function hasPendingSubmission(): bool
    {
        return $this->submissions()
            ->whereIn('state', ['queued', 'pending_submission', 'submitted'])
            ->exists();
    }

    /**
     * The hash of this tenant's preceding invoice — ZATCA's PIH.
     *
     * Every document carries the previous one's hash, and the authority checks
     * the chain. There is no previous_invoice_hash column, so this accessor is
     * what makes the attribute answer: Eloquent returns null for an attribute
     * it does not have rather than failing, and XmlBuilder turns a null into
     * the genesis PIH — a silent claim to be first in the chain.
     *
     * Defined on the model rather than at each call site, so that the queue,
     * the offline path and the direct path all derive the chain the same way.
     *
     * Ordered by ICV rather than created_at, so the chain follows ZATCA's
     * sequential counter instead of wall-clock time, which is not deterministic
     * under concurrent inserts. Restricted to invoices that have been hashed —
     * an unsigned draft is not part of the chain. Null is correct for a
     * tenant's first invoice.
     *
     * The tenant scope is lifted because org_id is already pinned to this
     * invoice's own tenant. Leaving it on would make the answer depend on
     * whether a request context happens to be present, and the way it fails —
     * scoping to null, finding nothing, returning the genesis PIH — is silent
     * and indistinguishable from a genuinely first invoice.
     */
    public function getPreviousInvoiceHashAttribute(): ?string
    {
        // A draft holds no counter, so it holds no position, so it has no
        // predecessor to name. Asking anyway used to reach the query builder
        // as `where('icv', '<', null)`, which throws rather than returning
        // nothing — and every path that reads this attribute speculatively
        // died on it once the counter moved to issuance.
        if ($this->icv === null) {
            return null;
        }

        return static::withoutTenantScope(fn (): ?string => static::query()
            ->where('org_id', $this->org_id)
            ->where('icv', '<', $this->icv)
            ->whereNotNull('hash')
            ->orderByDesc('icv')
            ->value('hash')
        );
    }
}
