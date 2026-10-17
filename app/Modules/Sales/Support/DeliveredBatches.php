<?php

namespace App\Modules\Sales\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which batches a challan line actually took, for the printed challan and the
 * printed invoice (owner, 17 Oct 2026: a recall starts from the paper the
 * customer holds, so the batch number and its expiry belong on it).
 *
 * ⚠ READ FROM THE STOCK MOVEMENTS THE LINE PRODUCED (delivery_line_movements),
 * never from the batch FEFO would pick today: one line can span two batches,
 * and a reprint a year later must still name the batch that left.
 *
 * Batches with no number are left out: there is nothing to print for them.
 */
final class DeliveredBatches
{
    /**
     * @param  iterable<int>  $deliveryLineIds
     * @return array<int, list<array{batch_number: string, expiry_date: ?string, quantity: string}>>
     *                                                                                               delivery line id => its batches, oldest expiry first
     */
    public static function forLines(iterable $deliveryLineIds): array
    {
        $ids = collect($deliveryLineIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = DB::table('delivery_line_movements')
            ->join('stock_movements', 'stock_movements.id', '=', 'delivery_line_movements.stock_movement_id')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_movements.batch_id')
            ->whereIn('delivery_line_movements.delivery_line_id', $ids)
            ->whereNotNull('stock_batches.batch_number')
            ->where('stock_batches.batch_number', '!=', '')
            ->groupBy('delivery_line_movements.delivery_line_id', 'stock_batches.id', 'stock_batches.batch_number', 'stock_batches.expiry_date')
            ->orderBy('delivery_line_movements.delivery_line_id')
            ->orderByRaw('stock_batches.expiry_date IS NULL, stock_batches.expiry_date')
            ->orderBy('stock_batches.id')
            ->selectRaw('delivery_line_movements.delivery_line_id as line_id, stock_batches.batch_number, stock_batches.expiry_date, SUM(ABS(stock_movements.quantity)) as quantity')
            ->get();

        $byLine = [];

        foreach ($rows as $row) {
            $byLine[(int) $row->line_id][] = [
                'batch_number' => (string) $row->batch_number,
                // A date written in words, the way the invoice prints its own.
                'expiry_date' => $row->expiry_date === null ? null : CarbonImmutable::parse($row->expiry_date)->format('d M Y'),
                'quantity' => (string) $row->quantity,
            ];
        }

        return $byLine;
    }
}
