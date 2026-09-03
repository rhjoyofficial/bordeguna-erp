<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The accountant's expense-claim structure (3 Sep 2026): the employee
// picks a CATEGORY; the category carries the COA mapping; approval posts
// Dr expense / Cr Employee Reimbursements Payable; settlement clears the
// payable. Categories are data the accountant controls.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->unsignedBigInteger('account_id');
            $table->boolean('is_exception')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::table('expense_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('expense_category_id')->nullable()->after('purpose');
            $table->unsignedBigInteger('approval_journal_id')->nullable()->after('expense_voucher_id');
        });

        // The payable the approval credits. The chart is CENTRAL; idempotent
        // by system_slug so re-running or a pre-seeded chart cannot double it.
        if (! DB::table('accounts')->where('system_slug', 'employee_reimbursements_payable')->exists()) {
            $parent = DB::table('accounts')->where('code', '2100')->value('id');
            DB::table('accounts')->insert([
                'code' => '2145',
                'name' => 'Employee Reimbursements Payable',
                'type' => 'liability',
                'parent_id' => $parent,
                'is_postable' => 1,
                'is_active' => 1,
                'system_slug' => 'employee_reimbursements_payable',
                'note' => 'What the business owes staff for approved expense claims not yet paid back.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ⚠⚠ The expense heads the spec's own table names (employee-expense.txt
        // §4). Without them Courier, Travel, Packaging and Software all land in
        // Miscellaneous, and §5's whole reason for the structure - asking what
        // one head cost in a month - cannot be answered. Idempotent by code:
        // 6225 already exists in production, added by hand for hosting.
        $operating = DB::table('accounts')->where('code', '6000')->value('id');

        // ⚠ 6225 Software and Hosting is NOT here: production already has it
        // (added by hand for the hosting bill). It joins the chart SEEDER, for
        // fresh installs only.
        foreach ([
            ['6232', 'Travel and TA'],
            ['6235', 'Courier and Delivery'],
            ['6255', 'Packaging Materials'],
        ] as [$code, $name]) {
            if (DB::table('accounts')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => 'expense',
                'parent_id' => $operating,
                'is_postable' => 1,
                'is_active' => 1,
                'system_slug' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn(['expense_category_id', 'approval_journal_id']);
        });
        Schema::dropIfExists('expense_categories');
    }
};
