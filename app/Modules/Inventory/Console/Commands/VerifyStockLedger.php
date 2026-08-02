<?php

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Support\StockLedger;
use Illuminate\Console\Command;

/**
 * Verifies the whole auditability chain: movements → batch → warehouse →
 * product, for every business.
 *
 * ⚠⚠ Runs COLD. A console command carries no BusinessContext and every model
 * involved is business-scoped, so without entering each tenant `BusinessScope`
 * compiles `1 = 0` and this reports "all consistent" while examining nothing -
 * the worst possible failure for an integrity check. Third time this project has
 * met that trap, so it has its own test.
 */
class VerifyStockLedger extends Command
{
    protected $signature = 'inventory:verify-ledger
                            {--rebuild : Rewrite every batch quantity from its ledger balance}';

    protected $description = 'Verify that stock quantities agree with the movement ledger at every level';

    public function handle(): int
    {
        if ($this->option('rebuild')) {
            return $this->rebuild();
        }

        $problems = StockLedger::verifyAllBusinesses();

        if ($problems === []) {
            $this->info('Stock ledger is consistent: every batch, warehouse and product total is explained by movements.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' consistency problem(s) found:');

        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }

        // ⚠ C-LED-1: reports and exits non-zero, and deliberately does NOT
        // self-heal. A silent rebuild would erase the evidence of whatever
        // caused the drift, and the cause is worth more than the symptom.
        $this->newLine();
        $this->warn('Not repaired. Investigate the cause first, then run with --rebuild to bring the cache back in line.');

        return self::FAILURE;
    }

    private function rebuild(): int
    {
        $total = 0;

        foreach (Business::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            $corrected = StockLedger::rebuildAll();
            $total += $corrected;

            $this->line("{$business->name}: {$corrected} batch(es) corrected");
        }

        TenantScope::leave();

        // ⚠ A rebuild is NOT a stock edit, whatever it looks like. Joy's rule
        // forbids changing a quantity without a movement to explain it; this
        // changes nothing in reality, it makes a stale cache agree with the
        // movements that were always there. Logged so the correction itself is
        // visible.
        activity('inventory.ledger')
            ->withProperties(['batches_corrected' => $total])
            ->log("Rebuilt stock cache from the ledger: {$total} batch(es) corrected");

        $this->info("Rebuilt from the ledger. {$total} batch(es) corrected in total.");

        return self::SUCCESS;
    }
}
