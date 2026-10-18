<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\StaffPurchaseEvent;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * ⚠ P17 (18 Oct 2026, practice run): the Audit tab's Details column printed the
 * stored JSON, e.g. {"total":"566.31","batches":[9],"invoice_id":7,...}. Ids
 * and keys mean nothing to HR. Each event's details become labelled parts
 * ("total 566.31", "batch PRN-2026-27-0002", "invoice INV-..." with a link),
 * with names looked up in one query per kind for the whole page.
 *
 * Each part is `key` (a dictionary word the screen translates), `value`, and
 * an optional `href`. The stored JSON is untouched: this is how it is READ.
 */
final class StaffPurchaseAuditDetails
{
    /**
     * @param  Collection<int, StaffPurchaseEvent>  $events
     * @return array<int, list<array{key: string, value: string, href?: string}>> event id => parts
     */
    public static function for(Collection $events): array
    {
        $details = $events->mapWithKeys(fn (StaffPurchaseEvent $event) => [$event->id => is_array($event->details) ? $event->details : []]);

        $productIds = $details->flatMap(fn (array $d) => array_merge(
            isset($d['product_id']) ? [(int) $d['product_id']] : [],
            collect($d['lines'] ?? [])->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all(),
        ))->unique()->values();
        $batchIds = $details->flatMap(fn (array $d) => array_map('intval', (array) ($d['batches'] ?? [])))->unique()->values();
        $warehouseIds = $details->pluck('warehouse_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $products = $productIds->isEmpty() ? collect() : Product::query()->whereIn('id', $productIds)->pluck('name', 'id');
        $batches = $batchIds->isEmpty() ? collect() : StockBatch::query()->whereIn('id', $batchIds)->pluck('batch_number', 'id');
        $warehouses = $warehouseIds->isEmpty() ? collect() : Warehouse::query()->whereIn('id', $warehouseIds)->pluck('name', 'id');

        $product = fn ($id) => $products[(int) $id] ?? "#{$id}";

        return $events->mapWithKeys(function (StaffPurchaseEvent $event) use ($details, $product, $batches, $warehouses) {
            $d = $details[$event->id];
            $parts = [];

            $add = function (string $key, $value, ?string $href = null) use (&$parts) {
                if ($value === null || $value === '' || $value === []) {
                    return;
                }
                $part = ['key' => $key, 'value' => (string) $value];
                if ($href !== null) {
                    $part['href'] = $href;
                }
                $parts[] = $part;
            };

            foreach ((array) ($d['lines'] ?? []) as $line) {
                $add('product', $product($line['product_id'] ?? '?'));
                $add('price', self::money($line['staff_price'] ?? $line['estimate'] ?? null));
                $add('was', self::money($line['was'] ?? null));
                $add('reference', self::money($line['reference_price'] ?? null));
            }

            if (isset($d['product_id'])) {
                $add('product', $product($d['product_id']));
            }
            $add('quantity', isset($d['quantity']) ? self::quantity($d['quantity']) : null);
            $add('total', self::money($d['total'] ?? null));
            $add('amount', self::money($d['amount'] ?? null));
            $add('cost', self::money($d['cost'] ?? null));

            foreach (array_map('intval', (array) ($d['batches'] ?? [])) as $batchId) {
                $add('batch', $batches[$batchId] ?? "#{$batchId}", route('inventory.batches.show', $batchId, false));
            }

            if (isset($d['invoice_id'])) {
                $add('invoice', $d['invoice_number'] ?? "#{$d['invoice_id']}", route('sales.invoices.show', (int) $d['invoice_id'], false));
            }

            if (isset($d['warehouse_id'])) {
                $add('warehouse', $warehouses[(int) $d['warehouse_id']] ?? "#{$d['warehouse_id']}");
            }

            $add('payment_mode', $d['payment_mode'] ?? null);
            // D-D (18 Oct 2026): a credit purchase carries its days and due date.
            $add('credit_days', $d['credit_days'] ?? null);
            $add('due_date', $d['due_date'] ?? null);
            $add('payroll', $d['payroll'] ?? (isset($d['version']) ? 'v'.$d['version'] : null));
            $add('reason', $d['reason'] ?? null);
            $add('evidence', $d['evidence'] ?? null);

            return [$event->id => $parts];
        })->all();
    }

    private static function money($value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2);
    }

    private static function quantity($value): string
    {
        $text = number_format((float) $value, 4);

        return rtrim(rtrim($text, '0'), '.');
    }
}
