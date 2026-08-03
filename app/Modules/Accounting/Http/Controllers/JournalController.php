<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $journals = Journal::query()
            ->with('lines')
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
                'total' => $journal->totalDebits()->format(),
                'lines' => $journal->lines->count(),
                'is_reversal' => $journal->isReversal(),
                'reversed' => $journal->hasBeenReversed(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('accounting/journals/create', [
            'accounts' => Account::query()
                ->postable()
                ->inChartOrder()
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->label(),
                    'type_label' => $account->type->label(),
                ]),
            'types' => collect(JournalType::cases())->map(fn (JournalType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function show(Journal $journal): Response
    {
        $journal->load(['lines.account', 'postedBy', 'reverses']);

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
                'total' => $journal->totalDebits()->format(),
                'lines' => $journal->lines->map(fn ($line) => [
                    'line_no' => $line->line_no,
                    'account' => $line->account->label(),
                    'memo' => $line->memo,
                    'debit' => $line->isDebit() ? $line->amount()->format() : null,
                    'credit' => $line->isDebit() ? null : $line->amount()->absolute()->format(),
                ]),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'journal_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'journal_type' => ['required', Rule::enum(JournalType::class)],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'lines.*.debit' => ['nullable', 'string', 'max:20'],
            'lines.*.credit' => ['nullable', 'string', 'max:20'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = [];

        foreach ($data['lines'] as $index => $line) {
            $debit = $this->amountOrNull($line['debit'] ?? null, "lines.{$index}.debit");
            $credit = $this->amountOrNull($line['credit'] ?? null, "lines.{$index}.credit");

            // ⚠ Exactly one side per line. A line carrying both is the shape
            // that a signed single amount cannot express, so it has to be
            // refused at the door rather than silently netted - netting a line
            // that says 500 debit AND 200 credit into 300 is a number nobody
            // typed.
            if ($debit !== null && $credit !== null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.debit" => 'Put an amount in the debit column or the credit column, not both.',
                ]);
            }

            if ($debit === null && $credit === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.debit" => 'This line needs an amount.',
                ]);
            }

            $lines[] = $debit !== null
                ? JournalLineDraft::debit((int) $line['account_id'], $debit, $line['memo'] ?? null)
                : JournalLineDraft::credit((int) $line['account_id'], $credit, $line['memo'] ?? null);
        }

        $draft = new JournalDraft(
            date: Carbon::parse($data['journal_date'])->startOfDay(),
            lines: $lines,
            memo: $data['memo'] ?? null,
            type: JournalType::from($data['journal_type']),
        );

        $journal = $this->postOrFail(fn () => app(PostJournal::class)->handle($draft, $request->user()));

        return redirect()
            ->route('accounting.journals.show', $journal->id)
            ->with('success', "Posted {$journal->journal_number}.");
    }

    public function reverse(Request $request, Journal $journal): RedirectResponse
    {
        $reversal = $this->postOrFail(
            fn () => app(ReverseJournal::class)->handle($journal, $request->user())
        );

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

    private function amountOrNull(?string $raw, string $key): ?Money
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $amount = Money::ofTaka($raw);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$key => 'That is not an amount of money.']);
        }

        // A typed zero is a line somebody meant to delete. Treated as empty so
        // the "this line needs an amount" message is the one they get.
        return $amount->isZero() ? null : $amount;
    }
}
