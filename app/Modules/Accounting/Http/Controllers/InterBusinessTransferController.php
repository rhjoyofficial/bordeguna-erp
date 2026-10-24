<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordInterBusinessTransfer;
use App\Modules\Accounting\Corrections\InterBusinessTransferActionPolicy;
use App\Modules\Accounting\Corrections\InterBusinessTransferDuplicateRule;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Support\CorrectionScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance & Accounts > Inter-business transfers (finance decision C2): money
 * moved to another business of the group, booked in both.
 */
class InterBusinessTransferController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = (int) app(BusinessContext::class)->id();
        $policy = app(InterBusinessTransferActionPolicy::class);
        $user = $request->user();
        $memberOf = $request->user()->businesses()->pluck('businesses.id')->all();

        $accounts = fn ($query) => $query->where('is_active', true)->whereNotNull('account_id')
            ->whereNotIn('type', [MoneyAccountType::Courier->value, MoneyAccountType::Gateway->value])
            ->with('business:id,name')->orderBy('name')->get()
            ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'label' => $a->label(), 'business' => $a->business?->name]);

        return Inertia::render('accounting/inter-business/index', [
            'fromAccounts' => $accounts(MoneyAccount::query()),
            'toAccounts' => $accounts(MoneyAccount::withoutGlobalScopes()->whereIn('business_id', $memberOf)->where('business_id', '!=', $businessId)),
            'transfers' => InterBusinessTransfer::query()->involving($businessId)
                ->with(['fromBusiness:id,name', 'toBusiness:id,name', 'fromAccount', 'toAccount', 'createdBy:id,name'])
                ->latest('transferred_on')->latest('id')->limit(100)->get()
                ->map(fn (InterBusinessTransfer $t) => [
                    'id' => $t->id,
                    'date' => $t->transferred_on?->toDateString(),
                    'direction' => $t->from_business_id === $businessId ? 'out' : 'in',
                    'from' => "{$t->fromBusiness?->name} · {$t->fromAccount?->name}",
                    'to' => "{$t->toBusiness?->name} · {$t->toAccount?->name}",
                    'amount' => $t->money()->format(),
                    'note' => $t->note,
                    'by' => $t->createdBy?->name,
                    // ⭐ Corrections wave 2 (24 Oct 2026): Reverse in both
                    // businesses at once, then Copy (the form, filled in).
                    'number' => $policy->numberOf($t),
                    'from_money_account_id' => $t->from_money_account_id,
                    'to_money_account_id' => $t->to_money_account_id,
                    'amount_value' => $t->money()->toTaka(),
                    'reversal' => $t->reversalSummary(),
                    'allow_today' => ! AccountingPeriod::isClosedOn($t->transferred_on->copy()->startOfDay()),
                    'corrections' => $t->from_business_id === $businessId ? CorrectionScreen::for($t, $policy, $user) : null,
                ]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordInterBusinessTransfer $record): RedirectResponse
    {
        $data = $request->validate([
            'from_money_account_id' => ['required', 'integer'],
            'to_money_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'transferred_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'duplicate_ack' => ['nullable', 'string', 'max:2000'],
        ]);

        $from = MoneyAccount::query()->findOrFail($data['from_money_account_id']);
        $to = MoneyAccount::withoutGlobalScopes()->findOrFail($data['to_money_account_id']);
        $guard = app(DuplicateGuard::class);

        // ⭐ Corrections wave 2 (24 Oct 2026; FINAL-PLAN 5): "Possible duplicate".
        try {
            DB::transaction(function () use ($data, $request, $record, $from, $to, $guard) {
                $ack = $guard->check(app(InterBusinessTransferDuplicateRule::class), $data, $request->user(), (int) app(BusinessContext::class)->id(), $data['duplicate_ack'] ?? null);
                $transfer = $record->handle($from, $to, (string) $data['amount'], $data['transferred_on'], $data['note'] ?? null, $request->user());

                if ($ack !== null) {
                    $guard->record($ack, $transfer);
                }
            });
        } catch (PossibleDuplicateException $e) {
            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different transfer?']);
        }

        return back()->with('success', 'Transfer booked in both businesses.');
    }
}
