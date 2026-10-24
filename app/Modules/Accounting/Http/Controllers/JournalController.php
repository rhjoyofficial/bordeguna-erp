<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\Loans\RecordLoanLinesFromJournal;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\ManualJournalActionPolicy;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\CounterpartyRules;
use App\Modules\Accounting\Support\Loans\LoanOptions;
use App\Modules\Accounting\Support\Loans\LoanReclassification;
use App\Modules\Accounting\Support\ManualJournalForm;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Accounting\Support\SubsystemAccounts;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manual journal: the one place a human writes to the General Ledger by
 * hand, with no document behind it.
 *
 * ⚠⚠ THE HIGHEST-RISK PERMISSION IN THE SYSTEM (C-GL-6). It can move money
 * between any two accounts. `accounting.journal.post` is Owner only, and that
 * IS the control in this phase - the configurable threshold and approval
 * pipeline already exist in Inventory and get reused later rather than rebuilt.
 *
 * ⚠ The screen validates shape - two lines, one side each, an amount that
 * parses. It does NOT re-implement a single accounting rule. Balance, postable
 * accounts, the period lock and the date are all decided by PostJournal, once,
 * and a refusal is turned into a message. A screen that knew the rules would be
 * a second place they could disagree.
 */
class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        // ACC-011 (14 Oct 2026): the data-entry guide says "filter the Journal
        // register by type 'Opening balance'" to make sure the opening journal
        // is not entered twice, and the register had no filter. It has one now.
        $type = JournalType::tryFrom((string) $request->query('type', ''));

        // ⚠ ACC-006 (14 Oct 2026): the total and the reversed flag used to be
        // two fresh queries per row (`totalDebits()`, `hasBeenReversed()`),
        // about fifty per page. Both are read from what is loaded here instead.
        $journals = Journal::query()
            ->with('lines')
            ->withExists('reversal')
            ->when($type !== null, fn ($query) => $query->where('journal_type', $type->value))
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('accounting/journals/index', [
            'journals' => $journals->through(fn (Journal $journal) => [
                'id' => $journal->id,
                'number' => $journal->journal_number,
                'date' => $journal->journal_date->toDateString(),
                'type' => $journal->journal_type->value,
                'type_label' => $journal->journal_type->label(),
                'memo' => $journal->memo,
                // ⚠ Explicitly formatted. `19200.00000000` has reached a real
                // user on this project twice, in two different modules.
                'total' => Money::ofMinor((int) $journal->lines->where('amount_minor', '>', 0)->sum('amount_minor'))->format(),
                'lines' => $journal->lines->count(),
                'is_reversal' => $journal->isReversal(),
                'reversed' => (bool) $journal->reversal_exists,
            ]),
            'type' => $type?->value,
            'types' => collect(JournalType::cases())->map(fn (JournalType $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ])->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        // ⚠ Shown on the form rather than only enforced on save. A rule the user
        // meets as a rejection after typing eight lines is a rule they resent;
        // the same rule visible in the list is one they work with.
        $owned = SubsystemAccounts::on(Carbon::today());

        return Inertia::render('accounting/journals/create', [
            'accounts' => Account::query()
                ->postable()
                ->inChartOrder()
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->label(),
                    'type_label' => $account->type->label(),
                    // True when an operational module owns this account. Still
                    // selectable for an opening balance, which is the one entry
                    // that legitimately sets these by hand.
                    'system_owned' => isset($owned[$account->id]),
                    // "employee", "customer", "supplier" or "counterparty" when every
                    // line on this head must name who it is about (PartyLedgers).
                    // The form shows a picker for it; PostJournal refuses a line without.
                    'party' => ($class = PartyLedgers::partyClassFor($account)) === null ? null : PartyLedgers::noun($class),
                    // The loan head's note, translated by the screen (CounterpartyRules).
                    'loan_rule' => CounterpartyRules::guidanceKey($account),
                ]),
            'types' => collect(JournalType::cases())->map(fn (JournalType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
            'today' => Carbon::today()->toDateString(),
            // ⭐ 13 Oct 2026: an opening balance on 2130 Salaries Payable, 1170
            // Employee Advances or 2140 Employee Deductions Payable must name the
            // employee, and this screen had no way to, so the September salary
            // still owed at the opening date could not be entered anywhere.
            'suppliers' => fn () => Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name]),
            'customers' => fn () => Customer::query()->listable()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name]),
            // Separated employees too: a person who left may still be owed salary.
            'employees' => fn () => Employee::query()->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'label' => PartyLedgers::label($e).' ('.$e->employee_code.')'.($e->status !== EmployeeStatus::Separated ? '' : ' (left)')]),
            // ⭐ 14 Oct 2026: an opening balance on a loan head names its lender
            // or borrower (loans plan v2). Group-wide, active ones only.
            'counterparties' => fn () => self::counterpartyOptions(),
            // ⭐ Loans plan v2, phase 2: a line on a loan head names its register
            // loan, and "Prepare reclassification" opens this screen filled in.
            'loans' => fn () => LoanOptions::forPickers(),
            'prefill' => fn () => $this->reclassPrefill($request),
        ]);
    }

    /**
     * The Loan Register's year-end reclassification, proposed and never
     * posted: the lines arrive here for the Owner to review and post.
     *
     * @return array{date: string, memo: string, type: string, lines: list<array<string, mixed>>}|null
     */
    private function reclassPrefill(Request $request): ?array
    {
        $asAt = $request->query('reclass_as_at');

        if (! is_string($asAt) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt)) {
            return null;
        }

        $date = Carbon::parse($asAt)->startOfDay();
        $counterparty = $request->integer('counterparty_id') ?: null;
        $lines = LoanReclassification::journalLines($date, $counterparty);

        return $lines === [] ? null : [
            'date' => $date->toDateString(),
            'memo' => "Current portion of long-term loans at {$date->format('j M Y')}",
            'type' => JournalType::Manual->value,
            'lines' => $lines,
        ];
    }

    /**
     * The counterparties a loan line may name, for the journal and voucher
     * forms: active ones, with the related-party mark the picker filters by.
     *
     * @return list<array{id: int, label: string, related: bool}>
     */
    public static function counterpartyOptions(): array
    {
        return Counterparty::query()->active()->orderBy('legal_name')->get(['id', 'legal_name', 'related_party'])
            ->map(fn (Counterparty $c) => ['id' => $c->id, 'label' => $c->legal_name, 'related' => (bool) $c->related_party])
            ->values()
            ->all();
    }

    public function show(Journal $journal): Response
    {
        $journal->load(['lines.account', 'lines.party', 'postedBy', 'reverses']);

        return Inertia::render('accounting/journals/show', [
            'journal' => [
                'id' => $journal->id,
                'number' => $journal->journal_number,
                'date' => $journal->journal_date->toDateString(),
                'type_label' => $journal->journal_type->label(),
                'memo' => $journal->memo,
                'posted_by' => $journal->postedBy?->name,
                'posted_at' => $journal->posted_at?->toDayDateTimeString(),
                'is_reversal' => $journal->isReversal(),
                'reverses' => $journal->reverses?->journal_number,
                'reversed' => $journal->hasBeenReversed(),
                // ACC-004 (14 Oct 2026): a journal another screen made (a
                // voucher, payroll, stock) is never reversed from here, so the
                // page no longer offers a button the server always refuses.
                'has_source' => $journal->source_type !== null,
                // ⭐ Corrections wave 2 (24 Oct 2026): Correct and Reverse for a
                // manual journal, each greyed with its reason when blocked.
                'corrections' => $journal->source_type !== null || $journal->isReversal() ? null : [
                    ...CorrectionScreen::for($journal, app(ManualJournalActionPolicy::class), request()->user()),
                    'allow_today' => ! AccountingPeriod::isClosedOn($journal->journal_date->copy()->startOfDay()),
                ],
                'total' => $journal->totalDebits()->format(),
                'lines' => $journal->lines->map(fn ($line) => [
                    'line_no' => $line->line_no,
                    'account' => $line->account->label(),
                    'party' => PartyLedgers::label($line->party, $line->party_type, $line->party_id),
                    'memo' => $line->memo,
                    'debit' => $line->isDebit() ? $line->amount()->format() : null,
                    'credit' => $line->isDebit() ? null : $line->amount()->absolute()->format(),
                ]),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // ⭐ Corrections wave 2 (24 Oct 2026): the form is read by
        // ManualJournalForm, the same rules a manual journal's Correct reads.
        $data = $request->validate(ManualJournalForm::rules());
        $draft = ManualJournalForm::draft($data);

        // ⭐ A line naming a register loan moves that loan too, in the same
        // transaction (loans plan v2, phase 2).
        $journal = $this->postOrFail(fn () => DB::transaction(function () use ($draft, $request) {
            $posted = app(PostJournal::class)->handle($draft, $request->user());
            app(RecordLoanLinesFromJournal::class)->handle($posted, $request->user());

            return $posted;
        }));

        return redirect()
            ->route('accounting.journals.show', $journal->id)
            ->with('success', "Posted {$journal->journal_number}.");
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): a manual journal's Correct form, the
     * record screen pre-filled. Nothing is posted from here: "Preview and
     * correct" opens the action dialog, which posts to records.act.
     */
    public function correct(Request $request, Journal $journal, ManualJournalActionPolicy $policy): Response|RedirectResponse
    {
        $availability = $policy->availability($journal, $request->user(), DocumentAction::Correct);

        if ($journal->source_type !== null || $journal->isReversal() || $journal->hasBeenReversed()) {
            return redirect()->route('accounting.journals.show', $journal->id)
                ->with('error', $availability->blockers[0]->message ?? "{$journal->journal_number} cannot be corrected.");
        }

        $journal->load('lines');

        return $this->create($request)
            ->with('prefill', [
                'date' => $journal->journal_date->toDateString(),
                'memo' => (string) $journal->memo,
                'type' => $journal->journal_type->value,
                'lines' => $journal->lines->map(fn ($line) => [
                    'account_id' => (int) $line->account_id,
                    'debit' => $line->amount_minor > 0 ? Money::ofMinor((int) $line->amount_minor)->toTaka() : '',
                    'credit' => $line->amount_minor < 0 ? Money::ofMinor(abs((int) $line->amount_minor))->toTaka() : '',
                    'party_id' => $line->party_id === null ? null : (int) $line->party_id,
                    'loan_contract_id' => $line->loan_contract_id === null ? null : (int) $line->loan_contract_id,
                    'memo' => (string) $line->memo,
                ])->values()->all(),
            ])
            ->with('correcting', [
                'id' => $journal->id,
                'number' => $journal->journal_number,
                'total' => $journal->totalDebits()->format(),
                'type' => ManualJournalActionPolicy::TYPE,
                'availability' => $availability->toArray(),
                'reasons' => CorrectionScreen::reasons('correction'),
            ]);
    }

    public function reverse(Request $request, Journal $journal): RedirectResponse
    {
        // ⚠⚠ A SUBSYSTEM JOURNAL IS CORRECTED BY REVERSING THE BUSINESS EVENT,
        // never from the ledger screen. Reversing a payroll accrual here would
        // leave the payroll run approved and its cost gone from the books, with
        // nothing in either system able to explain the difference. The presence
        // of a source is exactly the test: a manual journal has none.
        if ($journal->source_type !== null) {
            throw ValidationException::withMessages([
                'lines' => 'This entry was created by another part of the system. Correct it there - reversing it here would leave the two records disagreeing with nothing to explain the difference.',
            ]);
        }

        $reversal = $this->postOrFail(fn () => DB::transaction(function () use ($journal, $request) {
            $reversal = app(ReverseJournal::class)->handle($journal, $request->user());
            // A manual journal that named a register loan: its movement goes too.
            RecordLoanLinesFromJournal::markReversed($journal, $reversal, $request->user(), 'Reversed from the journal screen');

            return $reversal;
        }));

        return redirect()
            ->route('accounting.journals.show', $reversal->id)
            ->with('success', "{$journal->journal_number} reversed by {$reversal->journal_number}.");
    }

    /**
     * ⚠ The engine throws; a screen has to show a sentence. Both refusals are
     * caught in ONE place so no route can accidentally leak a raw exception
     * page, and neither is re-implemented as a validation rule.
     */
    private function postOrFail(callable $post): Journal
    {
        try {
            return $post();
        } catch (JournalException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
    }
}
