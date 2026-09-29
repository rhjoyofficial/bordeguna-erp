<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Finance policy (29 Sep 2026): the owner-editable limits, and the two guards
 * that read them on a voucher - no money account below its floor (B14), and
 * no cash payment above the Income Tax Act s.55 limit (B13).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Policy Co', 'slug' => 'policy-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->user = User::factory()->create();

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Office Cash', 'type' => 'cash', 'is_active' => true,
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->expense = Account::where('code', '6051')->sole()->id;

    $this->voucher = fn (VoucherType $type, array $data, string $date = '2026-09-19') => app(PostVoucher::class)->handle(
        app(SaveVoucherDraft::class)->handle($type, $data + ['voucher_date' => $date], $this->user),
        $this->user,
    );

    // Money arrives through a Receive voucher against owner capital.
    $this->fund = fn (MoneyAccount $into, string $amount, string $date = '2026-09-19') => ($this->voucher)(VoucherType::Receipt, [
        'money_account_id' => $into->id,
        'lines' => [['account_id' => Account::where('code', '3100')->value('id') ?? Account::where('type', 'equity')->where('is_postable', true)->value('id'), 'amount' => $amount]],
    ], $date);

    $this->pay = fn (MoneyAccount $from, string $amount, string $date = '2026-09-19') => ($this->voucher)(VoucherType::Payment, [
        'money_account_id' => $from->id,
        'lines' => [['account_id' => $this->expense, 'amount' => $amount]],
    ], $date);
});

afterEach(fn () => Carbon::setTestNow());

it('has the recommended defaults before anybody saves a policy', function () {
    $policy = FinancePolicy::for($this->business->id);

    expect($policy->exists)->toBeFalse()
        ->and($policy->refuse_negative_money)->toBeTrue()
        ->and($policy->payment_second_approval_above_minor)->toBe(2_000_000)
        ->and($policy->cash_payment_limit_minor)->toBe(5_000_000)
        ->and($policy->staff_purchase_cap_percent)->toBe(10)
        ->and($policy->bad_debt_full_after_days)->toBe(365);
});

it('refuses a cash payment the box cannot cover, and posts one it can', function () {
    ($this->fund)($this->cash, '37000');

    expect(fn () => ($this->pay)($this->cash, '37000.01'))
        ->toThrow(ValidationException::class, 'Office Cash holds 37,000.00, not enough to pay 37,000.01.');

    ($this->pay)($this->cash, '37000');

    expect(MoneyAvailability::balanceMinor($this->cash))->toBe(0);
});

it('lets a bank go below zero only as far as its overdraft', function () {
    ($this->fund)($this->bank, '1000');
    $this->bank->update(['overdraft_limit_minor' => 500_000]);

    ($this->pay)($this->bank, '6000');

    expect(MoneyAvailability::balanceMinor($this->bank))->toBe(-500_000)
        ->and(fn () => ($this->pay)($this->bank, '0.01'))->toThrow(ValidationException::class, 'plus its overdraft limit of 5,000.00');
});

it('judges the later of the document date and today, so back-dating cannot overdraw', function () {
    ($this->fund)($this->cash, '1000', '2026-09-10');
    ($this->pay)($this->cash, '1000', '2026-09-15');

    // On the 12th the box held 1,000, but the 15th has already spent it.
    expect(fn () => ($this->pay)($this->cash, '500', '2026-09-12'))->toThrow(ValidationException::class);
});

it('catches a dip between the document date and today, even when both ends look fine', function () {
    ($this->fund)($this->cash, '1000', '2026-09-10');
    ($this->pay)($this->cash, '1000', '2026-09-15');
    ($this->fund)($this->cash, '5000', '2026-09-18');

    // The 12th held 1,000 and today holds 5,000, but the 15th-17th held nothing.
    expect(fn () => ($this->pay)($this->cash, '500', '2026-09-12'))->toThrow(ValidationException::class, 'holds 0.00');

    ($this->pay)($this->cash, '500', '2026-09-18');
    expect(MoneyAvailability::balanceMinor($this->cash))->toBe(450_000);
});

it('refuses a contra that moves more than the source holds', function () {
    ($this->fund)($this->cash, '100');

    expect(fn () => ($this->voucher)(VoucherType::Contra, [
        'money_account_id' => $this->cash->id,
        'to_money_account_id' => $this->bank->id,
        'amount' => '150',
    ]))->toThrow(ValidationException::class, 'Office Cash holds 100.00');
});

it('lets the Owner switch the guard off', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    ($this->pay)($this->cash, '10');

    expect(MoneyAvailability::balanceMinor($this->cash))->toBe(-1000);
});

it('refuses a cash payment above the s.55 limit, but not the same amount from a bank', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    expect(fn () => ($this->pay)($this->cash, '50000.01'))->toThrow(ValidationException::class, 'Income Tax Act 2023, s.55');

    ($this->pay)($this->cash, '50000');
    ($this->pay)($this->bank, '60000');

    expect(Journal::count())->toBe(2);
});

it('saves the policy in taka and keeps it in paisa, for the policy key only', function () {
    Permission::firstOrCreate(['name' => ChartPermissions::POLICY, 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(ChartPermissions::POLICY);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Owner');

    $payload = [
        'refuse_negative_money' => true,
        'payment_second_approval_above' => '25000',
        'advance_refund_owner_above' => '20000',
        'cash_payment_limit' => '50000',
        'raw_material_cash_limit' => '500000',
        'petty_cash_float' => '15000.50',
        'capitalisation_threshold' => '10000',
        'staff_purchase_cap_percent' => 15,
        'bad_debt_half_after_days' => 180,
        'bad_debt_full_after_days' => 365,
        'courier_settlement_alert_days' => 10,
    ];

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id])
        ->put(route('accounting.finance-policy.update'), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $policy = FinancePolicy::for($this->business->id);
    expect($policy->payment_second_approval_above_minor)->toBe(2_500_000)
        ->and($policy->petty_cash_float_minor)->toBe(1_500_050)
        ->and($policy->staff_purchase_cap_percent)->toBe(15);

    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);

    $this->actingAs($clerk)->withSession(['current_business_id' => $this->business->id])
        ->get(route('accounting.finance-policy.edit'))->assertForbidden();
});

it('refuses a full provision earlier than the half one', function () {
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => ChartPermissions::POLICY, 'guard_name' => 'web']));
    $this->user->businesses()->attach($this->business->id);

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id])
        ->put(route('accounting.finance-policy.update'), [
            'refuse_negative_money' => true,
            'payment_second_approval_above' => '20000', 'advance_refund_owner_above' => '20000',
            'cash_payment_limit' => '50000', 'raw_material_cash_limit' => '500000',
            'petty_cash_float' => '10000', 'capitalisation_threshold' => '10000',
            'staff_purchase_cap_percent' => 10, 'bad_debt_half_after_days' => 365,
            'bad_debt_full_after_days' => 180, 'courier_settlement_alert_days' => 7,
        ])->assertSessionHasErrors('bad_debt_full_after_days');
});
