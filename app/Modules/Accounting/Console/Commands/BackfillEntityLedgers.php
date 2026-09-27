<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Support\EntityLedgers;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ 27 Sep 2026 (Joy): give every customer, supplier and employee that already
 * exists its own named ledger head (EntityLedgers). New ones get theirs when
 * they are created; this back-fills the ones from before that change, and is the
 * safety net for any create path that was missed.
 *
 * Idempotent: an entity that already has a head is left alone (renamed only if
 * its name drifted), so it is safe to re-run.
 */
class BackfillEntityLedgers extends Command
{
    protected $signature = 'accounting:backfill-entity-ledgers {--dry-run : count what is missing without creating anything}';

    protected $description = 'Give every existing customer, supplier and employee its own ledger head';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $made = 0;

        foreach ([Customer::class, Supplier::class, Employee::class] as $class) {
            /** @var class-string<Model> $class */
            $missing = $class::withoutGlobalScopes()->whereNull('account_id')->get();

            $this->line(sprintf('%s: %d without a head.', class_basename($class), $missing->count()));

            if ($dryRun) {
                continue;
            }

            foreach ($missing as $entity) {
                EntityLedgers::ensureOwnLedger($entity);
                $made++;
            }
        }

        $this->info($dryRun ? 'Dry run: nothing created.' : "Created {$made} ledger head(s).");

        return self::SUCCESS;
    }
}
