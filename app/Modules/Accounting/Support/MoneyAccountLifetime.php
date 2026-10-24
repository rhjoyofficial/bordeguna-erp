<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Models\MoneyAccount;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ B23 LIFETIME OF ONE MONEY ACCOUNT (25 Oct 2026; R77).
 *
 * Joy: a page for every account with its whole life. A cash box, bank or
 * wallet: what came in and what went out, by the kind of document that moved
 * it (customer receipts, supplier payments, payroll, transfers, vouchers),
 * with the balance before the window and after it.
 *
 * ⚠ READ FROM THE LEDGER, the same lines MoneyAvailability sums for "today's
 * balance": the money account's own head, and for a courier or gateway
 * account (which share one head) only the lines that name it. So the closing
 * here IS the balance on the Bank and cash list, never a second opinion.
 *
 * ⚠ Fixed query count: one grouped read for the kinds, one sum for the
 * opening, whatever the number of lines.
 */
final class MoneyAccountLifetime
{
    public function __construct(private readonly MoneyAccount $account, private readonly LifetimeRange $range) {}

    /**
     * @return array{opening: string, money_in: string, money_out: string, closing: string, kinds: list<array{label: string, module: string, count: int, money_in: string, money_out: string, net: string}>}
     */
    public function summary(): array
    {
        if ($this->account->account_id === null) {
            return ['opening' => '0.00', 'money_in' => '0.00', 'money_out' => '0.00', 'closing' => '0.00', 'kinds' => []];
        }

        $opening = $this->range->from === null ? 0 : (int) $this->lines()
            ->where('journals.journal_date', '<', $this->range->from->toDateString())
            ->sum('journal_lines.amount_minor');

        $rows = $this->range->apply($this->lines(), 'journals.journal_date')
            ->groupBy('journals.source_type')
            ->selectRaw('journals.source_type AS source_type,
                COUNT(DISTINCT journals.id) AS journals,
                SUM(CASE WHEN journal_lines.amount_minor > 0 THEN journal_lines.amount_minor ELSE 0 END) AS money_in,
                SUM(CASE WHEN journal_lines.amount_minor < 0 THEN -journal_lines.amount_minor ELSE 0 END) AS money_out')
            ->get();

        // Two source classes can share one label (a reversal journal): add them up under it.
        $kinds = [];

        foreach ($rows as $row) {
            $label = JournalSourceCatalog::label($row->source_type) ?? JournalSourceCatalog::MANUAL;
            $kinds[$label] ??= ['label' => $label, 'module' => JournalSourceCatalog::module($row->source_type), 'count' => 0, 'in' => 0, 'out' => 0];
            $kinds[$label]['count'] += (int) $row->journals;
            $kinds[$label]['in'] += (int) $row->money_in;
            $kinds[$label]['out'] += (int) $row->money_out;
        }

        $in = array_sum(array_column($kinds, 'in'));
        $out = array_sum(array_column($kinds, 'out'));

        usort($kinds, fn (array $a, array $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']));

        return [
            'opening' => Money::ofMinor($opening)->format(),
            'money_in' => Money::ofMinor($in)->format(),
            'money_out' => Money::ofMinor($out)->format(),
            'closing' => Money::ofMinor($opening + $in - $out)->format(),
            'kinds' => array_map(fn (array $kind) => [
                'label' => $kind['label'],
                'module' => $kind['module'],
                'count' => $kind['count'],
                'money_in' => Money::ofMinor($kind['in'])->format(),
                'money_out' => Money::ofMinor($kind['out'])->format(),
                'net' => Money::ofMinor($kind['in'] - $kind['out'])->format(),
            ], $kinds),
        ];
    }

    private function lines()
    {
        $query = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.business_id', $this->account->business_id)
            ->where('journal_lines.account_id', $this->account->account_id);

        if ($this->account->isCourier() || $this->account->isGateway()) {
            $query->where('journal_lines.money_account_id', $this->account->id);
        }

        return $query;
    }
}
