<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A claim you can still be building, and one you have handed over.
 *
 * ⚠⚠ `submitted_at` NULL IS THE DRAFT. Until now a claim existed only at the
 * moment it was sent for approval, which is why a market trip could not be
 * assembled over an afternoon. A draft is a claim the employee still owns: they
 * add a line each time money leaves their pocket, and nothing reaches an
 * approver until they say so.
 *
 * ⚠ A RETURNED claim is a draft again - the approval request carries the
 * Returned status and `submitted_at` goes back to null - and it KEEPS ITS
 * NUMBER. EC-00009 sent back and fixed is still EC-00009, because the employee
 * has already written that number on something and the decision history hangs
 * off the same request.
 *
 * ⚠ A category flagged "receipt not expected" is the accountant's answer to
 * rickshaw fare and tea: there is no piece of paper to attach and there never
 * was. Every other category still refuses a line without a file. Default TRUE,
 * so a category nobody has thought about behaves the way the module always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('claim_number');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->boolean('receipt_required')->default(true)->after('is_exception');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('receipt_required');
        });
    }
};
