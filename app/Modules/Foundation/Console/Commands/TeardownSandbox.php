<?php

namespace App\Modules\Foundation\Console\Commands;

use App\Modules\Foundation\database\seeders\SandboxSeeder;
use App\Modules\Foundation\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the dummy sandbox business and everything behind it.
 *
 * ⚠⚠ THIS COMMAND DELETES A WHOLE TENANT, WHICH IS THE MOST DESTRUCTIVE THING IN
 * THIS APPLICATION. Every guard below exists because a hand-typed SQL delete
 * against a live database is how the wrong company gets emptied.
 *
 *  1. It refuses ANY slug other than the sandbox, and the slug is a constant on
 *     the seeder rather than an argument default somebody can override.
 *  2. It refuses to run if the target holds a posted JOURNAL... unless --force,
 *     because a sandbox that has run the cycle is SUPPOSED to have journals. The
 *     check exists so pointing this at a real business fails loudly first.
 *  3. It reports orphan rows afterwards rather than trusting the cascade.
 *
 * Design note: DUMMY-DATA-DESIGN.md.
 */
class TeardownSandbox extends Command
{
    protected $signature = 'sandbox:teardown {--business=sandbox : Slug to remove} {--force : Proceed even though the sandbox holds journals}';

    protected $description = 'Delete the dummy sandbox business and everything that belongs to it';

    public function handle(): int
    {
        $slug = (string) $this->option('business');

        // ⚠⚠ GUARD 1. The only slug this command may ever touch. Not a default
        // that can be overridden - a hard refusal.
        if ($slug !== SandboxSeeder::SLUG) {
            $this->error("Refusing: this command only removes the '".SandboxSeeder::SLUG."' business, never '{$slug}'.");

            return self::FAILURE;
        }

        $business = Business::withoutGlobalScopes()->where('slug', $slug)->first();

        if ($business === null) {
            $this->info("No '{$slug}' business exists; nothing to do.");

            return self::SUCCESS;
        }

        // ⚠⚠ GUARD 2. A real business has employees and a ledger. If this command
        // is ever pointed somewhere it should not be, this is the sentence that
        // stops it.
        $journals = DB::table('journals')->where('business_id', $business->id)->count();

        if ($journals > 0 && ! $this->option('force')) {
            $this->error("The '{$slug}' business holds {$journals} posted journal(s).");
            $this->line('A sandbox that has run the cycle is expected to. Re-run with --force if this really is the sandbox.');

            return self::FAILURE;
        }

        $before = $this->rowCounts($business->id);
        $this->line("Removing business #{$business->id} '{$business->name}' - {$before->sum()} rows across {$before->filter()->count()} tables.");

        // ⚠⚠ NO FOREIGN KEY IS EVER DISABLED, AND THAT IS DELIBERATE.
        //
        // The first two attempts did disable them. Both were wrong. MySQL honours
        // `SET FOREIGN_KEY_CHECKS=0` inside a transaction; SQLite silently ignores
        // `PRAGMA foreign_keys` inside one, and the test harness wraps every test
        // in a transaction - so the guard that looked like it worked in production
        // was a no-op under test, which is the worst of both.
        //
        // ⚠ Turning the constraints off is also the wrong instinct: they are the
        // thing standing between this command and a half-deleted tenant. Instead
        // the deletion CONVERGES - repeated passes, each removing whatever is no
        // longer referenced - so the safe order is DERIVED every run rather than
        // hand-maintained and re-derived every time a module adds a table.
        $tables = $this->tablesWithBusinessId();
        $passes = 0;

        do {
            $deletedThisPass = 0;
            $passes++;

            foreach ($tables as $table) {
                try {
                    $deletedThisPass += DB::table($table)->where('business_id', $business->id)->delete();
                } catch (QueryException) {
                    // Still referenced by a table not yet cleared. The next pass
                    // will reach it.
                }
            }
        } while ($deletedThisPass > 0 && $passes < 20);

        DB::table('businesses')->where('id', $business->id)->delete();

        $this->line("Converged in {$passes} pass(es).");

        // ⚠ Verify rather than assume. The whole point of the design note was
        // that a cascade might not do what it looks like it does.
        $after = $this->rowCounts($business->id);
        $orphans = $after->filter(fn (int $count) => $count > 0);

        if ($orphans->isNotEmpty()) {
            $this->error('Orphan rows remain: '.$orphans->map(fn ($c, $t) => "{$t}={$c}")->implode(', '));

            return self::FAILURE;
        }

        $stillThere = Business::withoutGlobalScopes()->where('slug', $slug)->exists();

        if ($stillThere) {
            $this->error('The business row itself survived.');

            return self::FAILURE;
        }

        $this->info('Sandbox removed. Zero orphan rows.');

        return self::SUCCESS;
    }

    /**
     * Every table carrying a `business_id`, discovered from the schema rather
     * than from a hand-kept list that would go stale the next time a module
     * ships a table.
     *
     * @return Collection<int, string>
     */
    private function tablesWithBusinessId(): Collection
    {
        // ⚠⚠ Laravel's schema API, NOT `information_schema`. The first version of
        // this queried information_schema directly and worked only on MySQL - the
        // end-to-end test, which runs on SQLite, caught it immediately. Driver
        // specific SQL inside application code is a defect even when production
        // happens to be the driver it was written for.
        return collect(Schema::getTables())
            ->map(fn ($table) => is_array($table) ? $table['name'] : $table->name)
            ->filter(fn (string $table) => Schema::hasColumn($table, 'business_id'))
            ->sort()
            ->values();
    }

    /** @return Collection<string, int> */
    private function rowCounts(int $businessId): Collection
    {
        return $this->tablesWithBusinessId()
            ->mapWithKeys(fn (string $table) => [
                $table => (int) DB::table($table)->where('business_id', $businessId)->count(),
            ]);
    }
}
