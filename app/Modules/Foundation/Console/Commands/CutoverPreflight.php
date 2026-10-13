<?php

namespace App\Modules\Foundation\Console\Commands;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this system ready to be used for real on a given morning?
 *
 * ⚠⚠ READ ONLY, ALWAYS. Nothing here writes, updates, seeds or repairs. It is
 * meant to be run against PRODUCTION during the preparation week and again on
 * cutover morning, so anything that could change a figure is out of bounds by
 * construction. The verifiers it calls are the same read-only ones the nightly
 * checks use, and none of them has a repair path wired in here.
 *
 * ⚠⚠ RUNS COLD. A console command carries no BusinessContext, and every model
 * below is business scoped, so without entering the tenant BusinessScope
 * compiles `1 = 0` and this would report a clean bill of health having examined
 * nothing. That failure has bitten this project three times, so the tenant is
 * entered explicitly and put back afterwards.
 *
 * ⚠ It refuses to guess business data. It cannot know which drums, bottles,
 * caps and cartons Joy intends to count, so it checks that raw material SKUs
 * EXIST and reports how many, and leaves the completeness of that list to the
 * person holding the count sheet. The same applies to prices: it will say which
 * sellable products have no price in force, never what the price should be.
 */
class CutoverPreflight extends Command
{
    protected $signature = 'cutover:preflight
                            {--business= : Business id or slug to check}
                            {--date= : The cutover date to test readiness for (defaults to today)}';

    protected $description = 'Read-only readiness check for the first real purchase and sale cycle';

