<?php

namespace App\Modules\Purchasing\Notifications;

use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One message a day to whoever can pay the bills, naming what is about to be due.
 *
 * ⚠⚠ A DIGEST, NOT ONE PER BILL - the same rule the CRM follow-up digest
 * follows. Ten reminders in a morning are ten things to dismiss.
 *
 * ⚠ Overdue is stated SEPARATELY rather than folded into the total. "3 due"
 * reads as this week's work; "1 of them already late" is the sentence that
 * changes what somebody does before lunch.
 */
class RecurringExpensesDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /** @var list<array{name:string, period:string, due_on:string, amount:string, overdue:bool}> */
    public array $items = [];

    public int $overdue = 0;

    public float $total = 0.0;

    /**
     * @param  list<RecurringExpenseOccurrence>  $occurrences
     */
    public function __construct(array $occurrences)
    {
        $today = CarbonImmutable::today();

        foreach ($occurrences as $occurrence) {
            $overdue = $occurrence->due_on->lt($today);

            $this->items[] = [
                'name' => (string) ($occurrence->recurringExpense?->name ?? 'Standing cost'),
                'period' => (string) $occurrence->period_label,
                'due_on' => $occurrence->due_on->toDateString(),
                'amount' => number_format((float) $occurrence->expected_amount, 2),
                'overdue' => $overdue,
            ];

            $this->overdue += $overdue ? 1 : 0;
            $this->total += (float) $occurrence->expected_amount;
        }

        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'recurring_expenses_due',
            'summary' => $this->summary(),
            'url' => '/purchasing/recurring-expenses',
            'count' => count($this->items),
            'overdue' => $this->overdue,
            'total' => number_format($this->total, 2),
            'items' => $this->items,
        ];
    }

    public function summary(): string
    {
        $count = count($this->items);

        $summary = $count.' standing cost'.($count === 1 ? '' : 's').' due, '.number_format($this->total, 2).' in total';

        if ($this->overdue > 0) {
            $summary .= ', '.$this->overdue.' already overdue';
        }

        $named = array_slice(array_map(
            fn (array $item) => $item['name'].' '.$item['period'].' on '.$item['due_on'],
            $this->items,
        ), 0, 4);

        return $summary.': '.implode('; ', $named);
    }
}
