<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $transfers = MoneyTransfer::with(['from:id,name', 'to:id,name', 'feeAccount:id,code,name'])
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
            ]);

        return Inertia::render('accounting/money-transfers/index', [
            'transfers' => $transfers,
            // ⚠ Only accounts the action will accept. An account with no chart
            // mapping cannot be debited or credited, so offering it would be a
            // form that fails on submit for a reason nobody can see.
            'accounts' => MoneyAccount::where('is_active', true)
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
        ]);

        // findOrFail runs inside BusinessScope, so an id from another tenant is
        // a 404 here and never reaches the action's ownership check.
        $transfer = $action->handle(
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

        return back()->with('success', "Recorded transfer {$transfer->transfer_number}.");
    }
}
