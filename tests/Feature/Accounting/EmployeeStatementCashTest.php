<?php

use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Support\EmployeeStatement;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Initial plan B3 follow-up (25 Oct 2026, R63): the employee statement shows
 * his Employee cash account beside his other ledgers: the cash given to him,
 * what he paid from it, and the balance (holds, or the company owes him).
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;

    $this->sifat = Employee::create([
        'employee_code' => 'BDG-000071', 'first_name' => 'Sifat', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $this->other = Employee::create([
        'employee_code' => 'BDG-000072', 'first_name' => 'Sharif', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    $this->petty = MoneyAccount::create([
        'business_id' => $this->w->business->id, 'name' => 'Petty Cash - Sifat',
        'type' => MoneyAccountType::EmployeeCash, 'custodian_employee_id' => $this->sifat->id,
    ]);
    MoneyLedgers::ensureOwnLedger($this->petty);
    $this->petty->refresh();

    $this->w->record('100000', '2026-10-15', into: $this->w->bank);

    app(RecordMoneyTransfer::class)->handle(
        from: $this->w->bank, to: $this->petty, amount: '10000', transferredOn: '2026-10-16', createdBy: $this->w->owner,
    );

    // He pays 20,000 of salt from a 10,000 float: the company owes him 10,000.
    app(RecordQuickPurchase::class)->handle(
        requestKey: (string) Str::uuid(),
        supplier: $this->d->supplier,
        warehouse: $this->d->warehouse,
        purchasedOn: '2026-10-17',
        lines: [['product' => $this->d->salt, 'quantity' => '1000', 'unit_cost' => '20.00']],
        billNumber: 'CM-'.Str::random(6),
        by: $this->w->owner,
        payment: ['money_account' => $this->petty, 'amount' => '20000'],
    );
});

afterEach(fn () => Carbon::setTestNow());

it('adds his Employee cash account as a ledger: cash given, cash spent, and the company owes him', function () {
    $statement = EmployeeStatement::for($this->sifat, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    $cash = $statement->heads->firstWhere(fn (array $head) => isset($head['money_account']));

    expect($cash)->not->toBeNull()
        ->and($cash['money_account']->id)->toBe($this->petty->id)
        ->and($cash['debits']->minor)->toBe(1_000_000)
        ->and($cash['credits']->minor)->toBe(2_000_000)
        ->and($cash['closing']->minor)->toBe(-1_000_000)
        ->and($cash['lines'])->toHaveCount(2);

    // Nobody else's statement carries it.
    $other = EmployeeStatement::for($this->other, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($other->heads->filter(fn (array $head) => isset($head['money_account'])))->toHaveCount(0);
});

it('shows it on the employee statement screen with its own kind', function () {
    $this->actingAs($this->w->owner)
        ->get("/accounting/employee-statement?employee={$this->sifat->id}&from=2026-10-01&to=2026-10-31")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/employee-statement/index')
            ->where('heads', fn ($heads) => collect($heads)->contains(fn ($head) => $head['kind'] === 'employee_cash'
                && $head['slug'] === "employee_cash_{$this->petty->id}"
                && $head['closing'] === '10,000.00'
                && $head['closing_side'] === 'credit'))
            ->etc());
});
