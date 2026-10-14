<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 4100 Sales Revenue becomes a group (Joy, 14 Oct 2026). See the migration
 * 2026_10_14_990000_sales_revenue_becomes_a_group.
 *
 * ⚠ The properties worth pinning: the account sales post to is RECODED, never
 * converted (same id, slug, mapping and postings, now 4101 under a new 4100
 * heading); a sales invoice still lands on it; the channel and IT heads sit
 * where they should; a second run changes nothing; a fresh install seeds the
 * same shape; and the chart screen still refuses to make 4101 a heading.
 */
const SALES_REVENUE_GROUP_MIGRATION = 'app/Modules/Accounting/database/migrations/2026_10_14_990000_sales_revenue_becomes_a_group.php';

/** The 13 new heads: code => [name, parent code]. */
const SALES_REVENUE_GROUP_HEADS = [
    '4120' => ['Retail Sales', '4100'],
    '4130' => ['Wholesale Sales', '4100'],
    '4140' => ['Dealer Sales', '4100'],
    '4150' => ['Corporate Sales', '4100'],
    '4160' => ['Online & E-commerce Sales', '4100'],
    '4410' => ['Software Development', '4400'],
    '4420' => ['Website Design & Development', '4400'],
    '4430' => ['Web Hosting', '4400'],
    '4440' => ['Domain Registration', '4400'],
    '4450' => ['IT Support & Maintenance', '4400'],
    '4460' => ['Software Subscriptions (SaaS)', '4400'],
    '4470' => ['IT Consulting', '4400'],
    '4490' => ['Other IT Services', '4400'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');

    $this->business = Business::create(['name' => 'Revenue Co', 'slug' => 'revenue-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $permissions = ['accounting.view', 'accounting.journal.post', 'accounting.chart.manage'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($role);

    $this->migration = fn () => require base_path(SALES_REVENUE_GROUP_MIGRATION);

    // The chart as production had it before 14 Oct 2026: 4100 Sales Revenue
    // postable under 4000, slug `sales_revenue`, mapped as `sales.revenue`,
    // with a posting on it, and none of the new heads.
    $this->legacyChart = function (): Account {
        $this->seed(ChartOfAccountsSeeder::class);

        DB::table('accounts')->whereIn('code', array_map('strval', array_keys(SALES_REVENUE_GROUP_HEADS)))->delete();
        $income = DB::table('accounts')->where('code', '4000')->value('id');
        DB::table('accounts')->where('system_slug', 'sales_revenue')->update(['parent_id' => $income]);
        DB::table('accounts')->whereIn('code', ['4100', '4400'])->delete();
        DB::table('accounts')->where('system_slug', 'sales_revenue')->update(['code' => '4100', 'name' => 'Sales Revenue']);

        (new InventoryAccountMappingSeeder)->run();
        (new SalesAccountMappingSeeder)->run();
        app(BusinessContext::class)->set($this->business->id);

        $sales = Account::bySlug('sales_revenue');

        // A sale booked before the change (Dr prepaid, only so it balances).
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse('2026-10-01'),
            lines: [
                JournalLineDraft::debit(Account::where('code', '1180')->sole()->id, Money::ofTaka('2500')),
                JournalLineDraft::credit($sales->id, Money::ofTaka('2500')),
            ],
        ), $this->owner);

        return $sales;
    };

    // Everything under 4000 as comparable rows, for "nothing changed" checks.
    $this->incomeChart = fn () => DB::table('accounts as a')
        ->leftJoin('accounts as p', 'p.id', '=', 'a.parent_id')
        ->where('a.type', 'income')
        ->orderBy('a.code')
        ->get(['a.id', 'a.code', 'a.name', 'a.type', 'a.is_postable', 'a.is_active', 'a.system_slug', 'p.code as parent_code'])
        ->map(fn ($row) => (array) $row)
        ->all();
});

afterEach(fn () => Carbon::setTestNow());

it('recodes the sales account to 4101 under a new 4100 heading and keeps its id, slug, postings and mapping', function () {
    $before = ($this->legacyChart)();
    $mappingId = AccountMapping::where('key', 'sales.revenue')->sole()->id;

    ($this->migration)()->up();

    $sales = Account::find($before->id);
    $group = Account::where('code', '4100')->sole();

    expect($sales->code)->toBe('4101')
        ->and($sales->name)->toBe('Product Sales (automatic)')
        ->and($sales->system_slug)->toBe('sales_revenue')
        ->and($sales->type)->toBe(AccountType::Income)
        ->and($sales->is_postable)->toBeTrue()
        ->and($sales->is_active)->toBeTrue()
        ->and($sales->parent_id)->toBe($group->id)
        // The heading: new row, no slug, nothing can post to it.
        ->and($group->id)->not->toBe($before->id)
        ->and($group->name)->toBe('Sales Revenue')
        ->and($group->type)->toBe(AccountType::Income)
        ->and($group->is_postable)->toBeFalse()
        ->and($group->system_slug)->toBeNull()
        ->and($group->parent->code)->toBe('4000')
        // History and the mapping still name the same id.
        ->and((int) JournalLine::where('account_id', $sales->id)->sum('amount_minor'))->toBe(-250000)
        ->and(JournalLine::where('account_id', $group->id)->exists())->toBeFalse()
        ->and(AccountMapping::where('key', 'sales.revenue')->sole()->id)->toBe($mappingId)
        ->and(AccountMapping::resolve('sales.revenue', Carbon::today())->id)->toBe($sales->id);

    // Each change is on the record, because a migration bypasses the model events.
    $logged = DB::table('activity_log')->where('subject_type', Account::class)->where('subject_id', $sales->id)
        ->where('properties', 'like', '%14 Oct 2026%')->orderBy('id')->pluck('properties')->map(fn ($json) => json_decode($json, true));

    expect($logged)->toHaveCount(2)
        ->and($logged[0]['old'])->toBe(['code' => '4100', 'name' => 'Sales Revenue'])
        ->and($logged[1]['attributes'])->toBe(['parent_id' => $group->id])
        ->and(DB::table('activity_log')->where('log_name', 'system')->where('description', 'like', '%4100 becomes a group%')->count())->toBe(1);
});

