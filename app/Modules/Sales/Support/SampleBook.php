<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Models\SampleIssueLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Samples (25 Oct 2026): how one sample reads on any screen (the Samples
 * list, a customer's or an employee's page), so the rows never differ.
 *
 * ⚠ Cost is shown only to who may see what goods cost (CostVisibility's rule:
 * sales.reports.costs, or the people who give and approve samples). The
 * quantities, the purpose and the delivery charge are for everyone who may
 * open the list.
 */
final class SampleBook
{
    /** @return array<string, mixed> */
    public static function row(SampleIssue $issue, ?User $viewer): array
    {
        $seesCost = self::seesCost($viewer);

        return [
            'id' => $issue->id,
            'number' => $issue->number,
            'given_on' => $issue->given_on->toDateString(),
            'recipient' => $issue->recipient_name,
            'recipient_phone' => $issue->recipient_phone,
            'recipient_kind' => $issue->recipientKind() ?? 'none',
            'recipient_url' => $issue->recipientUrl(),
            'purpose' => $issue->purpose,
            'purpose_note' => $issue->purpose_note,
            'warehouse' => $issue->warehouse?->name,
            'items' => $issue->lines->map(fn (SampleIssueLine $line) => [
                'product_id' => $line->product_id,
                'product' => $line->product?->name,
                'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                'unit' => $line->product?->unitOfMeasure?->abbreviation,
                'batch' => $line->batch?->batch_number,
            ])->values()->all(),
            'cost' => $seesCost && $issue->isPosted() ? $issue->costMoney()->format() : null,
            'delivery_method' => $issue->delivery_method,
            'delivery_charge' => $issue->deliveryMoney()->isZero() ? null : $issue->deliveryMoney()->format(),
            'delivery_to' => $issue->courierMoneyAccount?->name ?? $issue->deliveryMoneyAccount?->name,
            'feedback_on' => $issue->feedback_on?->toDateString(),
            'given_by' => $issue->givenBy?->name,
            'status' => $issue->isReversed() ? 'reversed' : $issue->status,
            'note' => $issue->note,
        ];
    }

    /** Eager loads every row needs. */
    public static function with(Builder $query): Builder
    {
        return $query->with([
            'lines.product:id,name,unit_of_measure_id', 'lines.product.unitOfMeasure:id,abbreviation', 'lines.batch:id,batch_number',
            'warehouse:id,name', 'givenBy:id,name', 'deliveryMoneyAccount:id,name', 'courierMoneyAccount:id,name',
        ]);
    }

    /**
     * What one person received: their samples, newest first, and the totals.
     *
     * @return array{rows: list<array<string, mixed>>, count: int, cost: ?string, delivery: string, url: string}
     */
    public static function forRecipient(Model $recipient, ?User $viewer): array
    {
        $issues = self::with(SampleIssue::query()
            ->where('recipient_type', $recipient->getMorphClass())
            ->where('recipient_id', $recipient->getKey())
            ->whereIn('status', [SampleIssue::POSTED, SampleIssue::WAITING])
            ->orderByDesc('given_on')->orderByDesc('id'))
            ->limit(50)
            ->get();

        $live = $issues->filter(fn (SampleIssue $issue) => $issue->isPosted() && ! $issue->isReversed());
        $kind = (new SampleIssue(['recipient_type' => $recipient->getMorphClass()]))->recipientKind();

        return [
            'rows' => $issues->map(fn (SampleIssue $issue) => self::row($issue, $viewer))->values()->all(),
            'count' => $live->count(),
            'cost' => self::seesCost($viewer) ? Money::ofMinor((int) $live->sum('cost_total_paisa'))->format() : null,
            'delivery' => Money::sum($live->map(fn (SampleIssue $issue) => $issue->deliveryMoney())->all())->format(),
            'url' => '/sales/samples?recipient_type='.$kind.'&recipient_id='.$recipient->getKey(),
        ];
    }

    public static function seesCost(?User $viewer): bool
    {
        return $viewer !== null && ($viewer->can('sales.reports.costs') || $viewer->can('sales.correct') || $viewer->can('accounting.view'));
    }
}
