<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Commission agreements (Joy's Q18 answer, 24 Oct 2026, binding): "No
 * Dealer commission for now ... no commission plan come from authority, also
 * no salesperson commission for now, its their duty, and a commission system
 * needs cause someone can offer i can sell these amount or i will sell but per
 * product you give me these amount, we can agree about that."
 *
 * So nobody earns anything automatically. A commission is a DEAL made case by
 * case with one person (an employee, a customer or shop, or an outside agent
 * kept as a supplier), and only sales that deal names count.
 *
 *   commission_agreements        who, valid from/to, the deal type: Tk per
 *                                unit, % of the sale value, or a target deal
 *   commission_agreement_items   which products (none = every product), a
 *                                product or a whole category
 *   commission_agreement_tiers   a target deal's steps: sell X, get Tk Y
 *   commission_settlements       "everything earned through date D": the
 *                                difference from the last live settlement is
 *                                posted (owed, paid now, or the next payroll)
 *   commission_payments          paying a settlement that was left owed
 *   sales_orders / sales_invoices.agent_type + agent_id
 *                                the "Sold by / agent" picker; an invoice
 *                                made from an order takes the order's agent
 *                                when it has none of its own (read time)
 *
 * ⚠ Additive only: no existing row changes, and sales_targets stays as it was
 * (shown read only under the agreements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->date('agreed_on');
            // employee | customer | supplier: the person's own ledger party.
            $table->string('party_type', 16);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            // per_unit | percent | target
            $table->string('deal_type', 16);
            // Tk per unit, or the percent; null on a target deal.
            $table->decimal('rate', 15, 4)->nullable();
            // A target deal counts units or Tk.
            $table->string('target_basis', 8)->nullable();
            // Which sales count: the ones this person is credited for (Sold by),
            // and for a customer, also what they buy themselves.
            $table->boolean('counts_credited_sales')->default(true);
            $table->boolean('counts_own_purchases')->default(false);
            $table->text('note')->nullable();
            // pending (above the limit, waiting) | active | rejected | withdrawn
            $table->string('status', 16)->default('active');
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'party_type']);
        });

        Schema::create('commission_agreement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_agreement_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_agreement_id')->constrained()->cascadeOnDelete();
            // Units or Tk to reach, and the Tk the person gets on reaching it.
            $table->decimal('threshold', 15, 4);
            $table->decimal('reward', 15, 4);
            $table->timestamps();
        });

        Schema::create('commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignId('commission_agreement_id')->constrained()->restrictOnDelete();
            $table->date('settled_on');
            // The sales window this settlement closes: from the day after the
            // last live settlement (or the agreement's start) through this day.
            $table->date('from_date');
            $table->date('through_date');
            $table->decimal('sold_quantity', 15, 4)->default(0);
            $table->decimal('sold_value', 15, 4)->default(0);
            // Everything earned from the start through through_date; amount is
            // that less what live settlements before it already took.
            $table->decimal('earned_to_date', 15, 4)->default(0);
            $table->decimal('amount', 15, 4);
            // payable | paid_now | payroll
            $table->string('method', 16);
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained('payroll_adjustments')->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            // pending | posted | rejected | withdrawn (reversed rides on Reversible)
            $table->string('status', 16)->default('pending');
            $table->text('note')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->string('reversal_reason', 1100)->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            // ⚠ Named: the default name is 65 characters, past MySQL's 64.
            $table->index(['commission_agreement_id', 'through_date'], 'commission_settlements_agreement_through_index');
        });

        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignId('commission_settlement_id')->constrained()->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 4);
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->string('reversal_reason', 1100)->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
        });

        foreach (['sales_orders', 'sales_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                // employee | customer | supplier, and that party's id.
                $table->string('agent_type', 16)->nullable();
                $table->unsignedBigInteger('agent_id')->nullable();
                $table->index(['agent_type', 'agent_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_orders', 'sales_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['agent_type', 'agent_id']);
                $table->dropColumn(['agent_type', 'agent_id']);
            });
        }

        Schema::dropIfExists('commission_payments');
        Schema::dropIfExists('commission_settlements');
        Schema::dropIfExists('commission_agreement_tiers');
        Schema::dropIfExists('commission_agreement_items');
        Schema::dropIfExists('commission_agreements');
    }
};
