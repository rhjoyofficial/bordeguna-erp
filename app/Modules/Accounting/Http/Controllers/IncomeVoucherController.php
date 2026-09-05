<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money that came in and was not a sale.
 *
 * ⚠⚠ WHY THIS EXISTS. `4900 Other Income` was postable from the day the chart
 * was seeded and NO SCREEN COULD REACH IT. Scrap, bank interest, rent received,
 * a supplier refund: all of it had to go through a hand-written journal, on the
 * one screen the rest of this system exists to spare people. Found on 5
 * September while writing down how income is recorded, and it was the only
 * money path with no document behind it.
 *
 * ⚠ This adds no accounting behaviour. Every rule - the period lock, the
 * future-date refusal, income-accounts-only, the sales-account refusal, the
 * money account mapping, the numbering - lives in `RecordIncomeVoucher` and
 * stays there. This class collects a form, validates its SHAPE and ownership,
 * and hands it over.
 */
class IncomeVoucherController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        $vouchers = IncomeVoucher::query()
            ->with(['moneyAccount:id,name', 'customer:id,name', 'lines.account:id,code,name', 'attachments.uploadedBy:id,name'])
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $vouchers->getCollection()->transform(fn (IncomeVoucher $voucher) => [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            // ⚠ toDateString(), not the model's date cast. A `date` cast
            // serialises to JSON in UTC and Dhaka is UTC+6, so midnight local
            // becomes 18:00 the PREVIOUS day.
            'received_on' => $voucher->received_on?->toDateString(),
            'received_into' => $voucher->moneyAccount?->name,
            'payer' => $voucher->payer(),
            'total' => Money::ofTaka((string) $voucher->total)->toTaka(),
            'reference' => $voucher->reference,
            'note' => $voucher->note,
            'evidence' => $voucher->attachments->map(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->file_name,
                'uploaded_by' => $file->uploadedBy?->name,
                'url' => route('attachments.show', $file->id),
            ])->values()->all(),
            'lines' => $voucher->lines->map(fn ($line) => [
                'id' => $line->id,
                'account' => $line->account?->label(),
                'amount' => Money::ofTaka((string) $line->amount)->toTaka(),
                'description' => $line->description,
            ])->all(),
        ]);

        return Inertia::render('accounting/other-income/index', [
            'vouchers' => $vouchers,
            'canRecord' => $request->user()?->can('accounting.income.record') ?? false,

            // ⚠⚠ INCOME accounts, postable, and NOT the sales ones. The action
            // refuses sales revenue and its contra pair outright, so offering
            // them here would be a picker whose most obvious choice is an error.
            'incomeAccounts' => Account::query()
                ->where('type', AccountType::Income)
                ->where('is_postable', true)
                ->where('is_active', true)
                // ⚠⚠ THE NULL BRANCH IS NOT OPTIONAL. `system_slug NOT IN
                // (...)` evaluates to NULL for a row whose slug is NULL, and
                // NULL is not true, so a bare whereNotIn would have hidden
                // EVERY head that has no slug - which is all of them, including
                // the five this feature ships and anything the accountant adds.
                ->where(function ($query) {
                    $query->whereNull('system_slug')
                        ->orWhereNotIn('system_slug', RecordIncomeVoucher::SALES_ONLY);
                })
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->label(),
                ])
                ->all(),

            // ⚠ Active accounts WITH a ledger mapping. An unmapped one throws
            // MappingNotFound out of the action, so listing it would be offering
            // a guaranteed failure.
            'moneyAccounts' => MoneyAccount::query()
                ->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->all(),

            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),

            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordIncomeVoucher $action): RedirectResponse
    {
        $this->authorize('accounting.income.record');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠ Scoped exists, never a bare one: a bare rule accepts another
            // business's id and the global scope then hides the row the document
            // points at.
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')
                ->where('business_id', $businessId)],
            'received_on' => ['required', 'date'],
            // ⚠ A payer is either a customer we already know or free text. A
            // scrap dealer is not a customer and should not have to become one
            // in the master data just to hand over money.
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')
                ->where('business_id', $businessId)],
            'received_from' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            // ⚠ The chart of accounts is deliberately GLOBAL - it carries no
            // business_id - so this exists rule is correctly unscoped. The
            // action still refuses anything that is not a postable, active,
            // non-sales income account.
            'lines.*.account_id' => ['required', 'integer', 'distinct', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        $customer = empty($data['customer_id'])
            ? null
            : Customer::findOrFail($data['customer_id']);

        $lines = [];

        foreach ($data['lines'] as $row) {
            $lines[] = [
                'account' => Account::findOrFail($row['account_id']),
                'amount' => (string) $row['amount'],
                'description' => $row['description'] ?? null,
            ];
        }

        // ⚠⚠ The action's refusals are RENDERED, never re-decided here. A
        // ValidationException it raises passes through with its own wording onto
        // its own field. PeriodClosedException and MappingNotFound are plain
        // RuntimeExceptions and would otherwise reach the user as a raw 500.
        try {
            $action->handle(
                moneyAccount: $moneyAccount,
                receivedOn: $data['received_on'],
                lines: $lines,
                createdBy: $request->user(),
                customer: $customer,
                receivedFrom: $data['received_from'] ?? null,
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        }

        return redirect()
            ->route('accounting.other-income.index')
            ->with('success', 'Income recorded.');
    }

    /**
     * Attach the deposit slip, bank advice or dealer's chit.
     *
     * ⚠ Evidence is added AFTER the voucher, deliberately, exactly as on the
     * expense side: the document is recorded when the money moves, the proof is
     * attached when it is in hand.
     */
    public function uploadEvidence(Request $request, IncomeVoucher $voucher, StoreAttachment $store): RedirectResponse
    {
        $this->authorize('accounting.income.record');

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx', 'max:5120'],
        ]);

        $store->handle($voucher, $request->file('file'), IncomeVoucher::EVIDENCE_COLLECTION, $request->user());

        return back()->with('success', "Proof attached to {$voucher->voucher_number}.");
    }

    /**
     * ⚠ Removing proof is allowed but logged. The voucher itself stays: money
     * that arrived is not deleted because its paperwork changed.
     */
    public function deleteEvidence(IncomeVoucher $voucher, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        $this->authorize('accounting.income.record');

        abort_unless(
            $attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id,
            404,
        );

        $delete->handle($attachment);

        return back()->with('success', 'Proof removed.');
    }

    /**
     * The voucher as paper: the office copy and the payer's copy on one sheet.
     *
     * ⚠ Behind the permission that can VIEW the books, not the one that records
     * income: a document is no more secret than the record it prints.
     */
    public function print(IncomeVoucher $voucher): Response
    {
        $this->authorize('accounting.view');

        $voucher->load(['moneyAccount:id,name', 'customer:id,name', 'lines.account:id,code,name', 'createdBy:id,name']);

        return Inertia::render('accounting/other-income/print', [
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'voucher' => [
                'voucher_number' => $voucher->voucher_number,
                'received_on' => $voucher->received_on?->toDateString(),
                'received_into' => $voucher->moneyAccount?->name,
                'payer' => $voucher->payer(),
                'reference' => $voucher->reference,
                'note' => $voucher->note,
                'total' => Money::ofTaka((string) $voucher->total)->toTaka(),
                'recorded_by' => $voucher->createdBy?->name,
                'lines' => $voucher->lines->map(fn ($line) => [
                    'account' => $line->account?->label(),
                    'description' => $line->description,
                    'amount' => Money::ofTaka((string) $line->amount)->toTaka(),
                ])->values()->all(),
            ],
        ]);
    }
}
