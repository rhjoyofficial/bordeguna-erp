<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Corrections\ExpenseVoucherActionPolicy;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CorrectionWorld;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): an expense voucher's Reverse
 * runs through the corrections engine (preview, reason, History), posting the
 * same mirror journal as before.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    (new PurchasingPermissionsSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->w->enter($this->w->business);

    $this->rent = Account::where('code', '6051')->sole();
    $this->spend = fn (string $on = '2026-10-15', string $amount = '1800.00') => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->w->cash, spentOn: $on,
        lines: [['account' => $this->rent, 'amount' => $amount, 'description' => 'October rent']],
        createdBy: $this->w->owner, paidTo: 'Landlord',
    );
    $this->input = fn (ExpenseVoucher $voucher) => new CorrectionInput(
        ExpenseVoucherActionPolicy::TYPE, (int) $voucher->id, DocumentAction::Reverse, new ActionReason(null, 'Paid the landlord twice'), null, ReverseDateChoice::OriginalDate, (string) Str::uuid(),
    );
    $this->onRent = fn () => (int) JournalLine::where('account_id', $this->rent->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('reverses an expense voucher through the engine: the preview, the mirror journal, the History link', function () {
    $voucher = ($this->spend)();
    expect(($this->onRent)())->toBe(180000);

    $service = app(CorrectionService::class);
    $input = ($this->input)($voucher);
    $preview = $service->preview($input, $this->w->manager);

    expect($preview->blockers)->toBe([])
        ->and($preview->dates->reversalDate->toDateString())->toBe('2026-10-15')
        ->and(collect($preview->effects)->pluck('sentence')->implode(' '))->toContain('goes back into Cash');

    expect($service->submit($input, $this->w->manager, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::Executed);

    $voucher->refresh();
    expect($voucher->isReversed())->toBeTrue()
        ->and($voucher->reversal_reason)->toBe('Paid the landlord twice')
        ->and($voucher->reversed_on->toDateString())->toBe('2026-10-15')
        ->and(($this->onRent)())->toBe(0)
        ->and(CorrectionLink::count())->toBe(1);

    expect($service->submit($input, $this->w->manager, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::AlreadyDone);
});

it('asks the Owner in a closed month and the second person above Tk 20,000', function () {
    $old = ($this->spend)('2026-09-20');
    $big = ($this->spend)('2026-10-15', '25000.00');
    $this->w->closeThrough('2026-09-30');
    $policy = app(ExpenseVoucherActionPolicy::class);

    expect($policy->availability($old, $this->w->owner, DocumentAction::Reverse)->allowed)->toBeTrue()
        ->and($policy->availability($old, $this->w->manager, DocumentAction::Reverse)->allowed)->toBeFalse()
        ->and($policy->availability($big, $this->w->manager, DocumentAction::Reverse)->approval->value)->not->toBe('none');
});

it('shows Reverse on the expenses screen to who may record expenses', function () {
    $voucher = ($this->spend)();

    expect(app(ExpenseVoucherActionPolicy::class)->actionsFor($voucher, $this->w->sales)->actions)->toBe([]);

    $this->actingAs($this->w->manager)->get(route('purchasing.expenses.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/expenses/index')
            ->where('vouchers.data.0.actions.0.action', 'reverse')
            ->has('reversalReasons'));
});
