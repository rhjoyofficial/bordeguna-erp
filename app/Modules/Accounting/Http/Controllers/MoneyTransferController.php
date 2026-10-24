<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Corrections\MoneyTransferActionPolicy;
use App\Modules\Accounting\Corrections\MoneyTransferDuplicateRule;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
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
 * Moving money between the business's own accounts.
 *
 * ⚠ Every rule lives in `RecordMoneyTransfer`. This validates SHAPE and hands
 * over, so there is exactly one place that decides what a transfer may be.
 */
class MoneyTransferController extends Controller
{
    public function index(Request $request): Response
    {
        // ⭐ Corrections wave 2 (24 Oct 2026): reversed and corrected transfers
        // are hidden until "Show corrections" is ticked; each row carries the
        // buttons its policy draws (Correct, Reverse), greyed with the reason.
        $showCorrections = $request->boolean('show_corrections');
        $policy = app(MoneyTransferActionPolicy::class);
        $user = $request->user();

        $transfers = MoneyTransfer::with(['from:id,name', 'to:id,name', 'feeAccount:id,code,name', 'reversedBy:id,name'])
            ->when(! $showCorrections, fn ($query) => $query->live())
            ->orderByDesc('transferred_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (MoneyTransfer $t) => [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'transferred_on' => $t->transferred_on?->toDateString(),
                'from' => $t->from?->name,
                'to' => $t->to?->name,
                'amount' => $t->amount,
                'fee' => $t->fee,
                'total_withdrawn' => $t->totalWithdrawn(),
                'fee_account' => $t->feeAccount?->label(),
                'reference' => $t->reference,
                'note' => $t->note,
                'from_money_account_id' => $t->from_money_account_id,
                'to_money_account_id' => $t->to_money_account_id,
                'fee_account_id' => $t->fee_account_id,
                'reversal' => $t->reversalSummary(),
                'allow_today' => ! AccountingPeriod::isClosedOn($t->transferred_on->copy()->startOfDay()),
                'corrections' => CorrectionScreen::for($t, $policy, $user),
            ]);

        return Inertia::render('accounting/money-transfers/index', [
            'transfers' => $transfers,
            'showCorrections' => $showCorrections,
            'today' => now()->toDateString(),
            // ⚠ Only accounts the action will accept. An account with no chart
            // mapping cannot be debited or credited, so offering it would be a
            // form that fails on submit for a reason nobody can see.
            'accounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            'fee_accounts' => Account::where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label()])
                ->values(),
        ]);
    }

    public function store(Request $request, RecordMoneyTransfer $action): RedirectResponse
    {
        $data = $request->validate([
            'from_money_account_id' => ['required', 'integer'],
            'to_money_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transferred_on' => ['required', 'date'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'fee_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'duplicate_ack' => ['nullable', 'string', 'max:2000'],
        ]);

        // ⭐ Corrections wave 2 (24 Oct 2026; FINAL-PLAN 5): "Possible
        // duplicate" (same accounts, same amount, within 3 days) is asked once
        // per payload; "Save anyway" is kept beside the transfer.
        $guard = app(DuplicateGuard::class);

        try {
            $transfer = DB::transaction(function () use ($data, $request, $action, $guard) {
                $ack = $guard->check(app(MoneyTransferDuplicateRule::class), $data, $request->user(), (int) app(BusinessContext::class)->id(), $data['duplicate_ack'] ?? null);
                $transfer = $this->record($data, $request, $action);

                if ($ack !== null) {
                    $guard->record($ack, $transfer);
                }

                return $transfer;
            });
        } catch (PossibleDuplicateException $e) {
            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different transfer?']);
        }

        return back()->with('success', "Recorded transfer {$transfer->transfer_number}.");
    }

    private function record(array $data, Request $request, RecordMoneyTransfer $action): MoneyTransfer
    {
        // findOrFail runs inside BusinessScope, so an id from another tenant is
        // a 404 here and never reaches the action's ownership check.
        return $action->handle(
            from: MoneyAccount::findOrFail($data['from_money_account_id']),
            to: MoneyAccount::findOrFail($data['to_money_account_id']),
            amount: (string) $data['amount'],
            transferredOn: $data['transferred_on'],
            createdBy: $request->user(),
            fee: (string) ($data['fee'] ?? '0'),
            feeAccount: empty($data['fee_account_id']) ? null : Account::findOrFail($data['fee_account_id']),
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
        );
    }
}
