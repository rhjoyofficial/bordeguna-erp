<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Vouchers list (redesign round 3, 16 Oct 2026): the status chips count inside
 * the type, dates and search, and a voucher page lights its type tab. Kept
 * when the Accounts Overview page went back to the earlier finance dashboard
 * (Joy, 17 Oct 2026).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 10:00:00');

    $this->business = Business::create(['name' => 'Overview Co', 'slug' => 'overview-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Elsewhere Co', 'slug' => 'elsewhere-co']);

    foreach ([$this->other, $this->business] as $business) {
        foreach (['Owner', 'Finance', 'Payroll', 'Manager', 'Staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'business_id' => $business->id, 'guard_name' => 'web']);
        }
    }

    TenantScope::enter($this->business->id);
    $this->seed(AccountingPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->as = function (string $role): User {
        $user = User::factory()->create(['name' => $role.' person']);
        $user->businesses()->attach($this->business->id);
        $user->assignRole(Role::where('name', $role)->where('business_id', $this->business->id)->firstOrFail());

        return $user;
    };

    $this->owner = ($this->as)('Owner');
    $this->rent = Account::where('code', '6051')->sole();
    $this->bankHead = Account::bySlug('cash_at_bank');

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => $this->bankHead->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A voucher written straight to the table: these tests are about reading
 * vouchers, and the engine that writes them is tested on its own.
 *
 * @param  list<array{0: int, 1: string, 2: int}>  $lines  [account id, side, paisa]
 */
function aovVoucher(int $businessId, int $userId, array $attributes = [], array $lines = []): int
{
    $id = DB::table('vouchers')->insertGetId($attributes + [
        'business_id' => $businessId,
        'type' => 'payment',
        'status' => 'draft',
        'voucher_date' => '2026-10-10',
        'total_minor' => 0,
        'created_by' => $userId,
        'created_at' => '2026-10-10 09:00:00',
        'updated_at' => '2026-10-10 09:00:00',
    ]);

    foreach ($lines as $index => [$accountId, $side, $minor]) {
        DB::table('voucher_lines')->insert([
            'voucher_id' => $id, 'business_id' => $businessId, 'line_no' => $index + 1,
            'account_id' => $accountId, 'side' => $side, 'amount_minor' => $minor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

it('counts the vouchers list by status for the chips, inside the type, the dates and the search', function () {
    $u = $this->owner->id;
    $b = $this->business->id;

    aovVoucher($b, $u, ['status' => 'draft', 'counterparty' => 'Landlord']);
    aovVoucher($b, $u, ['status' => 'draft', 'counterparty' => 'Grocer']);
    aovVoucher($b, $u, ['status' => 'posted', 'voucher_number' => 'PV-1', 'counterparty' => 'Landlord']);
    aovVoucher($b, $u, ['status' => 'posted', 'voucher_number' => 'PV-2', 'counterparty' => 'Landlord', 'voucher_date' => '2026-08-01']);
    aovVoucher($b, $u, ['type' => 'receipt', 'status' => 'draft', 'counterparty' => 'Landlord']);
    aovVoucher($this->other->id, User::factory()->create()->id, ['status' => 'draft', 'counterparty' => 'Landlord']);

    $this->actingAs($this->owner)->get('/accounting/vouchers/payment?status=draft')
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusCounts', ['all' => 4, 'draft' => 2, 'pending_approval' => 0, 'posted' => 2, 'reversed' => 0])
            ->where('vouchers.total', 2));

    $this->actingAs($this->owner)->get('/accounting/vouchers/payment?search=Landlord&from=2026-10-01')
        ->assertInertia(fn (Assert $page) => $page->where('statusCounts', ['all' => 2, 'draft' => 1, 'pending_approval' => 0, 'posted' => 1, 'reversed' => 0]));
});

it('lights the voucher type tab on a voucher page, which its address cannot', function () {
    $receipt = aovVoucher($this->business->id, $this->owner->id, ['type' => 'receipt', 'status' => 'draft']);
    $journal = aovVoucher($this->business->id, $this->owner->id, ['type' => 'journal', 'status' => 'draft']);

    $this->actingAs($this->owner)->get("/accounting/vouchers/doc/{$receipt}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sectionTab', '/accounting/vouchers/receipt'));

    $this->actingAs($this->owner)->get("/accounting/vouchers/doc/{$journal}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sectionTab', '/accounting/vouchers/journal'));
});
