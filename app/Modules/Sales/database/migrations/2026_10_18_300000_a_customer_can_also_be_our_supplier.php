<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ A supplier can also be our customer (Joy, 18 Oct 2026, decision D-F).
 *
 * `customers.supplier_id` links one customer to one supplier of the SAME
 * business: the same firm on both sides of the books. Unique where set, so a
 * supplier stands behind one customer only and the other way round.
 *
 * ⚠ WHY A COLUMN AND NOT A COUNTERPARTY (18 Oct 2026). A counterparty is the
 * group-wide lender register (no business_id): linking every trading partner
 * there would put dealers on the "Lenders & borrowers" list and could pair a
 * customer of LaLaDia with a supplier of Bor de Guna, which no set-off may
 * ever cross. The link is per business, beside the two rows it joins. Where a
 * counterparty already names either side, the link must agree with it
 * (Actions\LinkSupplierCustomer), so there is still one identity.
 *
 * ⚠ Linking never merges ledgers: AP 2110 stays per supplier, AR 1140 per
 * customer, and only an approved set-off moves money between them.
 *
 * Additive and nullable, no foreign key (the house rule for columns added to
 * an existing table on SQLite); MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('employee_id');
            $table->unique('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['supplier_id']);
            $table->dropColumn('supplier_id');
        });
    }
};
