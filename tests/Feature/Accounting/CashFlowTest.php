<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CashFlow;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The statement of cash flows, built from the ledger by the direct method.
 *
 * ⚠⚠ The property that matters is that it PROVES: the three sections are built
 * by classifying journal lines, and the closing balance is read separately from
 * the ledger. Two independent routes to one number. A trial balance can balance
 * while being wrong; this cannot agree while being incomplete.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-27 10:00:00');

    $this->business = Business::create([
        'name' => 'Flow Co', 'slug' => 'flow-co', 'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo(['accounting.view', 'accounting.journal.post']);

    $outsider = Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($reader);

    $this->stranger = User::factory()->create();
    $this->stranger->businesses()->attach($this->business->id);
    $this->stranger->assignRole($outsider);

    $this->bankAccount = Account::bySlug('cash_at_bank');
    $this->tillAccount = Account::bySlug('cash_in_hand');
    $this->sales = Account::bySlug('sales_revenue') ?? Account::where('code', '4100')->firstOrFail();
    $this->rent = Account::where('code', '6200')->firstOrFail();
    $this->equipment = Account::where('code', '1220')->firstOrFail();
    $this->capital = Account::bySlug('owners_capital');
    $this->loan = Account::where('code', '2210')->firstOrFail();
    $this->charges = Account::where('code', '6290')->firstOrFail();

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank',
        'type' => 'bank', 'account_id' => $this->bankAccount->id,
    ]);
    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash in Hand',
        'type' => 'cash', 'account_id' => $this->tillAccount->id,
    ]);

    // A cash movement: one line on the bank, one on the counterpart.
    $this->move = function (Account $counterpart, string $amount, string $date, bool $in) {
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: $in
                ? [
                    JournalLineDraft::debit($this->bankAccount->id, Money::ofTaka($amount)),
                    JournalLineDraft::credit($counterpart->id, Money::ofTaka($amount)),
                ]
                : [
                    JournalLineDraft::credit($this->bankAccount->id, Money::ofTaka($amount)),
                    JournalLineDraft::debit($counterpart->id, Money::ofTaka($amount)),
                ],
        ), $this->user);
    };

    $this->flow = fn (string $from = '2026-08-01', string $to = '2026-08-31') => CashFlow::between(
        Carbon::parse($from)->startOfDay(),
        Carbon::parse($to)->endOfDay(),
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── Classification ─────────────────────────────────────────────────────────

it('sorts each movement into operating, investing or financing', function () {
    ($this->move)($this->sales, '50000', '2026-08-05', true);    // operating in
    ($this->move)($this->rent, '12000', '2026-08-06', false);    // operating out
    ($this->move)($this->equipment, '30000', '2026-08-07', false); // investing out
    ($this->move)($this->capital, '100000', '2026-08-02', true);   // financing in
    ($this->move)($this->loan, '20000', '2026-08-08', true);       // financing in

    $flow = ($this->flow)();

    expect($flow->totalOperating()->minor)->toBe(3_800_000)      // 50,000 - 12,000
        ->and($flow->totalInvesting()->minor)->toBe(-3_000_000)  // 30,000 out
        ->and($flow->totalFinancing()->minor)->toBe(12_000_000); // 100,000 + 20,000
});

/**
 * ⚠ Walked by PARENT, not by a list of codes. A brand new fixed-asset account
 * nobody has told this file about must still land in investing.
 */
it('puts a brand new fixed asset account into investing without being told', function () {
    $newAsset = Account::create([
        'code' => '1240', 'name' => 'Machinery', 'type' => $this->equipment->type,
        'parent_id' => $this->equipment->parent_id, 'is_postable' => true, 'is_active' => true,
    ]);

    ($this->move)($newAsset, '75000', '2026-08-09', false);

    expect(($this->flow)()->totalInvesting()->minor)->toBe(-7_500_000)
        ->and(($this->flow)()->totalOperating()->minor)->toBe(0);
});

// ─── The proof ──────────────────────────────────────────────────────────────

it('accounts for the change in cash exactly', function () {
    ($this->move)($this->capital, '100000', '2026-08-02', true);
    ($this->move)($this->sales, '50000', '2026-08-05', true);
    ($this->move)($this->rent, '12000', '2026-08-06', false);
    ($this->move)($this->equipment, '30000', '2026-08-07', false);

    $flow = ($this->flow)();

    expect($flow->proves())->toBeTrue()
        ->and($flow->difference()->minor)->toBe(0)
        ->and($flow->closing->minor)->toBe(10_800_000)
        ->and($flow->netMovement()->minor)->toBe(10_800_000);
});

it('carries an opening balance in from before the window', function () {
    ($this->move)($this->capital, '100000', '2026-07-15', true);
    ($this->move)($this->sales, '40000', '2026-08-05', true);

    $flow = ($this->flow)();

    expect($flow->opening->minor)->toBe(10_000_000)
        ->and($flow->netMovement()->minor)->toBe(4_000_000)
        ->and($flow->closing->minor)->toBe(14_000_000)
        ->and($flow->proves())->toBeTrue();
});

/**
 * ⚠⚠ Moving your own money is NOT a cash flow. A bank-to-till transfer has cash
 * on both sides, so it must net to zero here - only the fee is a real outflow.
 * Without this the statement would double-count every withdrawal as both a
 * receipt and a payment.
 */
it('shows almost nothing for a transfer between our own accounts', function () {
    ($this->move)($this->capital, '100000', '2026-08-01', true);

    app(RecordMoneyTransfer::class)->handle(
        from: $this->bank, to: $this->till, amount: '20000',
        transferredOn: '2026-08-10', createdBy: $this->user,
        fee: '370', feeAccount: $this->charges,
    );

    $flow = ($this->flow)();

    // The 20,000 moved between two cash accounts and vanishes; only the fee left.
    expect($flow->totalOperating()->minor)->toBe(-37_000)
        ->and($flow->netMovement()->minor)->toBe(9_963_000)
        ->and($flow->proves())->toBeTrue();
});

it('reports nothing rather than zeroes when no money account has a place in the chart', function () {
    MoneyAccount::query()->update(['account_id' => null]);

    expect(($this->flow)()->anyCashAccountMapped)->toBeFalse();
});

// ─── Reachability and permission ────────────────────────────────────────────

it('renders the cash flow screen', function () {
    ($this->move)($this->sales, '50000', '2026-08-05', true);

    $this->actingAs($this->user)->get('/accounting/cash-flow?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/cash-flow/index')
            ->has('operating', 1)
            ->where('proves', true)
            ->where('any_cash_account_mapped', true)
            ->where('totals.closing', '50,000.00')
        );
});

it('keeps someone who may not read the ledger out of the cash flow', function () {
    $this->actingAs($this->stranger)->get('/accounting/cash-flow')->assertForbidden();
});
