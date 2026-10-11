<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingDocument;
use App\Modules\Accounting\Support\AgeingRow;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/**
 * Supplier bills falling due in the next DUE_DAYS days, from Ageing::payables
 * (reversed bills excluded). High when one falls due today or tomorrow.
 */
class PayablesDueSource extends Source
{
    public const DUE_DAYS = 7;

    public function key(): string
    {
        return 'payables_due';
    }

    public function area(): string
    {
        return 'money';
    }

    protected function permissions(): array
    {
        return ['accounting.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $today = Carbon::today();
        $end = $today->copy()->addDays(self::DUE_DAYS);
        $bills = 0;
        $minor = 0;
        $soonest = null;

        foreach (Ageing::payables($today)->rows as $row) {
            /** @var AgeingRow $row */
            foreach ($row->documents as $document) {
                /** @var AgeingDocument $document */
                if ($document->dueDate === null || ! $document->dueDate->betweenIncluded($today, $end) || $document->outstanding->minor <= 0) {
                    continue;
                }

                $bills++;
                $minor += $document->outstanding->minor;
                $soonest = $soonest === null || $document->dueDate->lt($soonest) ? $document->dueDate : $soonest;
            }
        }

        if ($bills === 0) {
            return [];
        }

        return [$this->item(
            'week',
            $soonest->lte($today->copy()->addDay()) ? AttentionItem::HIGH : AttentionItem::NORMAL,
            'payables_due',
            '{count} supplier bills (Tk {amount}) fall due in the next {days} days, the first on {date}.',
            ['count' => $bills, 'amount' => self::money($minor / 100), 'days' => self::DUE_DAYS, 'date' => $soonest->format('j M Y')],
            '/accounting/payables-ageing',
            ['ownerRole' => 'Finance', 'count' => $bills],
        )];
    }
}
