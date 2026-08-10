<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Support\SubledgerReconciliation;
use Illuminate\Console\Command;

/**
 * Proves each subsidiary ledger agrees with its control account, for every
 * business.
 *
 * ⚠ `accounting:verify-ledger` proves the ledger agrees with itself; this
 * proves it agrees with the OPERATIONAL DOCUMENTS. Different failure class:
 * every journal can balance and Accounts Payable can still be wrong for every
 * supplier.
 *
 * ⚠ No self-healing and no `--rebuild`, for the same reason verify-ledger has
 * none: nothing here is a cache. A control account that disagrees with its
 * documents is an entry somebody has to look at.
 */
class ReconcileSubledgers extends Command
{
    protected $signature = 'accounting:reconcile';

    protected $description = 'Reconcile AP, AR, stock, GRNI, GDNI, COGS and employee advances against their source documents';

    public function handle(): int
    {
        $result = SubledgerReconciliation::reconcileAllBusinesses();

        $this->line("Ran {$result['checks']} reconciliation(s) across {$result['businesses']} business(es).");

        foreach ($result['warnings'] as $warning) {
            $this->warn("  ! {$warning}");
        }

        if ($result['problems'] === []) {
            $this->newLine();
            $this->info('Every subsidiary ledger agrees with its control account.');

            // ⚠ Warnings do not fail the command - an unallocated payment or a
            // business whose operations predate accounting are normal states,
            // and a command that is red through normal states is a command
            // nobody looks at by the time it matters.
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($result['problems']).' reconciliation break(s) found:');

        foreach ($result['problems'] as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }
}
