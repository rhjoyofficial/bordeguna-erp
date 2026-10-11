<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Reporting\Definitions\CashPositionReport;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cash position as at a date (reports pack, 11 Oct 2026): the audit's bug 2
 * answered as a report. Cash is the cash, bank and wallet heads only; money
 * with a courier or a gateway, what we owe couriers and customer advances are
 * each kept apart, labelled not cash, and never added to it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = cpBusiness('Bor de Guna', 'bdg-cp');
    $this->owner = cpUser($this->business, 'Owner');
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'type' => CustomerType::Retail]);

    $this->till = cpMoney($this->business, 'Till', 'cash');
    $this->bank = cpMoney($this->business, 'City Bank', 'bank');
    $this->pathao = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Pathao', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id]);
    $this->steadfast = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Steadfast', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id]);
    $this->gateway = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => 'gateway', 'account_id' => Account::bySlug('gateway_receivable')->id]);

    $capital = Account::bySlug('owners_capital')->id;
    $ar = Account::bySlug('accounts_receivable')?->id ?? Account::where('code', '1140')->value('id');

    cpPost($this, '2026-10-01', [
        JournalLineDraft::debit($this->till->account_id, Money::ofTaka('10000'), null, null, $this->till),
        JournalLineDraft::debit($this->bank->account_id, Money::ofTaka('50000'), null, null, $this->bank),
        JournalLineDraft::credit($capital, Money::ofTaka('60000')),
    ]);
    // Cash on delivery the couriers hold, and an online payment the gateway holds.
    cpPost($this, '2026-10-05', [
        JournalLineDraft::debit(Account::bySlug('courier_receivable')->id, Money::ofTaka('3000'), null, null, $this->pathao),
        JournalLineDraft::debit(Account::bySlug('courier_receivable')->id, Money::ofTaka('2000'), null, null, $this->steadfast),
        JournalLineDraft::debit(Account::bySlug('gateway_receivable')->id, Money::ofTaka('1500'), null, null, $this->gateway),
        JournalLineDraft::credit($ar, Money::ofTaka('6500'), null, $this->customer),
    ]);
    // A courier fee we owe Pathao.
    cpPost($this, '2026-10-06', [
        JournalLineDraft::debit(Account::bySlug('courier_charge')->id, Money::ofTaka('400')),
        JournalLineDraft::credit(Account::bySlug('courier_payable')->id, Money::ofTaka('400'), null, null, $this->pathao),
    ]);
    // An advance taken in cash: cash goes up, and so does what we owe.
    cpPost($this, '2026-10-07', [
        JournalLineDraft::debit($this->till->account_id, Money::ofTaka('700'), null, null, $this->till),
        JournalLineDraft::credit(Account::bySlug('customer_advances')->id, Money::ofTaka('700'), null, $this->customer),
    ]);
    // After the day asked about: not in the position as at 9 October.
    cpPost($this, '2026-10-10', [
        JournalLineDraft::debit($this->till->account_id, Money::ofTaka('999'), null, null, $this->till),
        JournalLineDraft::credit($capital, Money::ofTaka('999')),
    ]);

    // Receipts not yet applied to an invoice (documents, credit left in 1140).
    $receipt = fn (float $amount, ?int $orderId, string $on) => DB::table('customer_receipts')->insertGetId([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'sales_order_id' => $orderId,
        'money_account_id' => $this->till->id, 'received_on' => $on, 'amount' => $amount,
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = DB::table('sales_orders')->insertGetId([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'order_number' => 'SO-1',
        'ordered_at' => '2026-10-02', 'status' => 'open', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $receipt(300, $order, '2026-10-03');
    $receipt(500, null, '2026-10-04');
    $applied = $receipt(400, null, '2026-10-04');
    $invoice = DB::table('sales_invoices')->insertGetId([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'INV-1',
        'invoice_date' => '2026-10-04', 'subtotal' => 400, 'total' => 400, 'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_receipt_allocations')->insert([
        'business_id' => $this->business->id, 'customer_receipt_id' => $applied, 'sales_invoice_id' => $invoice,
        'amount' => 400, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $receipt(800, null, '2026-10-10');

    $this->asAt = new ReportParameters(['as_at' => '2026-10-09']);
});

afterEach(fn () => Carbon::setTestNow());

function cpBusiness(string $name, string $slug): Business
{
    $business = Business::create(['name' => $name, 'slug' => $slug]);
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Clerk'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new AccountingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    return $business;
}

function cpUser(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    $user->assignRole($role);

    return $user;
}

function cpMoney(Business $business, string $name, string $type): MoneyAccount
{
    $account = MoneyAccount::create(['business_id' => $business->id, 'name' => $name, 'type' => $type]);
    MoneyLedgers::ensureOwnLedger($account);

    return $account->fresh();
}

function cpPost(object $t, string $on, array $lines): void
{
    app(PostJournal::class)->handle(new JournalDraft(date: Carbon::parse($on), lines: $lines, memo: 'Fixture'), $t->owner);
}

function cpRows(ReportParameters $parameters): Illuminate\Support\Collection
{
    return app(CashPositionReport::class)->rows($parameters)->collect();
}

it('shows each cash and bank head, and its total ties to the cash ledger as at the day', function () {
    $rows = cpRows($this->asAt);
    $cash = $rows->where('section', 'Cash and bank')->keyBy('account');

    $ledger = (int) MoneyLedgers::cashLines($this->business->id)
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->whereDate('journals.journal_date', '<=', '2026-10-09')
        ->sum('journal_lines.amount_minor');

    expect($cash['Till (Cash)']['cash'])->toEqual(10700)
        ->and($cash['City Bank (Bank account)']['cash'])->toEqual(50000)
        ->and((int) round($rows->sum('cash') * 100))->toBe($ledger)
        ->and($ledger)->toBe(6070000);
});

it('keeps courier and gateway money apart as not cash, each courier its own figure', function () {
    $rows = cpRows($this->asAt);
    $couriers = $rows->where('section', 'With couriers (not cash)')->keyBy('account');

    expect($couriers['Pathao']['owed_to_us'])->toEqual(3000)
        ->and($couriers['Steadfast']['owed_to_us'])->toEqual(2000)
        ->and($couriers['Pathao']['cash'])->toBeNull()
        ->and($rows->firstWhere('account', 'SSLCommerz')['owed_to_us'])->toEqual(1500)
        ->and($rows->sum('owed_to_us'))->toEqual(6500)
        // None of it is in the cash column.
        ->and($rows->sum('cash'))->toEqual(60700);
});

it('shows what we owe couriers and customers, advances in both places, as not cash', function () {
    $rows = cpRows($this->asAt)->keyBy('account');

    $payable = cpRows($this->asAt)->where('section', 'Payable to couriers (not cash)');

    expect($payable->pluck('account')->all())->toBe(['Pathao'])
        ->and($payable->first()['owed_by_us'])->toEqual(400)
        ->and($payable->first()['owed_to_us'])->toBeNull()
        ->and($rows['2150 Advances from customers']['owed_by_us'])->toEqual(700)
        ->and($rows['Paid for orders not yet invoiced (held in 1140)']['owed_by_us'])->toEqual(300)
        // 500 on account; the 400 applied to an invoice is not; the 800 came after the day.
        ->and($rows['Other receipts not applied to an invoice (held in 1140)']['owed_by_us'])->toEqual(500)
        ->and(cpRows($this->asAt)->sum('owed_by_us'))->toEqual(1900);
});

it('moves with the day asked about', function () {
    $later = cpRows(new ReportParameters(['as_at' => '2026-10-10']));

    expect($later->sum('cash'))->toEqual(61699)
        ->and($later->firstWhere('account', 'Other receipts not applied to an invoice (held in 1140)')['owed_by_us'])->toEqual(1300);
});

it('never shows another business\'s money', function () {
    $other = cpBusiness('Other', 'other-cp');
    $otherOwner = cpUser($other, 'Owner');
    $vault = cpMoney($other, 'Vault', 'cash');
    $otherCourier = MoneyAccount::create(['business_id' => $other->id, 'name' => 'Other courier', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id]);
    app(PostJournal::class)->handle(new JournalDraft(date: Carbon::parse('2026-10-02'), lines: [
        JournalLineDraft::debit($vault->account_id, Money::ofTaka('77777'), null, null, $vault),
        JournalLineDraft::debit(Account::bySlug('courier_receivable')->id, Money::ofTaka('111'), null, null, $otherCourier),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('77888')),
    ], memo: 'Other'), $otherOwner);

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $rows = cpRows($this->asAt);

    expect($rows->sum('cash'))->toEqual(60700)
        ->and($rows->sum('owed_to_us'))->toEqual(6500)
        ->and($rows->pluck('account')->all())->not->toContain('Vault (Cash)')->not->toContain('Other courier');
});

it('is an accounting report behind accounting.view, with a chart and the not cash note', function () {
    $clerk = cpUser($this->business, 'Clerk');
    $registry = app(ReportRegistry::class);

    expect($registry->find('cash_position')->permission())->toBe('accounting.view')
        ->and($registry->availableTo($this->owner)->has('cash_position'))->toBeTrue()
        ->and($registry->availableTo($clerk)->has('cash_position'))->toBeFalse();

    $this->actingAs($clerk)->get(route('hrm.reports.preview', 'cash_position').'?as_at=2026-10-09')->assertNotFound();

    $json = $this->actingAs($this->owner)->getJson(route('hrm.reports.preview', 'cash_position').'?as_at=2026-10-09')->assertOk();

    expect($json->json('totals.cash.value'))->toEqual(60700)
        ->and($json->json('totals.owed_to_us.value'))->toEqual(6500)
        ->and($json->json('totals.owed_by_us.value'))->toEqual(1900)
        ->and($json->json('meta.note'))->toContain('None of the not cash amounts is added to cash')
        ->and($json->json('chart.type'))->toBe('stacked_bar');

    $this->actingAs($this->owner)->get(route('hrm.reports.run', 'cash_position').'?as_at=2026-10-09&format=pdf')->assertOk();
});
