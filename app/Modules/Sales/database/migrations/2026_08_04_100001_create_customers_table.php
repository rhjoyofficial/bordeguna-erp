<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales slice 1: who we sell to.
     *
     * ⚠⚠ R3 of the architecture review: "no customer exists, so Accounts
     * Receivable has nothing to hang on". Building the record with only a name
     * is how a second customer table appears later, so the fields a distributor
     * business needs are here from the first migration even though Phase 1 uses
     * almost none of them.
     *
     * ⚠ `credit_limit` and `payment_terms` are RECORDED AND NOT ENFORCED
     * (limitation L4). C-SLS-6 is unanswered, and the review's recommendation
     * was to warn rather than refuse until Joy asks for it. Storing them now
     * costs nothing and means the answer, whenever it comes, is a rule over data
     * that already exists rather than a migration on a live table.
     *
     * ⛔ Nothing sells yet. This slice adds customers, prices and accounts.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ Ours, not theirs - the mirror image of `supplier_bills`, where
            // the number belongs to the supplier. Nullable because a walk-in
            // buying one jar is not worth a code, and unique per business
            // because a distributor's code is how the sales team refers to them.
            $table->string('code')->nullable();
            $table->string('name');

            // ⚠ A classification, never a branch. See CustomerType.
            $table->string('type')->default('retail');

            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();

            // Informational, exactly as on suppliers ("Net 30", "COD").
            $table->string('payment_terms')->nullable();
            $table->decimal('credit_limit', 15, 4)->nullable();

            // ⚠ The hook the price resolver reads. A plain string rather than a
            // table, because a price group with no rows behind it is a name, and
            // naming it is the whole of what Phase 1 needs. When groups acquire
            // their own settings this becomes a foreign key, which is a
            // migration over a column that already holds the right values.
            $table->string('price_group')->nullable();

            // ⚠⚠ The seeded walk-in row. A system row cannot be deleted, because
            // every counter sale in the business's history points at it.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
