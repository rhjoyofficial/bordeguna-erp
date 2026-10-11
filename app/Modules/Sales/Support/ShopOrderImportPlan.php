<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Customer;

/**
 * What a shop order sheet would do, worked out and not yet done.
 *
 * ⚠ The preview is a picture of this; Apply builds it again from the bytes on
 * disk and acts on the new one, never on anything carried through the form.
 */
final class ShopOrderImportPlan
{
    /**
     * @param  array<string, array<string, mixed>>  $orders  order_ref => the order to create (see PlanShopOrderImport)
     * @param  list<array{order_ref: string, sales_order_id: int, order_number: string}>  $skipped  already imported
     * @param  list<array{line: int|null, order_ref: string|null, field: string, message: string}>  $errors
     * @param  list<array{line: int|null, order_ref: string|null, message: string}>  $warnings
     * @param  list<string>  $ignoredColumns
     */
    public function __construct(
        public readonly array $orders,
        public readonly array $skipped,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $ignoredColumns,
        public readonly int $rows,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<string> advance methods that carry money, for the mapping selects */
    public function advanceMethods(): array
    {
        return collect($this->orders)
            ->filter(fn (array $order) => $order['advance']->minor > 0 && $order['advance_method'] !== null)
            ->pluck('advance_method')->unique()->sort()->values()->all();
    }

    /** @return array<string, mixed> for the preview screen, with no models in it */
    public function toArray(): array
    {
        $orders = collect($this->orders)->map(fn (array $order) => [
            'order_ref' => $order['order_ref'],
            'line' => $order['line'],
            'order_date' => $order['order_date'],
            'brand' => $order['brand_name'],
            'channel' => $order['channel'],
            'customer_name' => $order['customer_name'],
            'phone' => $order['phone'],
            'customer' => $order['customer'] instanceof Customer
                ? ['id' => $order['customer']->id, 'name' => $order['customer']->name, 'existing' => true]
                : ['id' => null, 'name' => $order['customer_name'], 'existing' => false],
            'destination' => $order['destination']->oneLineAddress(),
            'lines' => collect($order['lines'])->map(fn (array $line) => [
                'line' => $line['line'],
                'sku' => $line['product'] instanceof Product ? $line['product']->sku : $line['sku'],
                'name' => $line['product'] instanceof Product ? $line['product']->name : null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price']->toTaka(),
                'list_price' => $line['list_price']?->toTaka(),
                'overridden' => $line['override'],
                'discount' => $line['discount']->toTaka(),
                'net' => $line['net']->toTaka(),
            ])->values()->all(),
            'goods' => $order['goods']->toTaka(),
            'order_discount' => $order['order_discount']->toTaka(),
            'delivery_charge' => $order['delivery_charge']?->toTaka(),
            'total' => $order['total']->toTaka(),
            'advance' => $order['advance']->toTaka(),
            'advance_method' => $order['advance_method'],
            'payment_method' => $order['payment_method'],
            'cod' => Money::ofMinor(max(0, $order['total']->minor - $order['advance']->minor))->toTaka(),
            'weight_grams' => $order['weight_grams'],
        ])->values()->all();

        return [
            'rows' => $this->rows,
            'orders' => $orders,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'ignored_columns' => $this->ignoredColumns,
            'advance_methods' => $this->advanceMethods(),
            'totals' => [
                'orders' => count($this->orders),
                'lines' => (int) collect($this->orders)->sum(fn (array $o) => count($o['lines'])),
                'new_customers' => collect($this->orders)->filter(fn (array $o) => ! $o['customer'] instanceof Customer)->pluck('phone')->unique()->count(),
                'goods' => Money::sum(collect($this->orders)->pluck('goods'))->toTaka(),
                'total' => Money::sum(collect($this->orders)->pluck('total'))->toTaka(),
                'advance' => Money::sum(collect($this->orders)->pluck('advance'))->toTaka(),
            ],
        ];
    }
}
