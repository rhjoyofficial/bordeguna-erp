<?php

use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\EmployeeCash;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\QuickPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Initial plan B3 (R60 to R63, Joy's Q1 = A, 25 Oct 2026): "Treat Employees
 * as Bank Accounts". Petty Cash - Sifat is funded by a transfer, pays
 * purchases and expenses from the ordinary screens, may go below zero (the
 * company then owes him), and reads as "holds" or "company owes".
 *
 * Joy's own figures: paid 20,000 from a 10,000 float -> the company owes him
 * 10,000; paid 6,000 -> he holds 4,000; and a purchase paid partly from the
 * bank, partly by him, the rest due.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;

    // The guards on, as in production.
    config(['accounting.money_availability' => true]);
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['refuse_negative_money' => true]);

    $this->sifat = Employee::create([
        'employee_code' => 'BDG-000071', 'first_name' => 'Sifat', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    $this->petty = MoneyAccount::create([
        'business_id' => $this->w->business->id, 'name' => 'Petty Cash - Sifat',
        'type' => MoneyAccountType::EmployeeCash, 'custodian_employee_id' => $this->sifat->id,
    ]);
    MoneyLedgers::ensureOwnLedger($this->petty);
    $this->petty->refresh();

    // Tk 1,00,000 in the bank to fund him from.
    $this->w->record('100000', '2026-10-15', into: $this->w->bank);

    $this->fund = fn (string $amount) => app(RecordMoneyTransfer::class)->handle(
        from: $this->w->bank, to: $this->petty, amount: $amount, transferredOn: '2026-10-16', createdBy: $this->w->owner,
    );

    $this->buy = fn (string $quantity, array $payment) => app(RecordQuickPurchase::class)->handle(
        requestKey: (string) Str::uuid(),
        supplier: $this->d->supplier,
        warehouse: $this->d->warehouse,
        purchasedOn: '2026-10-17',
        lines: [['product' => $this->d->salt, 'quantity' => $quantity, 'unit_cost' => '20.00']],
        billNumber: 'CM-'.Str::random(6),
        by: $this->w->owner,
        payment: $payment,
    );

    $this->balance = fn () => MoneyAvailability::balanceMinor($this->petty);

    $this->booksTie = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

afterEach(fn () => Carbon::setTestNow());

it('gets its own ledger head under 1135 Cash with Employees', function () {
    $head = Account::find($this->petty->account_id);
    $group = Account::bySlug('employee_cash_accounts');

    expect($group->code)->toBe('1135')
        ->and((int) $head->parent_id)->toBe($group->id)
        ->and($head->code)->toBe('113501')
        ->and($head->name)->toBe('Petty Cash - Sifat - Bor de Guna');
});

it('paid 20,000 from a 10,000 float: the company owes him 10,000', function () {
    ($this->fund)('10000');

    $quick = ($this->buy)('1000', ['money_account' => $this->petty, 'amount' => '20000']);

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and(($this->balance)())->toBe(-1_000_000)
        ->and(EmployeeCash::position($this->petty))->toBe(['position' => 'owed', 'position_amount' => '10,000.00'])
        ->and($quick->bill->outstanding()->minor)->toBe(0);

    ($this->booksTie)();
});

it('paid 6,000 from a 10,000 float: he holds 4,000', function () {
    ($this->fund)('10000');

    ($this->buy)('300', ['money_account' => $this->petty, 'amount' => '6000']);

    expect(($this->balance)())->toBe(400_000)
        ->and(EmployeeCash::position($this->petty))->toBe(['position' => 'holds', 'position_amount' => '4,000.00']);
});

it('a purchase paid partly from the bank, partly by him, the rest owed to the supplier', function () {
    ($this->fund)('5000');

    // Bill Tk 30,000: Tk 15,000 from the bank, Tk 10,000 by Sifat (he held 5,000), Tk 5,000 due.
    $quick = ($this->buy)('1500', [
        'money_account' => $this->w->bank, 'amount' => '25000',
        'split_lines' => [['money_account' => $this->petty, 'amount' => '10000']],
    ]);

    expect($quick->bill->outstanding()->minor)->toBe(500_000)
        ->and(($this->balance)())->toBe(-500_000)
        ->and(EmployeeCash::position($this->petty)['position'])->toBe('owed');

    // Paying him back is a transfer into his account.
    ($this->fund)('5000');

    expect(($this->balance)())->toBe(0)
        ->and(EmployeeCash::position($this->petty)['position'])->toBe('even');

    ($this->booksTie)();
});

it('pays an expense voucher and a supplier payment from his cash, below zero', function () {
    $expense = Account::query()->where('type', 'expense')->where('is_postable', true)->where('is_active', true)->orderBy('code')->first();

    app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->petty, spentOn: '2026-10-17',
        lines: [['account' => $expense, 'amount' => '350', 'description' => 'Labour for unloading']],
        createdBy: $this->w->owner, paidTo: 'Labour sardar',
    );

    $bill = $this->d->bill($this->d->order([[$this->d->sugar, '10', '10.00']]), null, 'INV-E');
    app(RecordSupplierPayment::class)->handle($this->d->supplier, $this->petty, '2026-10-17', [['bill' => $bill, 'amount' => '100']], $this->w->owner);

    expect(($this->balance)())->toBe(-45_000);

    ($this->booksTie)();
});

it('an ordinary cash box still cannot go below zero', function () {
    expect(fn () => ($this->buy)('100', ['money_account' => $this->w->cash, 'amount' => '2000']))
        ->toThrow(ValidationException::class);
});

it('is created on Bank & cash with its holder; the holder is required', function () {
    $this->w->owner->givePermissionTo('accounting.money_account.manage');
    $as = $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id]);

    $as->post('/accounting/money-accounts', ['name' => 'Petty Cash - Sharif', 'type' => 'employee_cash', 'is_active' => true])
        ->assertSessionHasErrors('custodian_employee_id');

    $as->post('/accounting/money-accounts', [
        'name' => 'Petty Cash - Sharif', 'type' => 'employee_cash', 'is_active' => true, 'custodian_employee_id' => $this->sifat->id,
    ])->assertSessionHasNoErrors();

    $account = MoneyAccount::query()->where('name', 'Petty Cash - Sharif')->sole();

    expect((int) $account->custodian_employee_id)->toBe($this->sifat->id)
        ->and($account->account_id)->not->toBeNull();

    ($this->fund)('2500');

    $as->get('/accounting/money-accounts')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('accounts', fn ($rows) => collect($rows)->contains(
            fn ($row) => $row['name'] === 'Petty Cash - Sifat' && $row['position'] === 'holds' && $row['custodian'] === 'Sifat Hossain (BDG-000071)')));
});

