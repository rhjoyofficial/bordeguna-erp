<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.1 of Accounting: the Chart of Accounts.
     *
     * ⚠⚠ THIS TABLE HAS NO business_id, AND THAT IS THE DESIGN, NOT AN OMISSION.
     *
     * C-GL-1 is settled: one shared chart, separate balances per business. A
     * balance is therefore never a column here - it is
     * SUM(amount) WHERE account_id = ? AND business_id = ?, taken from the
     * journal lines, which ARE business-scoped. Consolidation later is that same
     * query with the business filter removed.
     *
     * Had the chart been per-business, consolidating four businesses would mean
     * matching four charts by name, which is why ERPs that start that way never
     * get consolidation right afterwards.
     *
     * ⚠ Being outside BusinessScope is also what makes the chart usable from a
     * queued job or a scheduled command. Every other model in this system fails
     * closed to `1 = 0` without a tenant, which is right for DATA and wrong for
     * CONFIGURATION - three times now this project has shipped, or nearly
     * shipped, code that was correct inside a request and blind outside one.
     *
     * There is a test asserting business_id is absent, so nobody adds it later
     * for convenience.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            // ⚠ The code is the HUMAN label, `id` is the identity. Every foreign
            // key in the ledger points at id, because accountants renumber a
            // chart and consider it a cosmetic act - if journal lines referenced
            // the code, a renumbering would silently re-file history.
            $table->string('code', 20)->unique();
            $table->string('name');

            // asset | liability | equity | income | expense - see AccountType.
            // The normal balance is DERIVED from this, never stored beside it.
            $table->string('type', 20);

            // The chart's groups: "Cash in Hand" under "Current Assets" under
            // "Assets". Self-referencing, and restricted on delete because
            // orphaning half a chart is not a recoverable accident.
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();

            // ⚠ You post to "Cash in Hand", never to "Current Assets". The
            // posting engine refuses a non-postable account, and that refusal is
            // what keeps the group totals meaningful rather than double-counted.
            $table->boolean('is_postable')->default(true);

            // ⚠ A stable machine name for the handful of accounts the software
            // itself must find - stock_on_hand, accounts_payable,
            // opening_balance_equity, rounding_difference. NULLABLE on purpose:
            // a slug on every row is a second numbering scheme nobody maintains.
            $table->string('system_slug', 60)->nullable()->unique();

            // ⚠ Deactivated, never deleted. A journal posted in August must
            // still name its account in December, and an account with history
            // behind it is refused deletion at the model.
            $table->boolean('is_active')->default(true);

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['type', 'code']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
