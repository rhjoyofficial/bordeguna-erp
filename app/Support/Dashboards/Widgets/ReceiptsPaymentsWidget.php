<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * Money in against money out per day, across cash, bank and wallets, from the
 * posted lines on those heads. Transfers between the business's own accounts
 * are left out: moving cash to the bank is neither a receipt nor a payment.
 */
class ReceiptsPaymentsWidget extends Widget
{
    public function key(): string
    {
        return 'receipts_vs_payments';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Money in and out';
    }

    public function description(): ?string
    {
        return 'Receipts and payments through cash, bank and wallets by journal date. Transfers between your own accounts are left out.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Finance, DashboardKey::Owner];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function size(): string
    {
        return 'lg';
    }

    public function dateBasis(): ?string
    {
        return 'journal date';
    }

    public function cacheSeconds(): int
    {
        return 60;
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/cash-and-bank';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYMENT_RECEIVED];
    }

    public function data(WidgetContext $context): array
    {
        $range = $context->range;

        $rows = MoneyLedgers::cashLines($context->businessId)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereBetween('journals.journal_date', [$range->fromDate(), $range->toDate()])
            ->where(fn ($q) => $q->whereNull('journals.source_type')->orWhere('journals.source_type', '!=', (new MoneyTransfer)->getMorphClass()))
            ->groupBy('journals.journal_date')
            ->selectRaw('journals.journal_date as day,
                SUM(CASE WHEN journal_lines.amount_minor > 0 THEN journal_lines.amount_minor ELSE 0 END) as money_in,
                SUM(CASE WHEN journal_lines.amount_minor < 0 THEN -journal_lines.amount_minor ELSE 0 END) as money_out')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $in = 0;
        $out = 0;
        $points = [];

        foreach ($range->dates() as $date) {
            $row = $rows->get($date);
            $dayIn = (int) ($row->money_in ?? 0);
            $dayOut = (int) ($row->money_out ?? 0);
            $in += $dayIn;
            $out += $dayOut;
            $points[] = ['date' => $date, 'label' => date('j M', strtotime($date)), 'in' => self::taka($dayIn), 'out' => self::taka($dayOut)];
        }

        return [
            'kind' => 'series',
            'chart' => 'bar',
            'unit' => 'money',
            'series' => [['key' => 'in', 'label' => 'Money in'], ['key' => 'out', 'label' => 'Money out']],
            'points' => $points,
            'total' => self::taka($in - $out),
            'total_label' => 'Net',
            'basis' => 'ledger',
        ];
    }
}
