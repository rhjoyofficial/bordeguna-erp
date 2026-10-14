<?php

use App\Models\User;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Http\Controllers\VoucherController;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ACC-003 (audit of 14 Oct 2026): the draft-only check on voucher edit and
 * delete ran on the route-bound model, outside any lock. A Post committed by
 * another user in that window let the edit rewrite a posted voucher's lines,
 * or the delete orphan its journal.
 *
 * SQLite has no row locks, so the race is simulated the only way it can be
 * here: the voucher turns `posted` in the database while the caller still
 * holds the model it read as a draft.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Race Co', 'slug' => 'race-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->user = User::factory()->create();
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->stale = app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-09-19', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => Account::where('code', '6051')->sole()->id, 'amount' => '100']],
    ], $this->user);

    // Another user's Post commits; this request still holds the draft it read.
    DB::table('vouchers')->where('id', $this->stale->id)->update(['status' => 'posted']);
});

afterEach(fn () => Carbon::setTestNow());

it('refuses to rewrite a voucher that was posted after the edit form was read', function () {
    expect(fn () => app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-09-19', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => Account::where('code', '6052')->sole()->id, 'amount' => '999']],
    ], $this->user, $this->stale))->toThrow(ValidationException::class);

    $fresh = Voucher::findOrFail($this->stale->id)->load('lines');

    expect($fresh->total_minor)->toBe(10000)
        ->and($fresh->lines)->toHaveCount(1)
        ->and($fresh->lines->first()->account_id)->toBe(Account::where('code', '6051')->sole()->id);
});

it('refuses to delete a voucher that was posted after the delete was pressed', function () {
    $this->actingAs($this->user);

    app(VoucherController::class)->destroy($this->stale);

    expect(Voucher::find($this->stale->id))->not->toBeNull()
        ->and(session('error'))->toContain('not deleted');
});