    private const PASS = 'PASS';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /** @var list<array{status: string, check: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        $date = $this->option('date') === null
            ? Carbon::today()
            : Carbon::parse($this->option('date'))->startOfDay();

        $this->line("Cutover preflight for <options=bold>{$business->name}</> on <options=bold>{$date->toDateString()}</>");
        $this->line('Read only. Nothing below writes to the database.');
        $this->newLine();

        // ⚠⚠ Prove WHICH database answered, before any check claims anything
        // about it. A cached config has silently pointed a command at the wrong
        // database on this project before, and a readiness report on the wrong
        // database is worse than no report.
        // ⚠ Driver aware on purpose: `database()` and `@@hostname` are MySQL
        // only and a bare probe crashes the whole command on SQLite, which is
        // what the test suite and a developer machine actually run.
        $this->line('Database: <options=bold>'.$this->databaseIdentity().'</>');
        $this->newLine();

        $callersBusiness = app(BusinessContext::class)->id();
        TenantScope::enter($business->id);

        try {
            // ⚠⚠ Each check is isolated. A readiness report that dies halfway
            // through on an unexpected exception tells the person reading it
            // nothing about the checks that never ran, and this is meant to be
            // read on a morning when something is already going wrong. A check
            // that throws becomes a failure with its own reason, and the rest
            // still run. Found by pointing it at a half-migrated database.
            $this->guard('Migrations', fn () => $this->checkMigrations());
            $this->guard('Accounting period', fn () => $this->checkAccountingPeriod($date));
            $this->guard('Opening Balance Equity account', fn () => $this->checkChartOfAccounts());
            $this->guard('Account mappings in force', fn () => $this->checkAccountMappings($date));
            $this->guard('Warehouses', fn () => $this->checkWarehouses());
            $this->guard('Raw material and packaging SKUs', fn () => $this->checkRawMaterials());
            $this->guard('Selling prices', fn () => $this->checkSellablePrices($date));
            $this->guard('Customer price groups', fn () => $this->checkCustomerPriceGroups());
            $this->guard('Opening stock', fn () => $this->checkOpeningStock($date));
            $this->guard('Opening customer and supplier dues', fn () => $this->checkOpeningDues());
            $this->guard('Opening Balance Equity cleared', fn () => $this->checkOpeningEquityCleared($date));
            $this->guard('Money accounts', fn () => $this->checkMoneyAccounts());
            $this->guard('People who can act on day one', fn () => $this->checkPeopleWhoCanAct());
            $this->guard('Ledgers', fn () => $this->checkLedgers());
        } finally {
            if ($callersBusiness === null) {
                TenantScope::leave();
            } else {
                TenantScope::enter($callersBusiness);
            }
        }

        return $this->report();
    }

    private function guard(string $check, callable $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            $this->record(self::FAIL, $check, 'This check could not run: '.$e->getMessage());
        }
    }

    private function databaseIdentity(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return $driver.' '.$connection->getDatabaseName();
        }

        try {
            $identity = DB::selectOne('select database() as db, @@hostname as host');

            return "{$identity->db} on {$identity->host}";
        } catch (Throwable) {
            return $driver.' '.$connection->getDatabaseName();
        }
    }

    private function resolveBusiness(): ?Business
    {
        $given = $this->option('business');

        $businesses = Business::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get();

        if ($given === null) {
            // ⚠ Deliberately no default. Guessing the tenant on a readiness
            // check is how somebody reads a clean result for the wrong company.
            $this->error('Say which business to check with --business.');
            $this->line('Active businesses:');

            foreach ($businesses as $candidate) {
                $this->line("  {$candidate->id}  {$candidate->slug}  ({$candidate->name})");
            }

            return null;
        }

        $business = $businesses->first(
            fn (Business $candidate) => (string) $candidate->id === (string) $given || $candidate->slug === $given
        );

        if ($business === null) {
            $this->error("No active business matches \"{$given}\".");

            return null;
        }

        return $business;
    }

    private function record(string $status, string $check, string $detail): void
    {
        $this->results[] = ['status' => $status, 'check' => $check, 'detail' => $detail];

        $tag = match ($status) {
            self::PASS => '<fg=green>PASS</>',
            self::WARN => '<fg=yellow>WARN</>',
            default => '<fg=red>FAIL</>',
        };

        $this->line("  {$tag}  {$check}");

        if ($detail !== '') {
            $this->line("        {$detail}");
        }
    }

    private function checkMigrations(): void
    {
        try {
            $ran = DB::table('migrations')->count();
            $files = collect(glob(database_path('migrations/*.php')))->count()
                + collect(glob(app_path('Modules/*/database/migrations/*.php')))->count();

            $this->record(
                $files > $ran ? self::FAIL : self::PASS,
                'Migrations',
                $files > $ran
                    ? "{$ran} applied but {$files} migration files exist; run migrate --force before going live."
                    : "{$ran} applied, none pending."
            );
        } catch (Throwable $e) {
            $this->record(self::FAIL, 'Migrations', 'Could not read the migrations table: '.$e->getMessage());
        }
    }

    private function checkAccountingPeriod(Carbon $date): void
    {
        try {
            AccountingPeriod::assertOpenFor($date);
            $this->record(self::PASS, 'Accounting period', "{$date->toDateString()} is open for posting.");
        } catch (Throwable $e) {
            $this->record(self::FAIL, 'Accounting period', $e->getMessage());
        }
    }

    /**
     * ⚠ Opening Balance Equity is resolved by SLUG, not by a mapping key, so a
     * missing mapping row is NOT the failure to look for here. The account
     * itself is.
     */
    private function checkChartOfAccounts(): void
    {
        $equity = Account::bySlug('opening_balance_equity');

        $this->record(
            $equity === null ? self::FAIL : self::PASS,
            'Opening Balance Equity account',
            $equity === null
                ? 'No account carries the slug opening_balance_equity; the opening stock journal cannot balance without it.'
                : "{$equity->code} {$equity->name}."
        );
    }

    private function checkAccountMappings(Carbon $date): void
    {
        // The keys the first purchase and the first sale actually touch.
        $required = [
            'inventory.stock_on_hand' => 'stock received and sold',
            'inventory.goods_received_not_invoiced' => 'goods received before the supplier bill',
            'purchasing.accounts_payable' => 'what is owed to suppliers',
            'sales.accounts_receivable' => 'what customers owe',
            'sales.revenue' => 'the sale itself',
            'sales.cost_of_goods_sold' => 'the cost of what was sold',
            'sales.goods_delivered_not_invoiced' => 'goods delivered before the invoice',
        ];

        $missing = [];

        foreach ($required as $key => $why) {
            if (AccountMapping::forKeyOn($key, $date) === null) {
                $missing[] = "{$key} ({$why})";
            }
        }

        $this->record(
            $missing === [] ? self::PASS : self::FAIL,
            'Account mappings in force',
            $missing === []
                ? count($required).' required mappings are in force on this date.'
                : count($missing).' missing on this date: '.implode('; ', $missing)
        );
    }

