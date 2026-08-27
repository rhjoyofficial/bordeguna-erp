<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the business spent on running itself: rent, fuel, utilities, repairs.
 *
 * ⚠⚠ WHY THIS CONTROLLER EXISTS. `RecordExpenseVoucher` was complete and tested
 * with NO ROUTE AND NO SCREEN, so an expense could not be recorded anywhere in
 * the application. Money could leave the business for a supplier bill, but not
 * for rent - which means most of the profit and loss account had no source and
 * the P&L could not honestly be produced at all.
 *
 * ⚠ This adds no accounting behaviour whatsoever, and that is the point of the
 * design note: there is one journal engine, not two. Every rule - the period
 * lock, the future-date refusal, expense-accounts-only, the money account
 * mapping, the numbering - already lives in the action and stays there. This
 * class collects a form, validates its SHAPE and ownership, and hands it over.
 */
class ExpenseVoucherController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = app(BusinessContext::class)->id();

        $vouchers = ExpenseVoucher::query()
            ->with(['moneyAccount:id,name', 'supplier:id,name', 'lines.account:id,code,name', 'attachments.uploadedBy:id,name'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $vouchers->getCollection()->transform(fn (ExpenseVoucher $voucher) => [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            // ⚠ toDateString(), not the model's date cast. A `date` cast
            // serialises to JSON in UTC and Dhaka is UTC+6, so midnight local
            // becomes 18:00 the PREVIOUS day. Same correction as the payment
            // screen, which was caught in a browser with a correct database.
            'spent_on' => $voucher->spent_on?->toDateString(),
            'paid_from' => $voucher->moneyAccount?->name,
            'payee' => $voucher->payee(),
            'total' => Money::ofTaka((string) $voucher->total)->toTaka(),
            'reference' => $voucher->reference,
            'note' => $voucher->note,
            // The receipts behind the number. Names and links only: the file
            // itself streams through the attachment route, which is
            // business-scoped, so nothing here widens who can read it.
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

        return Inertia::render('purchasing/expenses/index', [
            'vouchers' => $vouchers,
            'canRecord' => $request->user()?->can('purchasing.expense.record') ?? false,

            // ⚠ EXPENSE accounts only, and postable only. The action refuses
            // everything else outright, so offering a revenue account here would
            // be a picker whose choices are mostly errors.
            'expenseAccounts' => Account::query()
                ->where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->label(),
                ])
                ->all(),

            // ⚠⚠ Active accounts WITH a ledger mapping. An unmapped one throws
            // MappingNotFound out of the action, so listing it would be offering
            // a guaranteed failure. The bank & cash screen now warns about
            // exactly this rather than letting it be discovered here.
            'moneyAccounts' => MoneyAccount::query()
                ->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->all(),

            'suppliers' => Supplier::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),

            'today' => now()->toDateString(),
        ]);
    }

    /**
     * Attach the receipt, bill or screenshot the money was paid against.
     *
     * ⚠⚠ EVIDENCE IS ADDED AFTER THE VOUCHER, DELIBERATELY. A voucher IS the
     * money leaving, and holding the payment hostage to somebody finding the
     * receipt first would mean either a voucher recorded late or a receipt
     * invented to get past the form. The document is recorded when the money
     * moves; the proof is attached when it is in hand.
     *
     * ⚠ Several files allowed, not one. A month of hosting is an invoice AND a
     * card statement line; an electricity bill is the bill AND the paid stamp.
     */
    public function uploadEvidence(Request $request, ExpenseVoucher $voucher, StoreAttachment $store): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx', 'max:5120'],
        ]);

        $store->handle(
            $voucher,
            $request->file('file'),
            ExpenseVoucher::EVIDENCE_COLLECTION,
            $request->user(),
        );

        return back()->with('success', "Receipt attached to {$voucher->voucher_number}.");
    }

    /**
     * ⚠ Removing a receipt is allowed but logged, because the alternative is a
     * wrong file attached to a payment forever. The voucher itself stays: money
     * that left the business is not deleted because its paperwork changed.
     */
    public function deleteEvidence(ExpenseVoucher $voucher, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        abort_unless(
            $attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id,
            404,
        );

        $delete->handle($attachment);

        return back()->with('success', 'Receipt removed.');
    }

    /**
     * The voucher as paper: one copy for the office file, one for whoever was
     * paid.
     *
     * ⚠⚠ TWO COPIES ON ONE SHEET, NOT TWO PRINTS. In a Bangladeshi office the
     * payee signs the copy that stays behind; printing them separately means
     * the signed one and the given one can differ. Same sheet, same numbers,
     * one press.
     *
     * ⚠ Behind the same permission that can VIEW expenses, not the one that
     * records them: a document is no more secret than the record it prints.
     */
    public function print(ExpenseVoucher $voucher): Response
    {
        $this->authorize('purchasing.reports.view');

        $voucher->load(['moneyAccount:id,name', 'supplier:id,name', 'lines.account:id,code,name', 'createdBy:id,name']);

        return Inertia::render('purchasing/expenses/print', [
            // ⚠ The same identity object every other printed document uses, so
            // a letterhead can never drift between one document and another.
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'voucher' => [
                'voucher_number' => $voucher->voucher_number,
                'spent_on' => $voucher->spent_on?->toDateString(),
                'paid_from' => $voucher->moneyAccount?->name,
                'payee' => $voucher->payee(),
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

    public function store(Request $request, RecordExpenseVoucher $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠ Scoped exists, never a bare one: a bare rule accepts another
            // business's id and the global scope then hides the row the document
            // points at. Same hole closed on the product screen on 5 August.
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')
                ->where('business_id', $businessId)],
            'spent_on' => ['required', 'date'],
            // ⚠ A payee is either a supplier we already know or free text. A
            // landlord is not a supplier of goods and should not have to become
            // one in the master data just to be paid rent.
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')
                ->where('business_id', $businessId)],
            'paid_to' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            // ⚠ The chart of accounts is deliberately GLOBAL - it carries no
            // business_id and a test asserts that column's absence - so this one
            // exists rule is correctly unscoped. The action still refuses any
            // account that is not a postable, active expense account.
            'lines.*.account_id' => ['required', 'integer', 'distinct', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        $supplier = empty($data['supplier_id'])
            ? null
            : Supplier::findOrFail($data['supplier_id']);

        $lines = [];

        foreach ($data['lines'] as $row) {
            $lines[] = [
                'account' => Account::findOrFail($row['account_id']),
                'amount' => (string) $row['amount'],
                'description' => $row['description'] ?? null,
            ];
        }

        // ⚠⚠ The action's refusals are RENDERED, never re-decided here. A
        // ValidationException it raises - a future date, a revenue account on a
        // line - passes through with its own wording onto its own field.
        //
        // ⚠ PeriodClosedException and MappingNotFound are plain RuntimeExceptions
        // and would otherwise reach the user as a raw 500. Turned into a sentence
        // on the form exactly as SupplierPaymentController and JournalController
        // do it. No rule is added: the decision was made in the engine, all that
        // happens here is that a human can read it.
        try {
            $action->handle(
                moneyAccount: $moneyAccount,
                spentOn: $data['spent_on'],
                lines: $lines,
                createdBy: $request->user(),
                supplier: $supplier,
                paidTo: $data['paid_to'] ?? null,
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['spent_on' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchasing.expenses.index')
            ->with('success', 'Expense recorded.');
    }
}
