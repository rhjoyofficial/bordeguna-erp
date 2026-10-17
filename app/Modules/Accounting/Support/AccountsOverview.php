<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Foundation\Support\BusinessContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The three figures the Accounts Overview (redesign round 3, 16 Oct 2026,
 * decision D10) needs that no report class produced before: vouchers counted
 * by type and status, journal drafts that do not balance, and cheques still
 * waiting to clear. Everything else on the Overview comes from the classes
 * the statements and the role dashboards already use.
 *
 * ⚠⚠ SCOPED BY HAND. These are query-builder reads (a grouped count, a
 * HAVING over the lines), so the Eloquent BusinessScope does not apply: every
 * query names `business_id` itself, the business the request stands in.
 *
 * ⚠ Corrections by the verifier (round3-VERIFY A-E5, A-E6), kept here:
 * - `cheque_statuses` holds cleared, bounced or cancelled only. A cheque that
 *   is still waiting is a POSTED cheque voucher with NO status row, exactly
 *   as ChequeRegisterController reads it.
 * - `voucher_lines` carry `side` (debit / credit) and a positive
 *   `amount_minor`; there are no debit and credit columns. A draft may be
 *   unbalanced (only PostVoucher enforces balance), which is why the tile
 *   means something.
 */
final class AccountsOverview
{
    public function __construct(private readonly int $businessId) {}

    public static function forCurrentBusiness(): self
    {
        return new self((int) app(BusinessContext::class)->id());
    }