it('another kind of account never keeps a holder', function () {
    $this->w->owner->givePermissionTo('accounting.money_account.manage');

    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])->post('/accounting/money-accounts', [
        'name' => 'Front till', 'type' => 'cash', 'is_active' => true, 'custodian_employee_id' => $this->sifat->id,
    ])->assertSessionHasNoErrors();

    expect(MoneyAccount::query()->where('name', 'Front till')->sole()->custodian_employee_id)->toBeNull();
});

it('shows on his employee page what he holds and its movements', function () {
    ($this->fund)('10000');
    ($this->buy)('300', ['money_account' => $this->petty, 'amount' => '6000']);

    // What the employee page (EmployeeController::show, employee_cash) lists.
    $cash = EmployeeCash::forEmployee($this->sifat);

    expect($cash)->toHaveCount(1)
        ->and($cash[0]['name'])->toBe('Petty Cash - Sifat')
        ->and($cash[0]['position'])->toBe('holds')
        ->and($cash[0]['position_amount'])->toBe('4,000.00')
        ->and($cash[0]['lines'])->toHaveCount(2)
        ->and($cash[0]['lines'][0]['is_in'])->toBeFalse();
});

it('is offered in the purchase payment pickers', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->get('/purchasing/payments/new')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('moneyAccounts', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $this->petty->id)));
});
