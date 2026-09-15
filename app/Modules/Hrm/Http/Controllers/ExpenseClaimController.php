<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Hrm\Actions\PostExpenseClaimApproval;
use App\Modules\Hrm\Actions\SettleExpenseClaim;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\ExpenseClaimLine;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Notifications\ExpenseClaimReturnedNotification;
use App\Modules\Hrm\Support\ExpenseCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money an employee spent on the company, and getting it back to them.
 *
 * ⚠⚠ TWO AUDIENCES, ONE MODEL. `mine` is self-scope, exactly like the clock and
 * My Leave: an employee sees and raises only their own. `index` is the finance
 * view of everybody's, and it is the only place a claim can be paid. Mixing them
 * into one screen would mean every employee's list quietly carried a Pay button
 * that 403s.
 *
 * ⚠⚠ A CLAIM IS A HEADER WITH LINES (Joy, 9 Sep 2026). A market trip is goods,
 * a rickshaw, a courier and tea for the supplier - four spends, one afternoon,
 * one claim, one approval, one payment, one voucher.
 *
 * ⚠⚠ AND ONE SPEND IS THE WHOLE SCREEN (Joy, 10 Sep 2026: "it works, I did not
 * like the process"). The first attempt at the above asked for a claim TITLE as
 * well as a spend note, and offered two equally loud buttons - it solved the
 * market trip by taxing the single courier that is most of the traffic. Now:
 * five fields and one button, the spend lands on the employee's ONE open claim,
 * and sending is a separate quieter press. Nothing asks for a title; the claim
 * describes itself from what it holds.
 *
 * ⛔ A SUBMITTED CLAIM IS CLOSED TO THE EMPLOYEE. A spend remembered the next day
 * becomes a NEW claim. Reopening one mid-approval asks questions nobody can
 * answer: was the old total the one agreed, had the payable posted, was the
 * voucher printed, was the money already paid.
 *
 * ⚠⚠ AND THERE IS A WAY BACK. A claim with one wrong figure used to have to be
 * rejected and typed again from nothing. It can now be SENT BACK with a note:
 * the employee is told, fixes it, and resubmits the same claim under the same
 * number, with every decision still on the record.
 */
class ExpenseClaimController extends Controller
{
    /**
     * The employee's own claims: the ones they are still building at the top,
     * everything they have already sent below.
     */
    public function mine(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $claims = ExpenseClaim::query()
            ->with(['approvalRequest.decisions.decidedBy:id,name', 'lines.attachments', 'settledBy:id,name'])
            ->where('employee_id', $employee->id)
            // Joy, 7 Sep: newest ADDED first, not newest spent. A claim for
            // last month's taxi entered this morning is the one waiting on
            // somebody, so it belongs at the top; sorting by `spent_on` buried
            // it under older entries that had already been dealt with.
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpenseClaim $claim) => $this->row($claim));

        ExpenseCategories::ensureDefaults(app(BusinessContext::class)->id());

        return Inertia::render('hrm/expense-claims/mine', [
            // ⚠⚠ ONE OPEN CLAIM, NOT A LIST OF DRAFTS (10 Sep 2026). Allowing
            // several at once is what forced a "which claim" picker onto the
            // form, and a picker is a question most people never need to answer.
            // A returned claim is the same single open claim, handed back.
            'draft' => $claims->first(fn (array $row) => $row['is_draft']),
            'claims' => $claims->reject(fn (array $row) => $row['is_draft'])->values(),
            'categories' => ExpenseCategories::active(app(BusinessContext::class)->id()),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * Record one spend.
     *
     * ⚠⚠ THIS IS THE WHOLE SCREEN NOW (Joy, 10 Sep 2026: "it works, I did not
     * like the process"). Version 2 asked for a claim title, a spend note and a
     * choice between two equally-loud buttons, which solved the market trip by
     * taxing the courier that happens every day. An employee should be able to
     * think "I spent 180 taka on a courier today" and be finished.
     *
     * So: five fields and one button. The spend lands on the employee's open
     * claim, or starts one if they have none. Sending it is a separate, quieter
     * press on the claim itself.
     */
    public function store(Request $request, StoreAttachment $store): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return back()->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate(
            $this->lineRules($request, $businessId),
            $this->lineMessages(),
        );

        $claim = $this->openDraft($employee->id);

        return DB::transaction(function () use ($claim, $data, $request, $store, $employee, $businessId) {
            if ($claim === null) {
                $claim = ExpenseClaim::create([
                    'business_id' => $businessId,
                    'employee_id' => $employee->id,
                    'claim_number' => ExpenseClaim::nextNumber($businessId),
                    // ⚠ All three are DERIVED from the lines the moment the
                    // first one lands. They are written here only because the
                    // columns are not nullable, and `syncFromLines` immediately
                    // overwrites them with the truth.
                    'spent_on' => $data['spent_on'],
                    'amount' => $data['amount'],
                    // ⚠ Unnamed on purpose. One spend describes itself; the
                    // screen asks for a trip name only once a second joins.
                    'purpose' => null,
                    'created_by' => $request->user()->id,
                ]);
            }

            $this->addLine($claim, $data, $request, $store);

            return back()->with('success', "Added to {$claim->claim_number}. Add another spend, or send it for approval.");
        });
    }

    /**
     * Send a draft in that already has its spends on it - including one that
     * came back for correction.
     */
    public function submit(Request $request, ExpenseClaim $claim, NotifyStageApprovers $notify): RedirectResponse
    {
        $employee = Employee::forUser($request->user());
        abort_if($employee === null, 403);

        $claim = $this->ownDraft($claim->id, $employee->id);

        return $this->sendForApproval($claim, $request, $notify);
    }

    /**
     * Name a claim that holds several spends.
     *
     * ⭐ Joy, 10 Sep 2026, giving a journey to Barisal only as ONE example -
     * "not only trip, there may happen for many ways multi expense in single
     * time". Grouping several spends under one approval was already solved; what
     * the company could not see was WHAT they were. Saved as the employee types
     * rather than carried on the send, so a name typed at noon survives an
     * afternoon of adding more spends.
     */
    public function rename(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $employee = Employee::forUser($request->user());
        abort_if($employee === null, 403);

        $claim = $this->ownDraft($claim->id, $employee->id);

        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:1000'],
        ], [
            'purpose.required' => 'Say what these spends were for, so the office can see where the money went.',
        ]);

        $claim->forceFill(['purpose' => trim($data['purpose'])])->save();

        return back()->with('success', 'Saved.');
    }

    public function destroyLine(Request $request, ExpenseClaim $claim, ExpenseClaimLine $line, DeleteAttachment $deleteAttachment): RedirectResponse
    {
        $employee = Employee::forUser($request->user());
        abort_if($employee === null, 403);

        $claim = $this->ownDraft($claim->id, $employee->id);
        abort_unless($line->expense_claim_id === $claim->id, 404);

        $this->discardLine($line, $deleteAttachment);

        $claim->load('lines');
        $claim->syncFromLines();

        return back()->with('success', 'Spend removed from the claim.');
    }

    /**
     * Correct one spend on the employee's open claim.
     *
     * ⭐⭐ JOY, 15 SEP 2026: "the expense send back can be editable and can be
     * resend and admin will check". A claim sent back for "Dollar rate 125.25"
     * could only be fixed by removing the spend and typing it again with its
     * receipt, so on 14 Sep both returned claims were discarded instead, their
     * approval history left pointing at nothing and their numbers reused next
     * morning. This is the correction that was missing.
     *
     * ⚠ Only while the claim is the employee's own OPEN claim: never sent, or
     * sent back. `ownDraft` 404s a claim waiting on an approver, an approved or a
     * rejected one, so what an approver decided on cannot be changed underneath
     * the decision.
     *
     * ⚠ The receipt already on the spend counts. A new file replaces it; leaving
     * the file box empty keeps it, so correcting a figure never means finding the
     * slip again.
     */
    public function updateLine(Request $request, ExpenseClaim $claim, ExpenseClaimLine $line, StoreAttachment $store, DeleteAttachment $deleteAttachment): RedirectResponse
    {
        $employee = Employee::forUser($request->user());
        abort_if($employee === null, 403);

        $claim = $this->ownDraft($claim->id, $employee->id);
        abort_unless($line->expense_claim_id === $claim->id, 404);

        $line->load('attachments');

        $data = $request->validate(
            $this->lineRules($request, app(BusinessContext::class)->id(), receiptRequired: $line->attachments->isEmpty()),
            $this->lineMessages(),
        );

        DB::transaction(function () use ($claim, $line, $data, $request, $store, $deleteAttachment) {
            $line->update([
                'spent_on' => $data['spent_on'],
                'expense_category_id' => $data['expense_category_id'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
            ]);

            if ($request->hasFile('receipt')) {
                $line->attachments->each(fn (Attachment $file) => $deleteAttachment->handle($file));
                $store->handle($line, $request->file('receipt'), ExpenseClaimLine::EVIDENCE_COLLECTION, $request->user());
            }

            $claim->load('lines');
            $claim->syncFromLines();
        });

        return back()->with('success', "Spend corrected on {$claim->claim_number}.");
    }

    /**
     * Throw away a draft nobody has seen.
     *
     * ⚠ A draft only. A submitted claim is a record that somebody asked, and
     * deleting it would lose the only proof the request was ever made - the
     * same reason a rejected claim is kept exactly as it was.
     */
    public function destroy(Request $request, ExpenseClaim $claim, DeleteAttachment $deleteAttachment): RedirectResponse
    {
        $employee = Employee::forUser($request->user());
        abort_if($employee === null, 403);

        $claim = $this->ownDraft($claim->id, $employee->id);
        $number = $claim->claim_number;

        // ⛔⛔ JOY, 15 SEP 2026: a claim an approver has looked at is NOT
        // discarded; it is corrected and sent again. On 14 Sep two sent-back
        // claims were discarded, which left their approval history pointing at
        // nothing and let the next claims take the same numbers. A claim with an
        // approval request has been seen, whatever state it is in now.
        if ($claim->approvalRequest()->exists()) {
            throw ValidationException::withMessages([
                'claim' => "{$number} was sent back by an approver, so it cannot be discarded. Correct the spends and send it again.",
            ]);
        }

        // ⚠ The lines go with the claim through the foreign key, but their
        // receipts do NOT: an attachment is polymorphic, so the row and the file
        // on disk would both survive with nothing pointing at them.
        $claim->lines->each(fn (ExpenseClaimLine $line) => $this->discardLine($line, $deleteAttachment));

        $claim->delete();

        return back()->with('success', "Draft {$number} discarded.");
    }

    /**
     * ⚠ The file on disk goes with the row. This is only ever reached from a
     * DRAFT, which is the one state where a receipt is not yet evidence of
     * anything anybody has decided on; a submitted claim's proof is never
     * deletable from a screen ([[FileVisibility::mayDelete]] says the same).
     */
    private function discardLine(ExpenseClaimLine $line, DeleteAttachment $deleteAttachment): void
    {
        $line->attachments->each(fn (Attachment $file) => $deleteAttachment->handle($file));

        $line->delete();
    }

    /**
     * Everybody's claims, for the people who approve and pay them.
     */
    public function index(Request $request): Response
    {
        $this->authorize('hrm.payroll.manage');

        $claims = ExpenseClaim::query()
            ->with([
                'employee:id,employee_code,first_name,last_name',
                'approvalRequest.decisions.decidedBy:id,name',
                'lines.attachments',
                'expenseAccount:id,code,name',
                'settledBy:id,name',
            ])
            // Joy, 7 Sep: newest ADDED first, not newest spent. A claim for
            // last month's taxi entered this morning is the one waiting on
            // somebody, so it belongs at the top; sorting by `spent_on` buried
            // it under older entries that had already been dealt with.
            ->orderByDesc('id')
            ->get()
            // ⚠ A draft belongs to nobody but its author. It is unfinished, it
            // has not been asked for, and showing it here would invite somebody
            // to approve money the employee has not yet claimed.
            ->reject(fn (ExpenseClaim $claim) => $claim->isDraft() && ! $claim->wasSentBack())
            ->map(fn (ExpenseClaim $claim) => $this->row($claim, withEmployee: true) + [
                // ⚠ The STAGE decides who may approve, not this screen's own
                // permission. Drawing the decision block for somebody the
                // workflow will refuse is how a button that 403s gets shipped.
                'can_decide' => $claim->approvalRequest?->canBeActionedBy($request->user()) ?? false,
            ])
            ->values();

        return Inertia::render('hrm/expense-claims/index', [
            'claims' => $claims,
            'moneyAccounts' => MoneyAccount::query()->handled()
                ->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->all(),
            // ⚠ Newest first: a reimbursement almost always belongs to the month
            // being worked on, not one from last year.
            'payrollPeriods' => PayrollPeriod::query()
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->limit(12)
                ->get()
                ->map(fn (PayrollPeriod $period) => ['id' => $period->id, 'label' => $period->label()])
                ->all(),
            'expenseAccounts' => Account::query()
                ->where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
                ->all(),
        ]);
    }

    /**
     * Say yes to a claim, and optionally no to some of its lines.
     *
     * ⚠⚠ APPROVING IS NOT PAYING, and the two are deliberately separate calls.
     * Somebody with the payroll permission agrees the money is owed; paying it
     * is a second, explicit act that names the account or the salary month. A
     * screen that did both on one press would move money the moment somebody
     * agreed a receipt looked genuine.
     *
     * ⚠⚠ A DECLINED LINE IS REFUSED BEFORE THE JOURNAL IS WRITTEN, not after.
     * The posting reads the payable lines, so the declines have to be recorded
     * first or the ledger would carry money the approver just refused. Declining
     * every line is refused outright - that is a rejection, and it should say so.
     *
     * ⚠ The stage decides who may act, not this method - `canBeActionedBy`
     * reads the workflow, so changing who approves is a seeder row and not a
     * code change.
     */
    public function approve(Request $request, ExpenseClaim $claim, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $claim->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'declines' => ['nullable', 'array'],
            'declines.*.id' => ['required', 'integer'],
            'declines.*.reason' => ['required', 'string', 'max:500'],
        ], [
            'declines.*.reason.required' => 'Say why the line is being declined. The employee has to know what to fix.',
        ]);

        $this->applyDeclines($claim, $data['declines'] ?? []);

        if (! $approval->approve($request->user(), $data['note'] ?? null)) {
            $notify->handle($approval->fresh(), "Expense claim {$claim->claim_number}");

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        // FINAL approval: the expense becomes real NOW - Dr the category's
        // account, Cr Employee Reimbursements Payable. Cash moves later.
        $journal = app(PostExpenseClaimApproval::class)
            ->handle($claim->fresh()->load('lines'), $request->user());

        // ⚠ The posting is skipped for a legacy claim with no category, and
        // when accounting is off. Saying "booked" either way would be a lie
        // the accountant only discovers in the ledger.
        return back()->with('success', $journal === null
            ? "Claim {$claim->claim_number} approved. It can now be paid."
            : "Claim {$claim->claim_number} approved and booked to the expense. It can now be paid.");
    }

    /**
     * Hand the claim back to the employee to fix.
     *
     * ⚠⚠ THE NOTE IS REQUIRED. This exists because rejecting a claim over one
     * wrong figure made the employee retype everything; sending it back with no
     * words would leave them exactly as stuck, and looking for somebody to ask.
     */
    public function sendBack(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $approval = $claim->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ], [
            'note.required' => 'Say what needs correcting. The employee has to know what to change before they resubmit.',
        ]);

        $approval->sendBack($request->user(), $data['note']);

        // Back to the employee's hands: a draft again, same number, same lines.
        $claim->forceFill(['submitted_at' => null])->save();

        $claim->employee?->user?->notify(new ExpenseClaimReturnedNotification(
            "Claim {$claim->claim_number} was sent back: {$data['note']}",
            route('hrm.my-expenses'),
        ));

        return back()->with('success', "Claim {$claim->claim_number} sent back to {$claim->employee?->fullName()} for correction.");
    }

    public function reject(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $approval = $claim->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $approval->reject($request->user(), $this->note($request));

        // ⚠ The claim itself is left exactly as it was. A rejected claim is a
        // record that the employee asked and was turned down, and rewriting or
        // deleting it would lose the only proof the request was ever made.
        return back()->with('success', "Claim {$claim->claim_number} rejected. No money moved.");
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
    }

    /**
     * Pay the claim, by whichever route the office chose.
     */
    public function settle(Request $request, ExpenseClaim $claim, SettleExpenseClaim $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'method' => ['required', Rule::in([ExpenseClaim::SETTLE_PAYMENT, ExpenseClaim::SETTLE_PAYROLL])],
            // Legacy claims (no category) still name the account by hand;
            // a category claim already fixed it at approval.
            'expense_account_id' => [
                Rule::requiredIf(fn () => $claim->approval_journal_id === null),
                'nullable', 'integer', Rule::exists('accounts', 'id'),
            ],
            // ⚠ Required only for the pay-now route. A salary settlement moves
            // no money today and has no account to move it from.
            'money_account_id' => [
                Rule::requiredIf(fn () => $request->input('method') === ExpenseClaim::SETTLE_PAYMENT),
                'nullable',
                'integer',
                Rule::exists('money_accounts', 'id'),
            ],
            // ⚠ Required only for the salary route, and named rather than
            // inferred: an adjustment belongs to one payroll month.
            'payroll_period_id' => [
                Rule::requiredIf(fn () => $request->input('method') === ExpenseClaim::SETTLE_PAYROLL),
                'nullable',
                'integer',
                Rule::exists('payroll_periods', 'id'),
            ],
        ]);

        $expenseAccount = isset($data['expense_account_id'])
            ? Account::findOrFail($data['expense_account_id'])
            : Account::findOrFail($claim->expense_account_id);

        if ($data['method'] === ExpenseClaim::SETTLE_PAYROLL) {
            $period = PayrollPeriod::findOrFail($data['payroll_period_id']);
            $action->withSalary($claim, $expenseAccount, $period, $request->user());

            return back()->with('success', "Claim {$claim->claim_number} will be paid with the {$period->label()} salary.");
        }

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);
        $settled = $action->payNow($claim, $moneyAccount, $expenseAccount, $request->user());

        return back()->with(
            'success',
            "Claim {$claim->claim_number} paid from {$moneyAccount->name}. Payment voucher "
            .($settled->voucher?->voucher_number ?? '').' created with the receipt attached.',
        );
    }

    // ── The pieces the actions above share ──────────────────────────────────

    /**
     * The validation one spend has to pass, wherever it is being typed.
     *
     * ⚠⚠ THE RECEIPT IS REQUIRED UNLESS THE ACCOUNTANT SAID OTHERWISE. Joy's
     * requirement was that the office keeps the proof, and proof asked for later
     * is proof that often never arrives. The exception is a category the
     * accountant has flagged - rickshaw fare and tea come with no piece of
     * paper, and refusing them would push those spends off the books entirely.
     *
     * @return array<string, mixed>
     */
    private function lineRules(Request $request, int $businessId, bool $receiptRequired = true): array
    {
        return [
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:1000'],
            // The accountant's structure: a category from the list, so the COA
            // mapping is decided by data, never by the claimant.
            'expense_category_id' => ['required', 'integer',
                Rule::exists('expense_categories', 'id')
                    ->where('business_id', $businessId)
                    ->where('is_active', true),
            ],
            'receipt' => [
                Rule::requiredIf(fn () => $receiptRequired && $this->needsReceipt($request->input('expense_category_id'))),
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120',
            ],
        ];
    }

    /** @return array<string, string> */
    private function lineMessages(): array
    {
        return [
            'receipt.required' => 'Attach a photo or scan of the receipt. A spend cannot be approved without it.',
            'spent_on.before_or_equal' => 'You cannot claim for a date in the future.',
        ];
    }

    private function needsReceipt(mixed $categoryId): bool
    {
        if (! is_numeric($categoryId)) {
            // No category picked yet: the category rule refuses first, and
            // demanding a file on top would print two errors for one mistake.
            return false;
        }

        return ExpenseCategories::describe((int) $categoryId)['receipt_required'] ?? true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addLine(ExpenseClaim $claim, array $data, Request $request, StoreAttachment $store): ExpenseClaimLine
    {
        $line = $claim->lines()->create([
            'business_id' => $claim->business_id,
            'spent_on' => $data['spent_on'],
            'expense_category_id' => $data['expense_category_id'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'sort' => (int) $claim->lines()->max('sort') + 1,
        ]);

        if ($request->hasFile('receipt')) {
            $store->handle($line, $request->file('receipt'), ExpenseClaimLine::EVIDENCE_COLLECTION, $request->user());
        }

        $claim->load('lines');
        $claim->syncFromLines();

        return $line;
    }

    /**
     * ⚠ A claim can only be sent by the person whose money it is, and only
     * while it is theirs to send. Everything else - somebody else's draft, one
     * already waiting on an approver, one already paid - is a 404 rather than a
     * silent no-op, so a stale tab cannot resubmit last week's claim.
     */
    private function ownDraft(int $claimId, int $employeeId): ExpenseClaim
    {
        $claim = ExpenseClaim::query()
            ->with('lines.attachments')
            ->where('id', $claimId)
            ->where('employee_id', $employeeId)
            ->whereNull('submitted_at')
            ->whereNull('settled_at')
            ->first();

        abort_if($claim === null, 404);

        return $claim;
    }

    /**
     * The one claim this employee currently has open, or null.
     *
     * ⚠⚠ EXACTLY ONE, BY DESIGN (10 Sep 2026). Several drafts at once is what
     * forced a "which claim" picker onto the form, and answering that question
     * is work most people never needed to do. A returned claim occupies the same
     * single slot, which is right: it IS the thing they should be dealing with.
     *
     * ⚠ Ordered oldest first so a returned claim - which is older than anything
     * started since - is the one they land on rather than a newer scratch.
     */
    private function openDraft(int $employeeId): ?ExpenseClaim
    {
        return ExpenseClaim::query()
            ->with('lines.attachments')
            ->where('employee_id', $employeeId)
            ->whereNull('submitted_at')
            ->whereNull('settled_at')
            ->orderBy('id')
            ->first();
    }

    private function sendForApproval(ExpenseClaim $claim, Request $request, NotifyStageApprovers $notify): RedirectResponse
    {
        $claim->load('lines.attachments');

        if ($claim->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'claim' => 'Add at least one spend before sending the claim for approval.',
            ]);
        }

        // ⚠⚠ CHECKED AGAIN AT SUBMISSION, not only when the line was typed. A
        // line can be added while its category still allowed a missing receipt
        // and then the accountant changes their mind; sending a claim an
        // approver is obliged to refuse wastes both their time.
        $missing = $claim->lines->filter(fn (ExpenseClaimLine $line) => $line->attachments->isEmpty()
            && $this->needsReceipt($line->expense_category_id));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'claim' => 'These spends still need a receipt: '
                    .$missing->map(fn (ExpenseClaimLine $line) => $line->spent_on?->toDateString().' '.$line->amount)->implode(', '),
            ]);
        }

        // ⛔ SEVERAL SPENDS AT ONCE HAVE TO SAY WHAT THEY WERE. Grouping them
        // under one approval is the whole point of the module, but four
        // unrelated lines tell the company nothing about where the money went.
        // One line of the employee's own words does. A single spend is never
        // asked, because it already describes itself.
        if ($claim->needsAName()) {
            throw ValidationException::withMessages([
                'claim' => 'This claim has more than one spend, so give it a name first - what was the trip or the job?',
            ]);
        }

        $claim->syncFromLines();
        $claim->forceFill(['submitted_at' => now()])->save();

        $approval = $claim->resubmitForApproval($request->user());
        $notify->handle($approval, "Expense claim {$claim->claim_number} from {$claim->employee?->fullName()}");

        return back()->with('success', "Claim {$claim->claim_number} sent for approval.");
    }

    /**
     * @param  list<array{id: int, reason: string}>  $declines
     */
    private function applyDeclines(ExpenseClaim $claim, array $declines): void
    {
        if ($declines === []) {
            return;
        }

        $claim->load('lines');
        $byId = $claim->lines->keyBy('id');

        // ⚠ Counted from the lines that actually EXIST on this claim, and
        // de-duplicated. A repeated or foreign id must not be able to talk the
        // guard below into thinking a line survived when none did.
        $targets = collect($declines)
            ->filter(fn (array $decline) => $byId->has($decline['id']))
            ->keyBy(fn (array $decline) => $decline['id']);

        if ($targets->isEmpty()) {
            return;
        }

        if ($targets->count() >= $claim->lines->count()) {
            throw ValidationException::withMessages([
                'declines' => 'Declining every line is a rejection. Use Reject, or send the claim back for correction.',
            ]);
        }

        foreach ($targets as $id => $decline) {
            $byId->get($id)->forceFill([
                'declined_at' => now(),
                'declined_reason' => $decline['reason'],
            ])->save();
        }

        $claim->load('lines');
        $claim->syncFromLines();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ExpenseClaim $claim, bool $withEmployee = false): array
    {
        $row = [
            'id' => $claim->id,
            'claim_number' => $claim->claim_number,
            // ⚠ toDateString(), not the cast: a `date` cast serialises in UTC
            // and Dhaka is UTC+6, so midnight local renders a day early.
            'spent_on' => $claim->spent_on?->toDateString(),
            'amount' => (string) (float) $claim->amount,
            'purpose' => $claim->displayName(),
            // The employee's own words, empty when they have not named it yet -
            // kept apart from `purpose` so the input is not pre-filled with a
            // label the system invented.
            'trip_name' => $claim->purpose,
            'needs_a_name' => $claim->needsAName(),
            'state' => $claim->state(),
            'is_draft' => $claim->isDraft(),
            'was_sent_back' => $claim->wasSentBack(),
            'sent_back_note' => $claim->wasSentBack() ? $claim->approvalRequest?->review_note : null,
            // ⚠ Joy, 15 Sep 2026: only a claim nobody has reviewed may be thrown
            // away. The screen hides Discard for the rest; destroy() refuses them.
            'can_discard' => $claim->isDraft() && $claim->approvalRequest === null,
            'is_settled' => $claim->isSettled(),
            'is_approved' => $claim->isApproved(),
            'settlement_method' => $claim->settlement_method,
            'settled_by' => $claim->settledBy?->name,
            // The category is what BOTH audiences recognise; the account is
            // the accountant's word for the same thing. Legacy claims carry
            // no category and show only the account, as they always did.
            'category' => ExpenseCategories::describe($claim->expense_category_id)['name'] ?? null,
            // ⚠ A booked claim has ALREADY hit the expense account, so the screen
            // must not offer an expense head for it: the cost is decided and only
            // the payable is outstanding. ⭐ Both settlement routes ARE offered
            // (Joy, 7 Sep) - paying now moves the payable to cash, adding it to
            // salary moves it to wages, and neither expenses it a second time.
            'is_booked' => $claim->approval_journal_id !== null,
            'expense_account' => $claim->expenseAccount?->label(),
            'lines' => $claim->lines->map(fn (ExpenseClaimLine $line) => [
                'id' => $line->id,
                'spent_on' => $line->spent_on?->toDateString(),
                'amount' => (string) (float) $line->amount,
                'description' => $line->description,
                'category' => $line->category()['name'] ?? null,
                'category_id' => $line->expense_category_id,
                'is_declined' => $line->isDeclined(),
                'declined_reason' => $line->declined_reason,
                'receipts' => $line->attachments->map(fn (Attachment $file) => [
                    'id' => $file->id,
                    'name' => $file->original_name ?? $file->file_name,
                    'url' => route('attachments.show', $file->id),
                ])->values()->all(),
            ])->values()->all(),
            // Kept flat as well: several screens only want to know whether
            // there is proof at all, without walking the lines to find out.
            'receipts' => $claim->receipts()->map(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->file_name,
                'url' => route('attachments.show', $file->id),
            ])->values()->all(),
        ];

        if ($withEmployee) {
            $row['employee'] = $claim->employee?->fullName();
            $row['employee_code'] = $claim->employee?->employee_code;
        }

        return $row;
    }

    /**
     * The reimbursement voucher, for printing.
     *
     * ⛔ WHY THIS EXISTS. A claim that was expensed at approval clears the
     * payable directly when it is paid and produces NO expense voucher, so
     * there was no printable document at all: money left the till with nothing
     * to file and nothing for the recipient to sign. The books were right and
     * the paperwork did not exist.
     *
     * ⚠ Only a SETTLED claim can be printed. A voucher for money that has not
     * moved is a document that says something untrue.
     */
    public function print(ExpenseClaim $claim): Response
    {
        $this->authorize('hrm.payroll.manage');

        abort_unless($claim->isSettled(), 404);

        $claim->load(['employee', 'expenseAccount', 'settledBy', 'voucher', 'adjustment.period', 'lines.expenseAccount']);

        $settlementJournal = Journal::where('source_type', $claim->getMorphClass())
            ->where('source_id', $claim->id)
            ->where('id', '!=', $claim->approval_journal_id)
            ->latest('id')
            ->first();

        return Inertia::render('hrm/expense-claims/print', [
            // The same identity object every other printed document uses, so a
            // letterhead cannot drift between one document and another.
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'claim' => [
                // ⚠ The screen's PDF button builds `/documents/expense-claim/{id}`
                // from this. It was missing, so the button asked for
                // `.../undefined/pdf` and the download simply failed.
                'id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'employee' => $claim->employee?->fullName() ?? '-',
                'employee_code' => $claim->employee?->employee_code,
                'spent_on' => $claim->spent_on?->toDateString(),
                'settled_on' => $claim->settled_at?->toDateString(),
                'purpose' => $claim->displayName(),
                // ⚠ THE CATEGORY, NOT THE ACCOUNT. Both of these read the
                // account before, so a voucher handed to the person being paid
                // said "6250 - Office Stationery" - a chart code, on the one
                // document the employee keeps. The claim list in this same
                // controller already had it right: the category is what both
                // audiences recognise, the account is the accountant's word for
                // the same thing, and a legacy claim carrying no category falls
                // back to the account exactly as it always did.
                'category' => ExpenseCategories::describe($claim->expense_category_id)['name'] ?? null,
                'charged_to' => $claim->expenseAccount?->label(),
                'amount' => (string) $claim->amount,
                // ⚠ Every spend on the voucher, so the person signing for the
                // money can check it against the receipts they handed over.
                'lines' => $claim->payableLines()->map(fn (ExpenseClaimLine $line) => [
                    'spent_on' => $line->spent_on?->toDateString(),
                    'category' => $line->category()['name'] ?? $line->expenseAccount?->label(),
                    'description' => $line->description,
                    'amount' => (string) $line->amount,
                ])->values()->all(),
                'declined' => $claim->lines->filter(fn (ExpenseClaimLine $line) => $line->isDeclined())
                    ->map(fn (ExpenseClaimLine $line) => [
                        'spent_on' => $line->spent_on?->toDateString(),
                        'description' => $line->description,
                        'amount' => (string) $line->amount,
                        'reason' => $line->declined_reason,
                    ])->values()->all(),
                'method' => $claim->settlement_method === ExpenseClaim::SETTLE_PAYROLL
                    ? 'Added to salary'
                    : 'Paid directly',
                'paid_from' => $this->paidFrom($claim, $settlementJournal),
                'salary_month' => $claim->adjustment?->period?->label(),
                'settled_by' => $claim->settledBy?->name,
                'approved_journal' => $claim->approval_journal_id
                    ? Journal::find($claim->approval_journal_id)?->journal_number
                    : null,
                'settlement_journal' => $settlementJournal?->journal_number,
                'voucher_number' => $claim->voucher?->voucher_number,
            ],
        ]);
    }

    /**
     * Which till, bank or wallet the cash actually left.
     *
     * ⛔⛔ THE VOUCHER COULD NOT SAY, AND AN AUDITOR WOULD ASK. This read
     * `$claim->voucher?->moneyAccount?->name`, which only exists on the legacy
     * route: a BOOKED claim settled in cash clears the payable directly and
     * writes no expense voucher, so the printed line read "Paid directly" and
     * then nothing at all - while the purchasing payment voucher beside it named
     * its account. `expense_claims` has no money account column, but the
     * settlement journal's credit line carries a `money_account_id`, which is
     * where this now reads it from.
     */
    private function paidFrom(ExpenseClaim $claim, ?Journal $settlementJournal): ?string
    {
        if ($claim->settlement_method !== ExpenseClaim::SETTLE_PAYMENT) {
            return null;
        }

        if (($name = $claim->voucher?->moneyAccount?->name) !== null) {
            return $name;
        }

        return $settlementJournal?->lines
            ->first(fn ($line) => $line->money_account_id !== null)
            ?->moneyAccount?->name;
    }
}
