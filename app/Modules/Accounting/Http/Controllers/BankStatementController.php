<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\BankStatementFile;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Bank statement matching (Module 2, Slice 3): the bank's statement beside
 * the book, line by line, per money account.
 *
 * ⚠⚠ AUTO-MATCHING IS DELIBERATELY TIMID: exact amount, right direction,
 * within three days, exactly ONE candidate. A wrong auto-match hides a real
 * discrepancy behind a false agreement, which is worse than no match at all.
 * Everything else waits for a human, who can match, unmatch or ignore (a
 * bank charge not yet booked) - acting needs accounting.journal.post,
 * looking needs accounting.view.
 */
class BankStatementController extends Controller
{
    private const MATCH_WINDOW_DAYS = 3;

    public function show(Request $request): Response
    {
        $this->authorize('accounting.view');

        $accounts = MoneyAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']);
        $accountId = (int) $request->query('money_account_id', (string) ($accounts->first()?->id ?? 0));

        $statementLines = DB::table('bank_statement_lines')
            ->where('money_account_id', $accountId)
            ->orderByDesc('txn_date')->orderByDesc('id')
            ->limit(200)
            ->get();

        $matchedIds = $statementLines->pluck('matched_journal_line_id')->filter();

        // The other side of the truth: book lines on this account with no
        // statement line claiming them.
        $unmatchedBookLines = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.money_account_id', $accountId)
            ->whereNotIn('journal_lines.id', DB::table('bank_statement_lines')
                ->whereNotNull('matched_journal_line_id')->pluck('matched_journal_line_id'))
            ->orderByDesc('journals.journal_date')
            ->limit(200)
            ->get([
                'journal_lines.id', 'journals.journal_date', 'journals.journal_number',
                'journals.memo', 'journal_lines.amount_minor',
            ])
            ->map(fn ($line) => [
                'id' => $line->id,
                'date' => $line->journal_date,
                'journal' => $line->journal_number,
                'memo' => $line->memo,
                'amount' => $line->amount_minor / 100,
            ]);

        return Inertia::render('accounting/bank-statements', [
            'accounts' => $accounts,
            'money_account_id' => $accountId,
            'statement_lines' => $statementLines,
            'unmatched_book_lines' => $unmatchedBookLines,
            'can_act' => $request->user()->can('accounting.journal.post'),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        $data = $request->validate([
            'money_account_id' => ['required', Rule::exists('money_accounts', 'id')],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $rows = BankStatementFile::read($request->file('file')->getRealPath());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        if ($rows === []) {
            return back()->withErrors(['file' => 'The statement carried no movements.']);
        }

        $matched = 0;

        DB::transaction(function () use ($rows, $data, $request, &$matched) {
            $importId = DB::table('bank_statement_imports')->insertGetId([
                'business_id' => app(BusinessContext::class)->id(),
                'money_account_id' => $data['money_account_id'],
                'filename' => $request->file('file')->getClientOriginalName(),
                'line_count' => count($rows),
                'imported_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($rows as $row) {
                $candidateId = $this->soleCandidate((int) $data['money_account_id'], $row);

                DB::table('bank_statement_lines')->insert([
                    'business_id' => app(BusinessContext::class)->id(),
                    'import_id' => $importId,
                    'money_account_id' => $data['money_account_id'],
                    'txn_date' => $row['txn_date'],
                    'description' => $row['description'],
                    'reference' => $row['reference'],
                    'amount' => $row['amount'],
                    'match_status' => $candidateId !== null ? 'matched' : 'unmatched',
                    'matched_journal_line_id' => $candidateId,
                    'matched_by' => $candidateId !== null ? $request->user()->id : null,
                    'matched_at' => $candidateId !== null ? now() : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                if ($candidateId !== null) {
                    $matched++;
                }
            }

            DB::table('bank_statement_imports')->where('id', $importId)->update(['matched_count' => $matched]);
        });

        return back()->with('success', count($rows).' statement lines imported, '.$matched.' matched automatically.');
    }

    public function match(Request $request, int $line): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        $statementLine = DB::table('bank_statement_lines')->where('id', $line)->first();
        abort_if($statementLine === null, 404);

        $data = $request->validate([
            'journal_line_id' => ['required', Rule::exists('journal_lines', 'id')],
        ]);

        $bookLine = DB::table('journal_lines')->where('id', $data['journal_line_id'])->first();

        // ⚠ A manual match still has to be TRUE: same account, same amount,
        // same direction. The human chooses between candidates; they do not
        // overrule arithmetic.
        if ((int) $bookLine->money_account_id !== (int) $statementLine->money_account_id
            || (int) round((float) $statementLine->amount * 100) !== (int) $bookLine->amount_minor) {
            return back()->withErrors([
                'journal_line_id' => 'That book line is a different amount or direction - a match must agree to the paisa.',
            ]);
        }

        DB::table('bank_statement_lines')->where('id', $line)->update([
            'match_status' => 'matched',
            'matched_journal_line_id' => $bookLine->id,
            'matched_by' => $request->user()->id,
            'matched_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Matched.');
    }

    public function unmatch(Request $request, int $line): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        DB::table('bank_statement_lines')->where('id', $line)->update([
            'match_status' => 'unmatched',
            'matched_journal_line_id' => null,
            'matched_by' => null,
            'matched_at' => null,
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Unmatched.');
    }

    public function ignore(Request $request, int $line): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        DB::table('bank_statement_lines')->where('id', $line)->update([
            'match_status' => 'ignored',
            'matched_journal_line_id' => null,
            'matched_by' => $request->user()->id,
            'matched_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Ignored - remember to book it if it is real.');
    }

    /**
     * The one journal line this statement row can only be - or null.
     *
     * @param array{txn_date: string, amount: float} $row
     */
    private function soleCandidate(int $moneyAccountId, array $row): ?int
    {
        $candidates = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.money_account_id', $moneyAccountId)
            ->where('journal_lines.amount_minor', (int) round($row['amount'] * 100))
            ->whereBetween('journals.journal_date', [
                date('Y-m-d', strtotime($row['txn_date'].' -'.self::MATCH_WINDOW_DAYS.' days')),
                date('Y-m-d', strtotime($row['txn_date'].' +'.self::MATCH_WINDOW_DAYS.' days')),
            ])
            ->whereNotIn('journal_lines.id', DB::table('bank_statement_lines')
                ->whereNotNull('matched_journal_line_id')->pluck('matched_journal_line_id'))
            ->limit(2)
            ->pluck('journal_lines.id');

        return $candidates->count() === 1 ? (int) $candidates->first() : null;
    }
}
