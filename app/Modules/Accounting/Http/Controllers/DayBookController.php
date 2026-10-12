<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\JournalSourceCatalog;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherNarrations;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Day Book: every journal posted in a window, from EVERY module, in the
 * order it was posted (the accountant's ask, 19 Sep 2026).
 *
 * ⚠⚠ EVERY journal, not only the four voucher types. A Day Book that shows
 * payment and receipt vouchers but not today's sales, payroll or depreciation
 * is asked "where is the rest?" within the first minute, and the honest answer
 * would be "on six other screens".
 *
 * ⚠ Read only, and it reads the journals themselves - there is no second record
 * to drift from the ledger. The kind of each row comes from the document that
 * posted it (JournalSourceCatalog), because `journal_type` is `manual` for
 * nearly everything and says nothing a person recognises.
 *
 * ⚠ Ordered by date and then by journal id - the posting sequence. Voucher
 * numbers cannot order a book that mixes several numbering streams.
 */
class DayBookController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $page = $this->query($filters)
            ->with(['lines.account:id,code,name', 'lines.party', 'source', 'postedBy:id,name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One batch for the page's vouchers, so a narration costs no query per journal.
        $narrations = VoucherNarrations::forJournals($page->getCollection()->pluck('id'));

        // Totals of the whole window, not of the page being looked at.
        $totalMinor = (int) $this->query($filters)
            ->join('journal_lines', 'journal_lines.journal_id', '=', 'journals.id')
            ->where('journal_lines.amount_minor', '>', 0)
            ->sum('journal_lines.amount_minor');

        $business = Business::find(app(BusinessContext::class)->id());

        return Inertia::render('accounting/day-book/index', [
            'from' => $filters['from']->toDateString(),
            'to' => $filters['to']->toDateString(),
            'kind' => $filters['kind'],
            'user' => $filters['user'],
            'journals' => $page->through(fn (Journal $journal) => $this->row($journal, $narrations)),
            'count' => $page->total(),
            'total' => Money::ofMinor($totalMinor)->format(),
            'kinds' => $this->kindsInUse(),
            'users' => $this->postersInUse(),
            'fiscal_year_start_month' => $business?->fiscal_year_start_month,
            'business_name' => $business?->name,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $name = "day-book-{$filters['from']->toDateString()}-to-{$filters['to']->toDateString()}.csv";

        return response()->streamDownload(function () use ($filters) {
            // ⚠ Queried INSIDE the closure, while the request's tenant scope is
            // still in force (the expense export's lesson).
            $out = fopen('php://output', 'w');
            // Excel reads a UTF-8 CSV as ANSI without the byte order mark, and
            // a Bengali narration turns to noise.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Voucher type', 'Journal no.', 'Document no.', 'Account code', 'Account', 'Party', 'Narration', 'Debit', 'Credit', 'Posted by']);

            $this->query($filters)
                ->with(['lines.account:id,code,name', 'lines.party', 'source', 'postedBy:id,name'])
                ->chunk(200, function (Collection $journals) use ($out) {
                    $narrations = VoucherNarrations::forJournals($journals->pluck('id'));

                    foreach ($journals as $journal) {
                        $row = $this->row($journal, $narrations);

                        foreach ($row['lines'] as $line) {
                            fputcsv($out, [
                                $row['date'], $row['kind'], $row['number'], $row['document_number'],
                                $line['code'], $line['account'], $line['party'],
                                $line['memo'] ?? $row['memo'],
                                $line['debit'], $line['credit'], $row['posted_by'],
                            ]);
                        }
                    }
                });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{from: Carbon, to: Carbon, kind: ?string, user: ?int}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', 'string', 'max:80'],
            'user' => ['nullable', 'integer'],
        ]);

        // A Day Book opens on TODAY. A single given date means that one day.
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : null;
        $from ??= $to ?? Carbon::today();
        $to ??= $from->copy();

        return ['from' => $from, 'to' => $to, 'kind' => $data['kind'] ?? null, 'user' => isset($data['user']) ? (int) $data['user'] : null];
    }

    /**
     * @param  array{from: Carbon, to: Carbon, kind: ?string, user: ?int}  $filters
     */
    private function query(array $filters): Builder
    {
        return Journal::query()
            ->whereDate('journals.journal_date', '>=', $filters['from']->toDateString())
            ->whereDate('journals.journal_date', '<=', $filters['to']->toDateString())
            ->when($filters['user'], fn (Builder $query, int $user) => $query->where('journals.posted_by', $user))
            ->when($filters['kind'], function (Builder $query, string $kind) {
                if ($kind === JournalSourceCatalog::OPENING) {
                    return $query->where('journals.journal_type', JournalType::OpeningBalance->value);
                }

                // ⚠ "Journal voucher" is BOTH the old hand-written journal (no
                // source) and the new journal voucher document. To a reader they
                // are one kind, so the filter returns both.
                if ($kind === JournalSourceCatalog::MANUAL) {
                    return $query->where(fn (Builder $either) => $either
                        ->where(fn (Builder $manual) => $manual->whereNull('journals.source_type')->where('journals.journal_type', '!=', JournalType::OpeningBalance->value))
                        ->orWhereHasMorph('source', [Voucher::class], fn (Builder $voucher) => $voucher->where('type', VoucherType::Journal->value)));
                }

                foreach (VoucherType::cases() as $voucherType) {
                    if ($kind === $voucherType->label()) {
                        return $query->whereHasMorph('source', [Voucher::class], fn (Builder $voucher) => $voucher->where('type', $voucherType->value));
                    }
                }

                $classes = JournalSourceCatalog::groupByLabel($this->sourceTypesInUse())[$kind] ?? [];

                // An unknown kind matches nothing rather than everything.
                return $query->whereIn('journals.source_type', $classes === [] ? ['-'] : $classes);
            })
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->select('journals.*');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Journal $journal, VoucherNarrations $narrations): array
    {
        $lines = $journal->lines->sortBy('line_no')->values();

        // ⭐ 12 Oct 2026: a voucher's journal reads as the voucher was written.
        // Its narration heads the entry and each line carries its own voucher
        // narration; the poster's "account - party" line memo, which only
        // repeats the account and party shown beside it, is dropped.
        $fromVoucher = $narrations->voucherFor((int) $journal->id) !== null;

        return [
            'id' => $journal->id,
            'date' => $journal->journal_date->toDateString(),
            'number' => $journal->journal_number,
            'kind' => $this->kindOf($journal),
            'module' => JournalSourceCatalog::module($journal->source_type),
            'document_number' => JournalSourceCatalog::documentNumber($journal->source),
            'memo' => $narrations->forJournal((int) $journal->id) ?? $journal->memo,
            'posted_by' => $journal->postedBy?->name,
            'is_reversal' => $journal->isReversal(),
            'reversed' => $journal->hasBeenReversed(),
            'total' => $journal->totalDebits()->format(),
            'lines' => $lines->map(fn ($line) => [
                'code' => $line->account?->code,
                'account' => $line->account?->name,
                'party' => $line->party?->getAttribute('name'),
                'memo' => $fromVoucher ? $narrations->forOwnLine($line) : $line->memo,
                'debit' => $line->isDebit() ? $line->amount()->format() : '',
                'credit' => $line->isDebit() ? '' : Money::ofMinor(abs((int) $line->amount_minor))->format(),
            ])->all(),
        ];
    }

    private function kindOf(Journal $journal): string
    {
        if ($journal->journal_type === JournalType::OpeningBalance) {
            return JournalSourceCatalog::OPENING;
        }

        // A voucher says which of the four it is; its class alone cannot.
        if ($journal->source instanceof Voucher) {
            return $journal->source->type->label();
        }

        return JournalSourceCatalog::label($journal->source_type) ?? JournalSourceCatalog::MANUAL;
    }

    /**
     * @return Collection<int, string|null>
     */
    private function sourceTypesInUse(): Collection
    {
        return Journal::query()->whereNotNull('source_type')->distinct()->pluck('source_type');
    }

    /**
     * Only kinds this business has actually posted: a filter offering "Payroll"
     * to a business that never ran one only ever returns an empty book.
     *
     * @return array<int, string>
     */
    private function kindsInUse(): array
    {
        $kinds = array_values(array_diff(array_keys(JournalSourceCatalog::groupByLabel($this->sourceTypesInUse())), ['Voucher']));

        foreach (Voucher::query()->whereNotNull('journal_id')->distinct()->pluck('type') as $voucherType) {
            $kinds[] = $voucherType->label();
        }

        if (Journal::query()->whereNull('source_type')->where('journal_type', '!=', JournalType::OpeningBalance->value)->exists()) {
            $kinds[] = JournalSourceCatalog::MANUAL;
        }

        if (Journal::query()->where('journal_type', JournalType::OpeningBalance->value)->exists()) {
            $kinds[] = JournalSourceCatalog::OPENING;
        }

        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        return $kinds;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function postersInUse(): array
    {
        return User::query()
            ->whereIn('id', Journal::query()->distinct()->pluck('posted_by'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
