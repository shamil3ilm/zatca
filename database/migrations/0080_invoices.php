<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->uuid('profile_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('invoice_number', 255);
            $table->string('type', 255);
            $table->string('document_type', 255)->nullable();
            $table->string('status', 255);
            $table->date('issue_date');
            $table->date('supply_date')->nullable();
            $table->string('currency', 3)->default('SAR');
            // BR-KSA-CU-01: a foreign-currency invoice reports VAT in SAR, so
            // the rate it was converted at is part of the record. Null for SAR.
            // Six decimal places because thinly-traded pairs need them.
            $table->decimal('exchange_rate', 16, 6)->nullable();
            $table->string('buyer_name', 255);
            $table->string('buyer_vat_number', 255)->nullable();
            // BT-46 and BT-46-1: who the buyer is when they have no VAT
            // number, and which register the identifier comes from.
            // BR-KSA-49 makes a national ID mandatory on a healthcare or
            // education supply billed to a citizen, so without these two
            // columns the most common zero-rated supplies in the Kingdom
            // could not be filed at all.
            $table->string('buyer_id', 50)->nullable();
            $table->string('buyer_id_scheme', 10)->nullable();
            $table->text('buyer_address')->nullable();
            $table->string('payment_means_code', 10)->nullable();
            $table->string('billing_ref', 255)->nullable();
            $table->string('adjustment_reason', 255)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->string('hash', 255)->nullable();
            $table->text('qr_code')->nullable();
            // What we signed and sent.
            $table->longText('signed_xml')->nullable();
            // What ZATCA sent back, for a standard invoice that was cleared.
            // The authority stamps the document it clears, and that stamped
            // document is the legal invoice — not the one we submitted.
            $table->longText('cleared_xml')->nullable();
            $table->string('cert_id', 64)->nullable();
            $table->string('rule_version', 20)->nullable();
            $table->string('schema_version', 20)->nullable();
            $table->timestamp('determined_at')->nullable();
            $table->string('signature_algorithm', 50)->nullable();
            $table->string('hash_algorithm', 20)->nullable();
            $table->unsignedBigInteger('icv')->nullable();
            $table->json('zatca_response')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_third_party')->default(false)->comment('ZATCA BT-3 bit 3: invoiced on behalf of a third party');
            $table->boolean('is_nominal')->default(false)->comment('ZATCA BT-3 bit 4: nominal / self-consumption');
            $table->boolean('is_export')->default(false)->comment('ZATCA BT-3 bit 5: export invoice');
            $table->boolean('is_summary')->default(false)->comment('ZATCA BT-3 bit 6: summary invoice');
            $table->boolean('is_self_billed')->default(false)->comment('ZATCA BT-3 bit 7: buyer-initiated self-billed invoice');
            $table->string('erp_reference_id', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['branch_id'], 'invoices_branch_id_index');
            $table->index(['profile_id'], 'invoices_compliance_profile_id_index');
            $table->index(['erp_reference_id'], 'invoices_erp_reference_id_index');
            $table->index(['invoice_number'], 'invoices_invoice_number_index');
            $table->index(['issue_date'], 'invoices_issue_date_idx');
            $table->index(['org_id', 'created_at'], 'invoices_org_created_idx');
            $table->unique(['org_id', 'icv'], 'invoices_org_icv_unique');
            $table->index(['org_id', 'status'], 'invoices_organization_id_status_index');
            $table->index(['cert_id'], 'invoices_signing_certificate_id_index');
            $table->index(['type'], 'invoices_type_idx');
            $table->foreign('branch_id', 'invoices_branch_id_foreign')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('profile_id', 'invoices_compliance_profile_id_foreign')->references('id')->on('compliance_profiles')->nullOnDelete();
            $table->foreign('org_id', 'invoices_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('invoice_id');
            $table->string('description', 255);
            $table->string('class_code', 50)->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_code', 10)->default('PCE');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(15.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->char('tax_category', 1)->default('S');
            $table->string('exempt_code', 50)->nullable();
            $table->string('exempt_reason', 255)->nullable();
            $table->decimal('line_total', 12, 2)->default(0.00);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['invoice_id'], 'invoice_lines_invoice_id_foreign');
            $table->foreign('invoice_id', 'invoice_lines_invoice_id_foreign')->references('id')->on('invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
