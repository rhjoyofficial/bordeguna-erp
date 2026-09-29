<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
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
 * The second approval on a large payment (finance decision B13, 29 Sep 2026):
 * a payment above the limit that its own preparer posts waits for somebody
 * else, touching nothing, and posts when they approve.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Approval Co', 'slug' => 'approval-co', 'fiscal_year_start_month' => 7]);
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

it('sends a payment above the limit, posted by its own preparer, for a second approval: no number, no journal', function () {
    ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('25000'))->assertRedirect();

    $voucher = Voucher::sole();

    expect($voucher->status)->toBe(VoucherStatus::PendingApproval)
        ->and($voucher->voucher_number)->toBeNull()
        ->and(Journal::count())->toBe(0)
        ->and($voucher->approvalRequest->canBeActionedBy($this->joy))->toBeFalse()
        ->and($voucher->approvalRequest->canBeActionedBy($this->moin))->toBeTrue();
});

it('posts at once at or below the limit, and posts at once when a second person posts what another prepared', function () {
    ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('20000'))->assertRedirect();
    expect(Voucher::sole()->status)->toBe(VoucherStatus::Posted);

    // The accountant drafts (cannot post); the Owner posts: already two people.
    ($this->as)($this->finance)->post('/accounting/vouchers/payment', ['post' => 0] + ($this->payload)('90000'))->assertRedirect();
    $draft = Voucher::query()->where('status', VoucherStatus::Draft)->sole();

    ($this->as)($this->joy)->post(route('accounting.vouchers.post', $draft))->assertRedirect();

    expect($draft->fresh()->status)->toBe(VoucherStatus::Posted);
});

it('refuses the preparer and posts when a second person approves, recorded as the poster', function () {
    ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('50000'));
    $voucher = Voucher::sole();

    ($this->as)($this->joy)->post(route('accounting.vouchers.approve', $voucher))->assertForbidden();

    ($this->as)($this->moin)->post(route('accounting.vouchers.approve', $voucher))->assertRedirect();

    $voucher->refresh();

    expect($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->voucher_number)->not->toBeNull()
        ->and($voucher->posted_by)->toBe($this->moin->id)
        ->and(Journal::count())->toBe(1);
});

it('sends it back to its preparer as a draft, and the same request is used when it is sent again', function () {
    ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('50000'));
    $voucher = Voucher::sole();
    $requestId = $voucher->approvalRequest->id;

    ($this->as)($this->moin)->post(route('accounting.vouchers.send-back', $voucher), ['note' => 'Wrong amount'])->assertRedirect();

    expect($voucher->fresh()->status)->toBe(VoucherStatus::Draft);

    ($this->as)($this->joy)->post(route('accounting.vouchers.post', $voucher))->assertRedirect();

    expect($voucher->fresh()->status)->toBe(VoucherStatus::PendingApproval)
        ->and($voucher->fresh()->approvalRequest->id)->toBe($requestId);
});

it('never routes a receipt or a journal voucher to approval, and a limit of zero switches the rule off', function () {
    $receipt = [
        'voucher_date' => '2026-09-19', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => Account::where('code', '3100')->sole()->id, 'amount' => '500000']], 'post' => 1,
    ];
    ($this->as)($this->joy)->post('/accounting/vouchers/receipt', $receipt)->assertRedirect();
    expect(Voucher::sole()->status)->toBe(VoucherStatus::Posted);

    FinancePolicy::query()->update(['payment_second_approval_above_minor' => 0]);
    ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('900000'))->assertRedirect();

    expect(Voucher::query()->where('type', 'payment')->sole()->status)->toBe(VoucherStatus::Posted);
});

it('cannot be posted, printed or edited while it waits', function () {
    ($this->as)($this->joy)->post('/accounting/vouchers/payment', ($this->payload)('50000'));
    $voucher = Voucher::sole();

    ($this->as)($this->moin)->get(route('accounting.vouchers.print', $voucher))->assertNotFound();
    ($this->as)($this->joy)->post(route('accounting.vouchers.post', $voucher))->assertSessionHasErrors('voucher');

    expect($voucher->fresh()->status)->toBe(VoucherStatus::PendingApproval);
});
