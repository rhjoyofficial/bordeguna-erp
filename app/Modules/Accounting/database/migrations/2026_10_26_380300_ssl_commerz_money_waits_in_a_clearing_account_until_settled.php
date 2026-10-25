<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): "Paid online (SSL) goes to an account named
 * SSL Commerz Clearing (money pending settlement). When SSL pays out, the real
 * amount goes to the actual bank and SSL's charge is deducted: handle it
 * properly (gross, fee, net), with a report of how much we got and how much
 * SSL took."
 *
 *   money_accounts.system_key   'ssl_clearing' marks the one clearing account
 *                               of a business (type gateway, head 1146
 *                               Receivable from Payment Gateways)
 *   ssl_settlements             one SSL payout: gross out of clearing, the fee
 *                               to 6475 (sales.gateway_fees), the net into the
 *                               bank; Reversible
 *
 * The clearing account is created here for every business that sells (has a
 * sales.revenue mapping) on a chart with 1146; any other business gets it the
 * first time it is needed (App\Modules\Accounting\Support\SslClearing, the
 * same idempotent rule). Nothing is backfilled: orders already marked paid
 * into a bank stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('money_accounts', 'system_key')) {
            Schema::table('money_accounts', function (Blueprint $table) {
                $table->string('system_key', 40)->nullable()->after('type');
                $table->index(['business_id', 'system_key']);
            });
        }

        if (! Schema::hasTable('ssl_settlements')) {
            Schema::create('ssl_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('number', 40);
                $table->date('settled_on');
                $table->foreignId('clearing_money_account_id')->constrained('money_accounts')->restrictOnDelete();
                $table->foreignId('bank_money_account_id')->constrained('money_accounts')->restrictOnDelete();
                $table->decimal('gross', 14, 2);
                $table->decimal('fee', 14, 2)->default(0);
                $table->decimal('net', 14, 2);
                $table->foreignId('fee_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
                $table->string('reference', 120)->nullable();
                $table->string('note', 1000)->nullable();
                $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                // Reversible (Foundation\Concerns\Reversible).
                $table->date('reversed_on')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reversal_reason', 1000)->nullable();
                $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'number']);
                $table->index(['business_id', 'settled_on']);
            });
        }

        $head = DB::table('accounts')->where('system_slug', 'gateway_receivable')->value('id')
            ?? DB::table('accounts')->where('code', '1146')->value('id');

        if ($head === null) {
            return;
        }

        $sellers = DB::table('account_mappings')->where('key', 'sales.revenue')->distinct()->pluck('business_id');

        foreach ($sellers as $businessId) {
            if (DB::table('money_accounts')->where('business_id', $businessId)->where('system_key', 'ssl_clearing')->exists()) {
                continue;
            }

            DB::table('money_accounts')->insert([
                'business_id' => $businessId,
                'name' => 'SSL Commerz Clearing',
                'type' => 'gateway',
                'system_key' => 'ssl_clearing',
                'account_id' => $head,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_settlements');

        DB::table('money_accounts')->where('system_key', 'ssl_clearing')
            ->whereNotExists(fn ($q) => $q->from('journal_lines')->whereColumn('journal_lines.money_account_id', 'money_accounts.id'))
            ->delete();

        if (Schema::hasColumn('money_accounts', 'system_key')) {
            Schema::table('money_accounts', function (Blueprint $table) {
                $table->dropIndex(['business_id', 'system_key']);
                $table->dropColumn('system_key');
            });
        }
    }
};
