<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Support\LedgerIntegrity;
use Illuminate\Console\Command;

/**
 * Proves the general ledger adds up, for every business.
 *
 * ⚠ Does NOT self-heal, and there is no `--rebuild`. `inventory:verify-ledger`
 * has one because a stock cache can legitimately be rewritten from the movements
 * that were always there. Nothing here is a cache: a journal that does not
 * balance is not a stale figure to recompute, it is an entry somebody has to
 * look at. Repairing it automatically would be inventing accounting.
 */
class VerifyAccountingLedger extends Command
{
    protected $signature = 'accounting:verify-ledger';

    protected $description = 'Verify that every journal balances and the trial balance nets to zero';

    public function handle(): int
    {
        $result = LedgerIntegrity::verifyAllBusinesses();

        $this->line("Checked {$result['journals']} journal(s) across {$result['businesses']} business(es).");

        foreach ($result['warnings'] as $warning) {
            $this->warn("  ! {$warning}");
        }

        if ($result['problems'] === []) {
            $this->newLine();
            $this->info('The ledger is consistent: every journal balances and every set of books nets to zero.');

            // ⚠ Warnings do NOT fail the command. Half-entered opening balances
            // are a normal state that can last days, and a command that is red
            // throughout setup is a command nobody looks at by the time it
            // matters.
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($result['problems']).' problem(s) found:');

        foreach ($result['problems'] as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }
}
