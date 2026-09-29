<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordInterBusinessTransfer;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Money from one business of the group to another (finance decision C2,
 * 29 Sep 2026): one document, a journal in EACH business, cancelling across
 * the group.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-29 10:00:00');

    $this->user = User::factory()->create();
    $setUp = function (string $name) {
        $business = Business::create(['name' => $name, 'slug' => str($name)->slug(), 'fiscal_year_start_month' => 7]);
        TenantScope::enter($business->id);
        app(BusinessContext::class)->set($business->id);
        (new ChartOfAccountsSeeder)->run();
        $this->user->businesses()->attach($business->id);

        return [$business, MoneyAccount::create([
            'business_id' => $business->id, 'name' => "{$name} bank", 'type' => 'bank',
            'account_id' => Account::bySlug('cash_at_bank')->id,
        ])];
    };

    [$this->receiver, $this->receiverBank] = $setUp('Laladia');
    [$this->sender, $this->senderBank] = $setUp('Bor de Guna');

    $this->lines = fn (int $businessId, string $slug) => (int) DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_lines.business_id', $businessId)->where('accounts.system_slug', $slug)
        ->sum('journal_lines.amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('books the transfer in both businesses and the due-from and due-to cancel', function () {
    $transfer = app(RecordInterBusinessTransfer::class)
        ->handle($this->senderBank, $this->receiverBank, '25000', '2026-09-29', 'Stock money', $this->user);

    expect($transfer->to_business_id)->toBe($this->receiver->id)
        ->and(($this->lines)($this->sender->id, 'due_from_businesses'))->toBe(2_500_000)
        ->and(($this->lines)($this->sender->id, 'cash_at_bank'))->toBe(-2_500_000)
        ->and(($this->lines)($this->receiver->id, 'cash_at_bank'))->toBe(2_500_000)
        ->and(($this->lines)($this->receiver->id, 'due_to_businesses'))->toBe(-2_500_000)
        ->and(app(BusinessContext::class)->id())->toBe($this->sender->id);
});

it('lists the transfer on both sides and books it from the screen', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->sender->id);
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.journal.post', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Poster', 'business_id' => $this->sender->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view', 'accounting.journal.post']);
    $this->user->assignRole('Poster');

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->sender->id])
        ->get(route('accounting.inter-business.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/inter-business/index')->has('toAccounts', 1));

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->sender->id])
        ->post(route('accounting.inter-business.store'), [
            'from_money_account_id' => $this->senderBank->id,
            'to_money_account_id' => $this->receiverBank->id,
            'amount' => '1000',
            'transferred_on' => '2026-09-29',
        ])->assertSessionHasNoErrors();

    expect(InterBusinessTransfer::query()->involving($this->receiver->id)->count())->toBe(1);
});

it('refuses a transfer inside one business, from another business, and in the future', function () {
    $ownSecond = MoneyAccount::create([
        'business_id' => $this->sender->id, 'name' => 'Till', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $record = app(RecordInterBusinessTransfer::class);

    expect(fn () => $record->handle($this->senderBank, $ownSecond, '10', '2026-09-29', null, $this->user))
        ->toThrow(ValidationException::class, 'Contra voucher')
        ->and(fn () => $record->handle($this->receiverBank, $this->senderBank, '10', '2026-09-29', null, $this->user))
        ->toThrow(ValidationException::class, 'own accounts')
        ->and(fn () => $record->handle($this->senderBank, $this->receiverBank, '10', '2026-10-05', null, $this->user))
        ->toThrow(ValidationException::class, 'future');

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->sender->id);
    expect(fn () => $record->handle($this->senderBank, $this->receiverBank, '10', '2026-09-29', null, $outsider))
        ->toThrow(ValidationException::class, 'do not work in the receiving business')
        ->and(InterBusinessTransfer::count())->toBe(0);
});