    /**
     * Vouchers by type and status for the vouchers dated in the range, what
     * is still open (drafts and approvals, whatever their date) and what
     * happened today.
     *
     * @return array{
     *     rows: list<array{type: string, draft: int, pending_approval: int, posted: int, reversed: int, posted_minor: int}>,
     *     other_income: array{count: int, minor: int},
     *     open: array{draft: int, pending_approval: int, oldest_draft: ?string, drafts_by_type: array<string, int>, pending_by_type: array<string, int>},
     *     today: array{entered: int, posted: int},
     * }
     */
    public function voucherCounts(CarbonInterface $from, CarbonInterface $to, CarbonInterface $today): array
    {
        $grouped = $this->vouchers()
            ->whereBetween('voucher_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('type', 'status')
            ->selectRaw('type, status, COUNT(*) as how_many, SUM(total_minor) as minor')
            ->get();

        $rows = [];

        foreach (VoucherType::cases() as $type) {
            $row = ['type' => $type->value, 'draft' => 0, 'pending_approval' => 0, 'posted' => 0, 'reversed' => 0, 'posted_minor' => 0];

            foreach ($grouped->where('type', $type->value) as $group) {
                if (array_key_exists((string) $group->status, $row)) {
                    $row[(string) $group->status] = (int) $group->how_many;
                }

                if ($group->status === VoucherStatus::Posted->value) {
                    $row['posted_minor'] = (int) $group->minor;
                }
            }

            $rows[] = $row;
        }

        // ⚠⚠ NO DOUBLE COUNT (edit, delete and correct, Phase 0, 17 Oct 2026).
        // Other income can now be reversed and corrected, so summing every
        // income_vouchers row counted a corrected voucher AND its correction.
        // The COUNT is of live vouchers; the MONEY comes from the ledger (the
        // contract's rule 1.12): the income-head lines of journals whose source
        // is an income voucher, in the range. A reversal on the original's date
        // nets its original to zero inside the same range.
        $incomeCount = DB::table('income_vouchers')
            ->where('business_id', $this->businessId)
            ->whereNull('reversed_at')
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->count();

        $incomeMinor = (int) DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journals.business_id', $this->businessId)
            ->where('journals.source_type', (new IncomeVoucher)->getMorphClass())
            ->where('accounts.type', AccountType::Income->value)
            ->whereBetween('journals.journal_date', [$from->toDateString(), $to->toDateString()])
            ->sum('journal_lines.amount_minor');

        $open = $this->vouchers()
            ->whereIn('status', [VoucherStatus::Draft->value, VoucherStatus::PendingApproval->value])
            ->groupBy('type', 'status')
            ->selectRaw('type, status, COUNT(*) as how_many, MIN(voucher_date) as oldest')
            ->get();

        $drafts = $open->where('status', VoucherStatus::Draft->value);
        $pending = $open->where('status', VoucherStatus::PendingApproval->value);
        $oldest = $drafts->pluck('oldest')->filter()->min();

        $dayStart = $today->copy()->startOfDay();
        $dayEnd = $today->copy()->endOfDay();

        return [
            'rows' => $rows,
            'other_income' => [
                'count' => $incomeCount,
                // Income is a CREDIT, negative in the ledger's signed convention.
                'minor' => -$incomeMinor,
            ],
            'open' => [
                'draft' => (int) $drafts->sum('how_many'),
                'pending_approval' => (int) $pending->sum('how_many'),
                'oldest_draft' => $oldest === null ? null : substr((string) $oldest, 0, 10),
                'drafts_by_type' => $drafts->mapWithKeys(fn ($g) => [(string) $g->type => (int) $g->how_many])->all(),
                'pending_by_type' => $pending->mapWithKeys(fn ($g) => [(string) $g->type => (int) $g->how_many])->all(),
            ],
            'today' => [
                'entered' => $this->vouchers()->whereBetween('created_at', [$dayStart, $dayEnd])->count()
                    + DB::table('income_vouchers')->where('business_id', $this->businessId)->whereBetween('created_at', [$dayStart, $dayEnd])->count(),
                'posted' => $this->vouchers()->whereBetween('posted_at', [$dayStart, $dayEnd])->count(),
            ],
        ];
    }

    /**
     * Draft journal vouchers whose lines do not balance, the biggest gap
     * first. `difference_minor` is debits minus credits.
     *
     * @return list<array{id: int, title: string, date: string, difference_minor: int, created_by: ?string}>
     */
    public function unbalancedJournalDrafts(): array
    {
        return DB::table('vouchers')
            ->join('voucher_lines', 'voucher_lines.voucher_id', '=', 'vouchers.id')
            ->leftJoin('users', 'users.id', '=', 'vouchers.created_by')
            ->where('vouchers.business_id', $this->businessId)
            ->where('voucher_lines.business_id', $this->businessId)
            ->where('vouchers.type', VoucherType::Journal->value)
            ->where('vouchers.status', VoucherStatus::Draft->value)
            ->groupBy('vouchers.id', 'vouchers.voucher_number', 'vouchers.voucher_date', 'users.name')
            ->havingRaw("SUM(CASE WHEN voucher_lines.side = 'debit' THEN voucher_lines.amount_minor ELSE -voucher_lines.amount_minor END) <> 0")
            ->selectRaw("vouchers.id as id, vouchers.voucher_number as number, vouchers.voucher_date as voucher_date, users.name as created_by,
                SUM(CASE WHEN voucher_lines.side = 'debit' THEN voucher_lines.amount_minor ELSE -voucher_lines.amount_minor END) as difference")
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => $row->number ?? 'Draft journal voucher',
                'date' => substr((string) $row->voucher_date, 0, 10),
                'difference_minor' => (int) $row->difference,
                'created_by' => $row->created_by,
            ])
            ->sortByDesc(fn (array $row) => abs($row['difference_minor']))
            ->values()
            ->all();
    }

    /**
     * Cheques written or received on a posted voucher that nobody has marked
     * cleared, bounced or cancelled yet, and how many of them are past the
     * date on the cheque.
     *
     * @return array{count: int, past_date: int, minor: int, oldest: ?string}
     */
    public function chequesWaiting(CarbonInterface $today): array
    {
        $waiting = $this->vouchers()
            ->where('instrument_type', 'cheque')
            ->where('status', VoucherStatus::Posted->value)
            ->whereNotExists(fn (Builder $q) => $q->from('cheque_statuses')
                ->whereColumn('cheque_statuses.voucher_id', 'vouchers.id')
                ->where('cheque_statuses.business_id', $this->businessId));

        $totals = (clone $waiting)->selectRaw('COUNT(*) as how_many, SUM(total_minor) as minor, MIN(instrument_date) as oldest')->first();

        return [
            'count' => (int) ($totals->how_many ?? 0),
            'past_date' => (clone $waiting)->whereNotNull('instrument_date')->where('instrument_date', '<', $today->toDateString())->count(),
            'minor' => (int) ($totals->minor ?? 0),
            'oldest' => $totals?->oldest === null ? null : substr((string) $totals->oldest, 0, 10),
        ];
    }

    private function vouchers(): Builder
    {
        return DB::table('vouchers')->where('vouchers.business_id', $this->businessId);
    }
}