    private function checkWarehouses(): void
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);

        $this->record(
            $warehouses->isEmpty() ? self::FAIL : self::PASS,
            'Warehouses',
            $warehouses->isEmpty()
                ? 'No active warehouse; nothing can be counted into stock.'
                : $warehouses->count().' active: '.$warehouses->map(fn ($w) => "{$w->code} {$w->name}")->implode(', ')
        );
    }

    /**
     * Rehearsal finding F1. The catalogue held only finished goods, so the drums,
     * bottles, caps, labels and cartons standing on the shelf on 31 August had
     * no SKU to be counted into.
     *
     * ⚠ It cannot know the intended list, so it refuses to pretend. Zero is a
     * hard failure; any non-zero count is reported for a human to judge.
     */
    private function checkRawMaterials(): void
    {
        $raw = Product::where('is_active', true)->where('type', ProductType::RawMaterial)->count();

        if ($raw === 0) {
            $this->record(
                self::FAIL,
                'Raw material and packaging SKUs',
                'No active raw material products exist (F1). Drums, bottles, caps, labels and cartons '
                .'cannot be counted on 31 August or consumed by a production run until they have SKUs.'
            );

            return;
        }

        $this->record(
            self::WARN,
            'Raw material and packaging SKUs',
            "{$raw} active raw material SKU(s) exist. This check cannot know the intended list: "
            .'confirm every line of the count sheet has one before printing it.'
        );
    }

    /**
     * The defect that would have stopped the first sale: a sellable product the
     * price file never reached refuses at the counter.
     */
    private function checkSellablePrices(Carbon $date): void
    {
        $sellable = Product::where('is_active', true)
            ->where('type', ProductType::FinishedGood)
            ->orderBy('name')
            ->get(['id', 'sku', 'name']);

        if ($sellable->isEmpty()) {
            $this->record(self::FAIL, 'Selling prices', 'No active finished goods exist to sell.');

            return;
        }

        $pricedIds = ProductPrice::query()
            ->inForceOn($date)
            ->distinct()
            ->pluck('product_id')
            ->all();

        $unpriced = $sellable->reject(fn (Product $p) => in_array($p->id, $pricedIds, true));

        if ($unpriced->isEmpty()) {
            $this->record(
                self::PASS,
                'Selling prices',
                "Every one of the {$sellable->count()} sellable products has a price in force on this date."
            );

            return;
        }

        $names = $unpriced->take(10)->map(fn (Product $p) => "{$p->sku} {$p->name}")->implode('; ');
        $more = $unpriced->count() > 10 ? ' and '.($unpriced->count() - 10).' more' : '';

        $this->record(
            self::FAIL,
            'Selling prices',
            "{$unpriced->count()} of {$sellable->count()} sellable products have NO price in force on "
            ."{$date->toDateString()}, so each refuses its first sale: {$names}{$more}"
        );
    }

    /**
     * Rehearsal finding F2. A customer with no price group bills at RETAIL
     * silently, and the books still balance, so nobody notices.
     *
     * ⚠ WARN and never FAIL. The fallthrough is a decision already taken
     * (D-PRC-2), and the walk-in customer legitimately holds no group. What this
     * can honestly do is name every customer standing on the default.
     */
    private function checkCustomerPriceGroups(): void
    {
        $groups = PriceGroup::where('is_active', true)->count();

        if ($groups === 0) {
            $this->record(self::FAIL, 'Price groups', 'No active price groups exist.');

            return;
        }

        $ungrouped = Customer::query()
            ->where('is_active', true)
            ->whereNull('price_group_id')
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($ungrouped->isEmpty()) {
            $this->record(self::PASS, 'Customer price groups', "{$groups} groups; every active customer has one.");

            return;
        }

        $this->record(
            self::WARN,
            'Customer price groups',
            $ungrouped->count().' active customer(s) have NO price group and will bill at RETAIL silently (F2): '
            .$ungrouped->take(10)->pluck('name')->implode('; ')
            .'. A walk-in customer is expected here; a dealer or distributor is not.'
        );
    }

    private function checkOpeningStock(Carbon $date): void
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);
        $recorded = OpeningStock::query()->pluck('warehouse_id')->all();

        $with = $warehouses->filter(fn ($w) => in_array($w->id, $recorded, true));
        $without = $warehouses->reject(fn ($w) => in_array($w->id, $recorded, true));

        if ($warehouses->isEmpty()) {
            return;
        }

        if ($with->isEmpty()) {
            $this->record(
                self::WARN,
                'Opening stock',
                'Not applied to any warehouse yet. Correct before the 31 August count, wrong on the morning after it.'
            );

            return;
        }

        $this->record(
            $without->isEmpty() ? self::PASS : self::WARN,
            'Opening stock',
            $without->isEmpty()
                ? 'Applied to all '.$with->count().' active warehouse(s).'
                : 'Applied to '.$with->map(fn ($w) => $w->code)->implode(', ')
                  .' but NOT to '.$without->map(fn ($w) => $w->code)->implode(', ').'.'
        );
    }

    /**
     * Opening dues are one document per unpaid invoice or bill (finance
     * decision A4/A5). This cannot know whether any were owed at cutover, so
     * it reports what is entered and leaves the "should there be more"
     * question to the person holding the lists.
     */
    private function checkOpeningDues(): void
    {
        $invoices = SalesInvoice::query()->where('is_opening', true)->get(['total']);
        $bills = SupplierBill::query()->where('is_opening', true)->get(['total']);

        $owedToUs = Money::sum($invoices->map(fn (SalesInvoice $invoice) => Money::ofTaka((string) $invoice->total)));
        $weOwe = Money::sum($bills->map(fn (SupplierBill $bill) => Money::ofTaka((string) $bill->total)));

        $this->record(
            self::WARN,
            'Opening customer and supplier dues',
            ($invoices->isEmpty() && $bills->isEmpty())
                ? 'None entered. Correct only if nobody owed us and we owed no supplier at cutover; otherwise enter one row per unpaid invoice or bill.'
                : $invoices->count().' opening invoice(s) owed to us, total '.$owedToUs->format()
                  .'; '.$bills->count().' opening bill(s) we owe, total '.$weOwe->format()
                  .'. Compare both with the receivables and payables lists at cutover.'
        );
    }

    /**
     * ⚠ Opening Balance Equity is a clearing account. Every opening figure is
     * balanced against it, and the accountant then moves what is left to
     * owner's capital or retained earnings. A balance still sitting there
     * means that closing journal has not been posted yet. A warning, not a
     * failure: during the preparation week a balance here is expected.
     */
    private function checkOpeningEquityCleared(Carbon $date): void
    {
        $equity = Account::bySlug('opening_balance_equity');

        if ($equity === null) {
            return; // Already a failure under "Opening Balance Equity account".
        }

        $lines = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $equity->id)
            ->whereDate('journals.journal_date', '<=', $date->toDateString());

        if (! (clone $lines)->exists()) {
            $this->record(self::PASS, 'Opening Balance Equity cleared', "{$equity->code} has nothing posted to it yet.");

            return;
        }

        $balance = Money::ofMinor((int) $lines->sum('journal_lines.amount_minor'));

        $this->record(
            $balance->minor === 0 ? self::PASS : self::WARN,
            'Opening Balance Equity cleared',
            $balance->minor === 0
                ? "{$equity->code} nets to zero on {$date->toDateString()}."
                : "{$equity->code} still holds ".$balance->absolute()->format().($balance->minor < 0 ? ' (credit)' : ' (debit)')
                  ." on {$date->toDateString()}. The accountant's closing journal to owner's capital or retained earnings has not been posted yet."
        );
    }

    private function checkMoneyAccounts(): void
    {
        $accounts = MoneyAccount::where('is_active', true)->count();

        $this->record(
            $accounts === 0 ? self::FAIL : self::PASS,
            'Money accounts',
            $accounts === 0
                ? 'No active cash or bank account, so no receipt or payment can be recorded.'
                : "{$accounts} active."
        );
    }

    /**
     * Rehearsal finding F6: somebody with authority to approve a purchase order
     * has to be reachable on the first morning, or the first receipt stalls.
     */
    private function checkPeopleWhoCanAct(): void
    {
        $needed = [
            'purchasing.po.approve' => 'approve a purchase order',
            'purchasing.receipt.record' => 'record a goods receipt',
            'sales.sell' => 'raise a sale',
        ];

        $gaps = [];

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('businesses', fn ($q) => $q->whereKey(app(BusinessContext::class)->id()))
            ->get();

        foreach ($needed as $permission => $what) {
            $holders = $users->filter(fn (User $u) => $u->is_super_admin || $u->can($permission))->count();

            if ($holders === 0) {
                $gaps[] = "nobody can {$what} ({$permission})";
            }
        }

        $this->record(
            $gaps === [] ? self::PASS : self::FAIL,
            'People who can act on day one',
            $gaps === []
                ? $users->count().' active user(s) cover approving, receiving and selling.'
                : implode('; ', $gaps)
        );
    }

    private function checkLedgers(): void
    {
        $stock = StockLedger::verify();

        $this->record(
            $stock === [] ? self::PASS : self::FAIL,
            'Inventory ledger',
            $stock === []
                ? 'Every batch, warehouse and product total is explained by movements.'
                : count($stock).' problem(s): '.implode('; ', array_slice($stock, 0, 5))
        );

        $reconciliation = SubledgerReconciliation::reconcileCurrentBusiness();

        if ($reconciliation['checks'] === 0) {
            // ⚠ The false green this project already met once: zero checks is
            // not agreement. Say what was examined, not what was not.
            $this->record(
                self::WARN,
                'Subledger reconciliation',
                'Nothing was reconciled because no anchor mappings are configured. This is NOT a statement that the books agree.'
            );
        } else {
            $this->record(
                $reconciliation['problems'] === [] ? self::PASS : self::FAIL,
                'Subledger reconciliation',
                $reconciliation['problems'] === []
                    ? $reconciliation['checks'].' check(s), every subsidiary ledger agrees with its control account.'
                    : count($reconciliation['problems']).' break(s): '.implode('; ', array_slice($reconciliation['problems'], 0, 5))
            );
        }

        // ⚠ System wide, not per business: LedgerIntegrity has no single-tenant
        // entry point, and a journal that does not balance is everybody's
        // problem anyway. Labelled so the scope is not overstated.
        // ⚠⚠ It returns a KEYED array (problems, warnings, businesses,
        // journals), unlike StockLedger::verify() which returns a bare list of
        // strings. Treating the two alike turned this check into "Array to
        // string conversion" and cost the report its last line.
        $integrity = LedgerIntegrity::verifyAllBusinesses();
        $problems = $integrity['problems'];

        $this->record(
            $problems === [] ? self::PASS : self::FAIL,
            'Accounting ledger (all businesses)',
            $problems === []
                ? "Every journal balances and every set of books nets to zero ({$integrity['journals']} journal(s) across {$integrity['businesses']} business(es))."
                : count($problems).' problem(s): '.implode('; ', array_slice($problems, 0, 5))
        );
    }

    private function report(): int
    {
        $failures = collect($this->results)->where('status', self::FAIL);
        $warnings = collect($this->results)->where('status', self::WARN);

        $this->newLine();
        $this->line(sprintf(
            '%d check(s): %d passed, %d warning(s), %d failure(s).',
            count($this->results),
            collect($this->results)->where('status', self::PASS)->count(),
            $warnings->count(),
            $failures->count(),
        ));

        if ($failures->isEmpty()) {
            $this->newLine();
            $this->info('Ready for the first real purchase and sale cycle.');

            if ($warnings->isNotEmpty()) {
                $this->warn('Read the warnings first: they are the things this check cannot decide for you.');
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('NOT ready. Each failure below stops a real transaction:');

        foreach ($failures as $failure) {
            $this->line("  - {$failure['check']}: {$failure['detail']}");
        }

        return self::FAILURE;
    }
}