it('still posts a sales invoice to the same account', function () {
    $before = ($this->legacyChart)();
    ($this->migration)()->up();

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HONEY', 'name' => 'Honey', 'type' => ProductType::FinishedGood,
    ]);
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Distributor]);

    app(RecordStockIn::class)->handle($honey, $warehouse, '10', '100', $this->owner, occurredAt: '2026-10-02 09:00:00');
    $delivery = app(RecordDelivery::class)->handle(
        customer: $customer, warehouse: $warehouse,
        lines: [['product' => $honey, 'quantity' => '10']], createdBy: $this->owner,
    );

    $invoice = app(RecordSalesInvoice::class)->handle($customer, [
        ['deliveryLine' => $delivery->lines[0], 'quantity' => '10', 'unitPrice' => '1000'],
    ], $this->owner);

    $journal = Journal::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->sole();
    $revenue = JournalLine::where('journal_id', $journal->id)->where('account_id', $before->id)->sum('amount_minor');

    expect((int) $revenue)->toBe(-1000000)
        ->and(Account::find($before->id)->code)->toBe('4101')
        ->and(JournalLine::where('journal_id', $journal->id)->where('account_id', Account::where('code', '4100')->value('id'))->exists())->toBeFalse();
});

it('plants the channel heads under 4100 and the IT heads under a new 4400 heading', function () {
    ($this->legacyChart)();
    ($this->migration)()->up();

    $it = Account::where('code', '4400')->sole();

    expect($it->name)->toBe('IT & Software Service Revenue')
        ->and($it->type)->toBe(AccountType::Income)
        ->and($it->is_postable)->toBeFalse()
        ->and($it->parent->code)->toBe('4000');

    foreach (SALES_REVENUE_GROUP_HEADS as $code => [$name, $parentCode]) {
        $head = Account::where('code', (string) $code)->sole();

        expect($head->name)->toBe($name)
            ->and($head->type)->toBe(AccountType::Income)
            ->and($head->is_postable)->toBeTrue()
            ->and($head->is_active)->toBeTrue()
            ->and($head->system_slug)->toBeNull()
            ->and($head->parent->code)->toBe($parentCode);
    }
});

it('changes nothing when it runs a second time', function () {
    ($this->legacyChart)();
    ($this->migration)()->up();

    $chart = ($this->incomeChart)();
    $logged = DB::table('activity_log')->count();

    ($this->migration)()->up();

    expect(($this->incomeChart)())->toBe($chart)
        ->and(DB::table('activity_log')->count())->toBe($logged);
});

it('builds the same income chart on a fresh install as on an upgraded one', function () {
    ($this->legacyChart)();
    ($this->migration)()->up();

    $shape = fn () => array_map(fn (array $row) => array_diff_key($row, ['id' => true]), ($this->incomeChart)());
    $upgraded = $shape();

    // A fresh chart from the seeder alone.
    DB::table('account_mappings')->delete();
    DB::table('journal_lines')->delete();
    DB::table('journals')->delete();
    DB::table('accounts')->update(['parent_id' => null]);
    DB::table('accounts')->delete();
    $this->seed(ChartOfAccountsSeeder::class);

    expect($shape())->toBe($upgraded);
});

it('does nothing on a chart with no Income root yet, where the seeder plants the shape', function () {
    // A fresh database: earlier migrations have planted the expense band, but
    // the income side does not exist until ChartOfAccountsSeeder runs.
    expect(Account::where('type', 'income')->count())->toBe(0);
    $accounts = Account::count();

    ($this->migration)()->up();

    expect(Account::where('type', 'income')->count())->toBe(0)
        ->and(Account::count())->toBe($accounts);
});

