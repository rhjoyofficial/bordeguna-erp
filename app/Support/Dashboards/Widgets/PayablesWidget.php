<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingDocument;
use App\Modules\Accounting\Support\AgeingRow;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * What we owe suppliers, by age, and how much falls due in the next seven days.
 * Ageing::payables, which excludes reversed bills (Slice 0, D1).
 */
class PayablesWidget extends Widget
{
    public function key(): string
    {
        return 'payables_ageing';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Payables';
    }

    public function description(): ?string
    {
        return 'What we owe suppliers as at today, by age, with what falls due in the next seven days.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function size(): string
    {
        return 'md';
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
        return '/accounting/payables-ageing';
    }

    public function data(WidgetContext $context): array
    {
        $today = Carbon::today();
        $ageing = Ageing::payables($today);
        $payload = AgeingPayload::from($ageing, 'Overdue');

        $weekEnd = $today->copy()->addDays(7);
        $dueSoon = 0;

        foreach ($ageing->rows as $row) {
            /** @var AgeingRow $row */
            foreach ($row->documents as $document) {
                /** @var AgeingDocument $document */
                if ($document->dueDate !== null && $document->dueDate->betweenIncluded($today, $weekEnd)) {
                    $dueSoon += $document->outstanding->minor;
                }
            }
        }

        array_unshift($payload['parts'], ['label' => 'Due in 7 days', 'value' => self::taka($dueSoon), 'unit' => 'money']);

        return $payload;
    }
}
