<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Support\Ageing;

/**
 * One ageing as a breakdown payload: the five buckets, the total, how much is
 * overdue, and whether the documents tie to the control account.
 */
final class AgeingPayload
{
    /** @return array<string, mixed> */
    public static function from(Ageing $ageing, string $overdueLabel): array
    {
        $items = [];
        $overdue = 0;

        foreach (AgeingBucket::ordered() as $bucket) {
            $minor = $ageing->bucket($bucket)->minor;
            $items[] = [
                'label' => $bucket->label(),
                'value' => round($minor / 100, 2),
                'tone' => $bucket === AgeingBucket::Current ? null : ($bucket === AgeingBucket::Days90Plus ? 'bad' : 'warn'),
            ];

            if ($bucket !== AgeingBucket::Current) {
                $overdue += $minor;
            }
        }

        $onAccount = $ageing->onAccount()->minor;

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'money',
            'items' => $items,
            'total' => round($ageing->total()->minor / 100, 2),
            'parts' => array_values(array_filter([
                ['label' => $overdueLabel, 'value' => round($overdue / 100, 2), 'unit' => 'money'],
                $onAccount !== 0 ? ['label' => 'On account', 'value' => round($onAccount / 100, 2), 'unit' => 'money'] : null,
            ])),
            'caption' => $ageing->tiesOut() ? null : 'Does not tie to the ledger: open the full report.',
            'basis' => 'ledger',
            'watch' => ['overdue_minor' => $overdue],
        ];
    }
}
