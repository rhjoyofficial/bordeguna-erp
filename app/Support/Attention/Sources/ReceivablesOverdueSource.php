<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingDocument;
use App\Modules\Accounting\Support\AgeingRow;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/** Customer invoices more than OVERDUE_DAYS days past due, from Ageing::receivables (posted). */
class ReceivablesOverdueSource extends Source
{
    public const OVERDUE_DAYS = 30;

    public function key(): string
    {
        return 'receivables_overdue';
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
        $documents = 0;
        $customers = 0;
        $minor = 0;
        $oldest = null;

        foreach (Ageing::receivables(Carbon::today())->rows as $row) {
            /** @var AgeingRow $row */
            $late = $row->documents->filter(fn (AgeingDocument $d) => $d->daysOverdue > self::OVERDUE_DAYS && $d->outstanding->minor > 0);

            if ($late->isEmpty()) {
                continue;
            }

            $customers++;
            $documents += $late->count();

            foreach ($late as $document) {
                $minor += $document->outstanding->minor;
                $oldest = $oldest === null || $document->basisDate()->lt($oldest) ? $document->basisDate() : $oldest;
            }
        }

        if ($documents === 0) {
            return [];
        }

        return [$this->item(
            'over30',
            AttentionItem::HIGH,
            'receivables_overdue',
            '{count} invoices from {customers} customers are more than {days} days overdue (Tk {amount}).',
            ['count' => $documents, 'customers' => $customers, 'days' => self::OVERDUE_DAYS, 'amount' => self::money($minor / 100)],
            '/accounting/receivables-ageing',
            ['since' => $oldest, 'ownerRole' => 'Finance', 'count' => $documents],
        )];
    }
}
