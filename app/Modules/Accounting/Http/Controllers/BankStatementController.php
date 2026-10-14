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

    /** One page of either list (FEA-007). */
    private const PER_PAGE = 100;

    public function show(Request $request): Response
    {
        $this->authorize('accounting.view');

        // ⚠ FEA-007 (14 Oct 2026): a switched-off account that still has
        // unmatched statement lines is listed too. The dashboard counts those
        // lines, and they could be reached only by typing an id in the address.
        $withOpenLines = DB::table('bank_statement_lines')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('match_status', 'unmatched')
            ->distinct()->pluck('money_account_id');

        $accounts = MoneyAccount::query()->handled()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $withOpenLines))
            ->orderBy('name')->get(['id', 'name', 'type', 'is_active'])
            ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'is_active' => (bool) $account->is_active]);
        $accountId = (int) $request->query('money_account_id', (string) ($accounts->firstWhere('is_active', true)['id'] ?? $accounts->first()['id'] ?? 0));

        // ⚠ The id comes from the address bar and every query below is raw, so
        // the business scope never saw it: another business's bank statement
        // could be read by changing a number (found 21 Sep 2026).
        abort_unless($accountId === 0 || MoneyAccount::query()->whereKey($accountId)->exists(), 404);

        // ⚠ FEA-007 (14 Oct 2026): both lists were cut at 200 with no paging,
        // the header count read as a total, and the Match picker offered only
        // book lines from that cut. Both lists are paged now (each its own
        // page number), the headers carry the true totals, and the picker gets
        // every unclaimed book line of the right amount, however old.
        $statementLines = DB::table('bank_statement_lines')
            ->where('money_account_id', $accountId)
            ->orderByDesc('txn_date')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page')
            ->withQueryString();

        $claimed = DB::table('bank_statement_lines')->whereNotNull('matched_journal_line_id')->select('matched_journal_line_id');

        // The other side of the truth: book lines on this account with no
        // statement line claiming them.
        $unclaimed = fn () => DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.money_account_id', $accountId)
            ->whereNotIn('journal_lines.id', $claimed);

        $bookLine = fn ($line) => [
            'id' => $line->id,
            'date' => $line->journal_date,
            'journal' => $line->journal_number,
            'memo' => $line->memo,
            'amount' => $line->amount_minor / 100,
        ];
        $bookColumns = ['journal_lines.id', 'journals.journal_date', 'journals.journal_number', 'journals.memo', 'journal_lines.amount_minor'];

        $unmatchedBookLines = $unclaimed()
            ->orderByDesc('journals.journal_date')->orderByDesc('journal_lines.id')
            ->paginate(self::PER_PAGE, $bookColumns, 'book_page')
            ->withQueryString()
            ->through($bookLine);

        // One query for every unmatched statement line on this page.
        $amounts = collect($statementLines->items())->where('match_status', 'unmatched')
            ->map(fn ($line) => (int) round((float) $line->amount * 100))->unique()->values();
        $matchCandidates = $amounts->isEmpty() ? collect() : $unclaimed()
            ->whereIn('journal_lines.amount_minor', $amounts)
            ->orderByDesc('journals.journal_date')
            ->limit(1000)
            ->get($bookColumns)
            ->map($bookLine);

        return Inertia::render('accounting/bank-statements', [
            'accounts' => $accounts,
            'money_account_id' => $accountId,
            'statement_lines' => $statementLines->items(),
            'statement_pages' => ['page' => $statementLines->currentPage(), 'last' => $statementLines->lastPage(), 'total' => $statementLines->total()],
            'unmatched_book_lines' => $unmatchedBookLines->items(),
            'book_pages' => ['page' => $unmatchedBookLines->currentPage(), 'last' => $unmatchedBookLines->lastPage(), 'total' => $unmatchedBookLines->total()],
            'match_candidates' => $matchCandidates->values(),
            'can_act' => $request->user()->can('accounting.journal.post'),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        $data = $request->validate([
            'money_account_id' => ['required', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $parsed = BankStatementFile::parse($request->file('file')->getRealPath());
            $rows = $parsed['rows'];
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

        // FEA-001 (14 Oct 2026): say how the dates were read, and say it louder
        // when the file could not tell, so a wrong reading is caught at once.
        $dates = match (true) {
            $parsed['date_format'] === BankStatementFile::UNAMBIGUOUS => '',
            $parsed['date_guessed'] => ' Dates were read as '.$parsed['date_format'].' (03/10/2026 = 3 Oct 2026); nothing in the file could confirm it, so check one line against the bank.',
            default => ' Dates were read as '.$parsed['date_format'].'.',
        };

        return back()->with('success', count($rows).' statement lines imported, '.$matched.' matched automatically.'.$dates);
    }

    public function match(Request $request, int $line): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        $statementLine = $this->ownLine($line);

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
        $this->ownLine($line);

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
        $this->ownLine($line);

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
     * @param  array{txn_date: string, amount: float}  $row
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

    /** A statement line of THIS business, or a 404: the table is read raw, outside the business scope. */
    private function ownLine(int $line): object
    {
        $statementLine = DB::table('bank_statement_lines')
            ->where('id', $line)
            ->where('business_id', app(BusinessContext::class)->id())
            ->first();

        abort_if($statementLine === null, 404);

        return $statementLine;
    }
}
