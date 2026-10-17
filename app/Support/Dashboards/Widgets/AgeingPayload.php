<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Support\Ageing;

/**
 * One ageing as a breakdown payload: the five buckets, the total, how much is
 * overdue, and whether the documents tie to the control account.
 *
 * ⚠ F33 (practice run 17 Oct 2026): the headline total is the parties on the
 * widget's own side (customers who owe us, suppliers we owe), never the net.
 * A dealer's 807.50 return credit read "-807.50" as money owed to us. Parties
 * the other way round get their own neutral part ("We owe customers",
 * "Suppliers owe us"), shown positive. The ageing screen keeps the signed total
 * that ties to the control account.
 */
final class AgeingPayload
{
    /** @return array<string, mixed> */
    public static function from(Ageing $ageing, string $overdueLabel, string $creditLabel = 'In credit'): array
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

        // ⚠ Money on account is normally credit on both sides (a receipt or a
        // payment not yet applied), so it is shown the other way up: positive
        // is money held on account. A minus here is then a genuine oddity.
        $onAccount = -$ageing->onAccount()->minor;
        $inCredit = $ageing->totalInCredit()->minor;

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'money',
            'items' => $items,
            'total' => round($ageing->totalOwing()->minor / 100, 2),
            'parts' => array_values(array_filter([
                ['label' => $overdueLabel, 'value' => round($overdue / 100, 2), 'unit' => 'money'],
                $onAccount !== 0 ? ['label' => 'On account', 'value' => round($onAccount / 100, 2), 'unit' => 'money'] : null,
                // Neutral on purpose (tone null, a label with no colour meaning).
                $inCredit !== 0 ? ['label' => $creditLabel, 'value' => round($inCredit / 100, 2), 'unit' => 'money', 'tone' => null] : null,
            ])),
            'caption' => $ageing->tiesOut() ? null : 'Does not tie to the ledger: open the full report.',
            'basis' => 'ledger',
            'watch' => ['overdue_minor' => $overdue],
        ];
    }
}
