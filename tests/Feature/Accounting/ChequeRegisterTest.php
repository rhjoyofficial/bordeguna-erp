<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The cheque register (finance decision C5, 29 Sep 2026): a cheque on a posted
 * voucher is pending until marked; bounced or cancelled reverses the voucher.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Cheque Co', 'slug' => 'cheque-co', 'fiscal_year_start_month' => 7]);
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);

    foreach (['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->make = function (string $roleName, array $permissions, string $name) {
        $role = Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $all = ['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post'];
    $this->joy = ($this->make)('Owner', $all, 'Joy');
    $this->moin = ($this->make)('Owner', $all, 'Moin');
    $this->finance = ($this->make)('Finance', ['accounting.view', 'accounting.voucher.prepare'], 'Accountant');

    (new VoucherWorkflowSeeder)->run();

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->payload = fn (string $amount) => [
        'voucher_date' => '2026-09-19', 'money_account_id' => $this->bank->id, 'narration' => 'Being rent',
        'lines' => [['account_id' => Account::where('code', '6051')->sole()->id, 'amount' => $amount]],
        'post' => 1,
    ];

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
});


afterEach(fn () => Carbon::setTestNow());

it('lists a cheque as pending, clears one, and a bounce reverses its voucher', function () {
    foreach (['CHQ-1001', 'CHQ-1002'] as $number) {
        ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('1000') + [
            'instrument_type' => 'cheque', 'instrument_number' => $number, 'instrument_date' => '2026-09-19',
        ])->assertRedirect();
    }
    [$first, $second] = Voucher::query()->orderBy('id')->get()->all();
    expect($first->status)->toBe(VoucherStatus::Posted);

    ($this->as)($this->joy)->get('/accounting/cheques')->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/cheques/index')
            ->has('cheques', 2)->where('cheques.0.status', 'pending'));

    ($this->as)($this->joy)->post("/accounting/cheques/{$first->id}", ['status' => 'cleared', 'status_on' => '2026-09-19'])
        ->assertSessionHasNoErrors();
    ($this->as)($this->joy)->post("/accounting/cheques/{$second->id}", ['status' => 'bounced', 'status_on' => '2026-09-19', 'note' => 'Insufficient funds'])
        ->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe(VoucherStatus::Posted)
        ->and($second->fresh()->status)->toBe(VoucherStatus::Reversed)
        ->and(ChequeStatus::count())->toBe(2);

    // Final: a second change is refused, and a preparer cannot change one.
    ($this->as)($this->joy)->post("/accounting/cheques/{$first->id}", ['status' => 'bounced', 'status_on' => '2026-09-19'])
        ->assertSessionHasErrors('status');
    ($this->as)($this->finance)->post("/accounting/cheques/{$first->id}", ['status' => 'bounced', 'status_on' => '2026-09-19'])
        ->assertForbidden();
});
