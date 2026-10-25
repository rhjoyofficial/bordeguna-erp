<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\EmployeeCash;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * IP21 (25 Oct 2026): an Employee cash account ("Petty Cash - Sifat", Joy's
 * Q1) reaches the Payment, Receipt and Contra vouchers. The server already
 * sent it under "Cash with employees", but the form's group list did not name
 * that group, so the account never showed and Joy could not fund it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');

    $this->business = Business::create(['name' => 'Voucher Co', 'slug' => 'voucher-co-ecash', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    // The guards on, as in production.
    config(['accounting.money_availability' => true]);
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => true, 'payment_second_approval_above_minor' => 0]);
    $this->seed(ChartOfAccountsSeeder::class);

    foreach (['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post']);
    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($role);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $sifat = Employee::create([
        'employee_code' => 'BDG-000071', 'first_name' => 'Sifat', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $this->petty = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Petty Cash - Sifat',
        'type' => MoneyAccountType::EmployeeCash, 'custodian_employee_id' => $sifat->id,
    ]);
    MoneyLedgers::ensureOwnLedger($this->petty);
    $this->petty->refresh();

    $this->draft = fn (VoucherType $type, array $data) => app(SaveVoucherDraft::class)
        ->handle($type, $data + ['voucher_date' => '2026-10-20'], $this->owner);
    $this->post = fn (Voucher $voucher) => app(PostVoucher::class)->handle($voucher, $this->owner);

    // Tk 50,000 in the bank, from the owner's capital.
    ($this->post)(($this->draft)(VoucherType::Receipt, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => Account::where('code', '3100')->sole()->id, 'amount' => '50000']],
    ]));
});

afterEach(fn () => Carbon::setTestNow());

it('offers the employee cash account on Payment, Receipt and Contra, under Cash with employees', function () {
    foreach (['payment', 'receipt', 'contra'] as $type) {
        $accounts = collect($this->actingAs($this->owner)->get("/accounting/vouchers/{$type}/create")
            ->assertOk()->original->getData()['page']['props']['moneyAccounts']);

        $petty = $accounts->firstWhere('id', $this->petty->id);

        expect($petty)->not->toBeNull()
            ->and($petty['group'])->toBe('Cash with employees')
            ->and($petty['hint'])->toBeNull();
    }
});

it('names every group the server sends in the form, so no account is silently left out', function () {
    $form = file_get_contents(resource_path('js/pages/accounting/vouchers/form.tsx'));
    preg_match('/const MONEY_GROUPS = \[(.*?)\];/s', $form, $match);
    $groups = collect(explode(',', $match[1]))->map(fn (string $g) => trim($g, " '\n\r\t"))->filter()->values();

    $controller = file_get_contents(app_path('Modules/Accounting/Http/Controllers/VoucherController.php'));
    preg_match_all("/MoneyAccountType::\\w+ => '([^']+)'/", $controller, $sent);
    preg_match("/default => '(Courier accounts)'/", $controller, $default);

    expect($sent[1])->toContain('Cash with employees');

    foreach ([...$sent[1], $default[1]] as $group) {
        expect($groups->all())->toContain($group);
    }

    // Right after the office cash.
    expect($groups->search('Cash with employees'))->toBe($groups->search('Cash in hand') + 1);
});

it('funds him by a Contra from the bank, then a payment may take him below zero', function () {
    $contra = ($this->post)(($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->bank->id,
        'to_money_account_id' => $this->petty->id,
        'amount' => '10000',
        'narration' => 'Float for Sifat',
    ]));

    expect($contra->journal->balance()->isZero())->toBeTrue()
        ->and(MoneyAvailability::balanceMinor($this->petty))->toBe(1_000_000)
        ->and(MoneyAvailability::balanceMinor($this->bank))->toBe(4_000_000);

    // He pays Tk 15,000 of rent from a Tk 10,000 float: the company owes him 5,000.
    ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->petty->id,
        'lines' => [['account_id' => Account::where('code', '6051')->sole()->id, 'amount' => '15000']],
    ]));

    expect(MoneyAvailability::balanceMinor($this->petty))->toBe(-500_000)
        ->and(EmployeeCash::position($this->petty))->toBe(['position' => 'owed', 'position_amount' => '5,000.00']);

    // Paying him back is a Contra INTO his account, out of the bank.
    ($this->post)(($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->bank->id, 'to_money_account_id' => $this->petty->id, 'amount' => '5000',
    ]));

    expect(MoneyAvailability::balanceMinor($this->petty))->toBe(0);
});

it('takes cash back from him by a Contra out of his account, and a Receipt may land in it', function () {
    ($this->post)(($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->bank->id, 'to_money_account_id' => $this->petty->id, 'amount' => '8000',
    ]));

    // He hands Tk 3,000 back to the bank.
    ($this->post)(($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->petty->id, 'to_money_account_id' => $this->bank->id, 'amount' => '3000',
    ]));

    // He collects Tk 1,000 of other income in cash.
    ($this->post)(($this->draft)(VoucherType::Receipt, [
        'money_account_id' => $this->petty->id,
        'lines' => [['account_id' => Account::where('code', '3100')->sole()->id, 'amount' => '1000']],
    ]));

    expect(MoneyAvailability::balanceMinor($this->petty))->toBe(600_000)
        ->and(MoneyAvailability::balanceMinor($this->bank))->toBe(4_500_000);
});
