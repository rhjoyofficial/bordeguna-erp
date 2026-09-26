<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Store;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Carbon;

/**
 * What the ERP says an order HQ proposed would cost, at one moment (HQ docs/79 C.4, C.8, C.12).
 *
 * ⚠ The ERP's answer only. Nothing here was proposed by HQ; every figure came from the one pricing
 * engine, `DeliveryQuote` and `Availability`, asked by `HqOrderPricing`. Whoever holds one of these
 * compares HQ's figures against it; nobody rewrites it to match HQ's.
 */
final readonly class PricedHqOrder
{
    /**
     * @param  list<array{index: int, product: Product, product_id: int, sku: string, quantity: int, unit_price: string, line_total: string, price_note: string, made_to_order: bool}>  $lines
     */
    public function __construct(
        public Store $store,
        public SalesOrderDestination $destination,
        public string $phone,
        public array $lines,
        public Money $goods,
        public Money $delivery,
        public Carbon $pricedAt,
    ) {}

    public function agreed(): Money
    {
        return $this->goods->plus($this->delivery);
    }
}