// ⚠ Production, 14 Oct 2026: the first deploy stopped because 4110 was a head
// made by hand, "Sales Collected Before ERP", with postings and named 4110 in
// the owner's daily entry guide. It keeps its id, code, name and postings and
// only moves under the new group; the automatic head takes 4101.
it('keeps a hand-made 4110 head with postings, moving it under the new 4100 group', function () {
    $before = ($this->legacyChart)();
    $income = Account::where('code', '4000')->value('id');
    $collected = Account::create(['code' => '4110', 'name' => 'Sales Collected Before ERP', 'type' => AccountType::Income, 'parent_id' => $income, 'is_postable' => true]);

    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-10-02'),
        lines: [
            JournalLineDraft::debit(Account::where('code', '1180')->sole()->id, Money::ofTaka('885.39')),
            JournalLineDraft::credit($collected->id, Money::ofTaka('885.39')),
        ],
    ), $this->owner);

    ($this->migration)()->up();

    $group = Account::where('code', '4100')->sole();
    $collected->refresh();

    expect($collected->code)->toBe('4110')
        ->and($collected->name)->toBe('Sales Collected Before ERP')
        ->and($collected->is_postable)->toBeTrue()
        ->and($collected->parent_id)->toBe($group->id)
        ->and(JournalLine::where('account_id', $collected->id)->count())->toBe(1)
        ->and(Account::find($before->id)->code)->toBe('4101')
        ->and(Account::find($before->id)->parent_id)->toBe($group->id);

    // A second run moves nothing.
    $chart = ($this->incomeChart)();
    ($this->migration)()->up();
    expect(($this->incomeChart)())->toBe($chart);
});

it('stops with a sentence when 4101 is already taken', function () {
    ($this->legacyChart)();
    Account::create(['code' => '4101', 'name' => 'Hand-made head', 'type' => AccountType::Income, 'parent_id' => Account::where('code', '4000')->value('id')]);

    expect(fn () => ($this->migration)()->up())->toThrow(RuntimeException::class, 'code 4101 is already used by "Hand-made head"');

    expect(Account::bySlug('sales_revenue')->code)->toBe('4100')
        ->and(Account::where('code', '4120')->exists())->toBeFalse();
});

it('rolls back to the old chart while the new heads are unused, and refuses once one has postings', function () {
    $before = ($this->legacyChart)();
    $legacy = ($this->incomeChart)();
    ($this->migration)()->up();

    ($this->migration)()->down();

    expect(($this->incomeChart)())->toBe($legacy);

    ($this->migration)()->up();
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-10-10'),
        lines: [
            JournalLineDraft::debit(Account::where('code', '1180')->sole()->id, Money::ofTaka('900')),
            JournalLineDraft::credit(Account::where('code', '4430')->sole()->id, Money::ofTaka('900')),
        ],
    ), $this->owner);

    expect(fn () => ($this->migration)()->down())->toThrow(RuntimeException::class, 'these heads already have postings: 4430');
    expect(Account::find($before->id)->code)->toBe('4101');
});

it('still refuses to turn 4101 into a heading from the chart screen', function () {
    $before = ($this->legacyChart)();
    ($this->migration)()->up();
    $sales = Account::find($before->id);

    $this->actingAs($this->owner)
        ->put(route('accounting.accounts.update', $sales->id), [
            'code' => '4101', 'name' => $sales->name, 'type' => 'income',
            'parent_id' => $sales->parent_id, 'is_postable' => false, 'is_active' => true,
        ])
        ->assertSessionHasErrors('is_postable');

    expect($sales->fresh()->is_postable)->toBeTrue();
});

it('prints Sales Revenue as its own section of the profit and loss, with 4101 in it', function () {
    $before = ($this->legacyChart)();
    ($this->migration)()->up();

    $statement = IncomeStatement::between(Carbon::parse('2026-10-01')->startOfDay(), Carbon::parse('2026-10-31')->endOfDay());
    $section = $statement->income->first(fn ($section) => $section->code === '4100');

    expect($section)->not->toBeNull()
        ->and($section->name)->toBe('Sales Revenue')
        ->and($section->lines->map(fn ($line) => $line->account->id)->all())->toBe([$before->id])
        ->and($statement->totalIncome()->format())->toBe('2,500.00');
});

it('shows the new hierarchy in the voucher picker: headings closed, 4101 posted by Sales, the new heads open', function () {
    $before = ($this->legacyChart)();
    ($this->migration)()->up();

    $rows = VoucherAccounts::catalogue(Carbon::today(), VoucherType::Journal)->keyBy(fn (array $row) => $row['account']->code);

    expect($rows['4100']['heading'])->toBeTrue()
        ->and($rows['4400']['heading'])->toBeTrue()
        ->and($rows['4101']['account']->id)->toBe($before->id)
        ->and($rows['4101']['usable'])->toBeFalse()
        ->and($rows['4101']['hint'])->toContain('Sales')
        ->and($rows['4120']['usable'])->toBeTrue()
        ->and($rows['4410']['usable'])->toBeTrue();

    // In chart order the heads follow their heading.
    $codes = $rows->keys()->map(fn ($code) => (string) $code)->filter(fn (string $code) => str_starts_with($code, '4'))->values()->all();
    expect(array_slice($codes, 0, 8))->toBe(['4000', '4100', '4101', '4120', '4130', '4140', '4150', '4160']);
});
