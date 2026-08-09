<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a prospect or customer ASKED FOR, as a dated event.
 *
 * ⚠⚠ EACH REQUEST IS ITS OWN ROW AND IS NEVER OVERWRITTEN. "Wants 2kg" in June
 * and "wants 10kg for Eid" in August are two different business facts, and the
 * demand history is precisely what tells you whether to stock mango again. An
 * earlier draft of this table used one mutable row per product; that would have
 * destroyed the first statement every time somebody made a second one.
 *
 * ⚠ The REQUEST columns are immutable, enforced on the model. Only the OUTCOME
 * columns may be appended later, so linking a sale never rewrites what the
 * customer originally said.
 *
 * ⛔ This is not the sales ledger. No price, no stock, no product name is
 * copied here - the master data is referenced.
 *
 * CRM-PHASE-B-ARCHITECTURE.md, C-CRMB-22..30.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_product_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Lead or Customer, whitelisted in the application. Interest does
            // not stop mattering at conversion - it is what the first sales
            // conversation is about.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // ── What they asked for. IMMUTABLE once written. ────────────────
            //
            // ⚠⚠ EXACTLY ONE of these three. Both category_id and brand_id
            // already exist on `products`, so all three are real queryable
            // dimensions: "interested in honey" and "interested in LaLaDia" are
            // genuine statements made before a SKU is chosen. Enforced in the
            // action, because SQLite has no usable CHECK across drivers here.
            //
            // ⚠ restrictOnDelete throughout: a product somebody asked for must
            // not vanish silently because the catalogue was tidied.
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();

            // ⚠ Nullable. "Wants mango" is a real statement; a quantity of zero
            // would be a different and false one.
            $table->decimal('requested_quantity', 15, 4)->nullable();

            // ⚠⚠ THE UNIT THEY SAID, NOT THE PRODUCT'S STOCK UNIT. A customer
            // says "2 packs" for something stocked in kilograms. Converting at
            // capture would invent a number nobody stated. No inventory path
            // reads this column.
            $table->foreignId('requested_unit_id')->nullable()
                ->constrained('units_of_measure')->restrictOnDelete();

            $table->text('note')->nullable();

            // ⚠ Separate from created_at, for the same reason occurred_at is on
            // an activity: a request made on Tuesday and typed on Friday was
            // made on Tuesday.
            $table->timestamp('requested_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Outcome. Appended later, never by rewriting the request. ────
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_note')->nullable();

            // ⚠⚠ A POINTER TO EVIDENCE, NOT A CLAIM. Whether somebody bought a
            // thing is answered by sales_invoice_lines; this only says which
            // invoice satisfied the request. Nothing sets it yet.
            $table->foreignId('fulfilled_by_invoice_id')->nullable()
                ->constrained('sales_invoices')->nullOnDelete();

            $table->timestamps();

            // ⚠ Short explicit names: the generated ones for a table with this
            // prefix run close to MySQL's 64-character identifier limit.
            $table->index(['business_id', 'subject_type', 'subject_id', 'status'], 'crm_interest_subject_idx');
            $table->index(['business_id', 'product_id', 'status'], 'crm_interest_product_idx');
            $table->index(['business_id', 'status', 'requested_at'], 'crm_interest_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_product_interests');
    }
};
