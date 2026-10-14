<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Store;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Sales\Couriers\Booking\ParcelDraft;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\DiscountCap;
use App\Modules\Sales\Support\SalesOrderDestination;
use App\Modules\Sales\Support\ShopOrderImportPlan;
use App\Modules\Sales\Support\ShopOrderSheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a shop order sheet into orders, and says everything wrong with it,
 * without writing anything (Joy, 11 Oct 2026).
 *
 * ⚠⚠ EVERY CHECK THE ORDER WOULD MEET LATER IS MET HERE FIRST, with the row
 * number: a destination the courier hub cannot route, a phone the courier will
 * refuse, a price the person may not set. An import that fails half way is
 * worse than one refused at the preview.
 *
 * ⭐ Idempotent by the shop's order number. An order_ref already on an order
 * (`sales_orders.shop_reference`, unique) is SKIPPED with a link, so the same
 * day's sheet sent twice, or a sheet that grew since the morning, imports only
 * what is new.
 */
class PlanShopOrderImport
{
    /** Columns that belong to the order; a later line may repeat them or leave them blank. */
    private const ORDER_COLUMNS = [
        'order_date', 'brand', 'channel', 'customer_name', 'phone', 'alt_phone', 'district', 'thana', 'area',
        'address_line', 'landmark', 'order_discount', 'delivery_charge', 'advance_paid', 'advance_method',
        'advance_reference', 'payment_method', 'note', 'weight_grams',
    ];

    /** @var list<array{line: int|null, order_ref: string|null, field: string, message: string}> */
    private array $errors = [];

    /** @var list<array{line: int|null, order_ref: string|null, message: string}> */
    private array $warnings = [];

    public function __construct(private readonly ResolveSellingPrice $prices) {}

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @param  list<string>  $ignoredColumns
     */
    public function handle(array $rows, array $ignoredColumns, User $by, int $businessId): ShopOrderImportPlan
    {
        $this->errors = [];
        $this->warnings = [];

        foreach ($ignoredColumns as $column) {
            $this->warn(null, null, "Column \"{$column}\" is not part of the template and was not read.");
        }

        $groups = [];

        foreach ($rows as $row) {
            $ref = trim($row['values']['order_ref']);

            if ($ref === '') {
                $this->error($row['line'], null, 'order_ref', 'Every line needs the shop order number (order_ref).');

                continue;
            }

            if (mb_strlen($ref) > 40) {
                $this->error($row['line'], $ref, 'order_ref', 'The order number is longer than 40 characters.');

                continue;
            }

            $groups[$ref][] = $row;
        }

        $existing = SalesOrder::query()->withoutGlobalScopes()
            ->whereIn('shop_reference', array_map('strval', array_keys($groups)))
            ->get(['id', 'business_id', 'order_number', 'shop_reference'])
            ->keyBy(fn (SalesOrder $o) => (string) $o->shop_reference);

        $products = $this->products($businessId, $rows);
        $brands = Brand::query()->withoutGlobalScopes()->where('business_id', $businessId)->where('is_active', true)->get();

        $orders = [];
        $skipped = [];

        foreach ($groups as $ref => $lines) {
            $ref = (string) $ref;
            $already = $existing->get($ref);

            if ($already !== null) {
                if ((int) $already->business_id !== $businessId) {
                    $this->error($lines[0]['line'], $ref, 'order_ref', "Order number {$ref} is already used by another business in the ERP.");
                } else {
                    $skipped[] = ['order_ref' => $ref, 'sales_order_id' => (int) $already->id, 'order_number' => (string) $already->order_number];
                }

                continue;
            }

            $order = $this->order($ref, $lines, $products, $brands, $by, $businessId);

            if ($order !== null) {
                $orders[$ref] = $order;
            }
        }

        return new ShopOrderImportPlan(
            orders: $orders,
            skipped: $skipped,
            errors: $this->errors,
            warnings: $this->warnings,
            ignoredColumns: $ignoredColumns,
            rows: count($rows),
        );
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $lines
     * @param  Collection<string, Product>  $products
     * @param  Collection<int, Brand>  $brands
     * @return array<string, mixed>|null null when the order has errors
     */
    private function order(string $ref, array $lines, Collection $products, Collection $brands, User $by, int $businessId): ?array
    {
        $before = count($this->errors);
        $first = $lines[0]['line'];
        $values = $this->orderValues($ref, $lines);

        $date = $this->date($values['order_date'], $first, $ref);

        // Brand: a name or a code, of this business, active.
        $brand = null;

        if ($values['brand'] !== '') {
            $key = self::brandKey($values['brand']);
            $brand = $brands->first(fn (Brand $b) => self::brandKey((string) $b->name) === $key || ($b->code !== null && self::brandKey((string) $b->code) === $key));

            if ($brand === null) {
                $this->error($first, $ref, 'brand', "Brand \"{$values['brand']}\" is not an active brand of this business".($brands->isEmpty() ? '.' : ' (choose: '.$brands->map(fn (Brand $b) => $b->name.($b->code ? " / {$b->code}" : ''))->implode(', ').').'));
            }
        } elseif ($brands->isNotEmpty()) {
            $this->error($first, $ref, 'brand', 'Choose the brand (for example '.$brands->first()->name.').');
        }

        $channel = strtolower($values['channel']);

        if ($channel === '') {
            $channel = 'other';
        } elseif (! in_array($channel, ShopOrderSheet::CHANNELS, true)) {
            $this->error($first, $ref, 'channel', "Channel \"{$values['channel']}\" is not one of: ".implode(', ', ShopOrderSheet::CHANNELS).'.');
        }

        $phone = $this->phone($values['phone'], $first, $ref, 'phone', required: true);
        $altPhone = $values['alt_phone'] === '' ? null : $this->phone($values['alt_phone'], $first, $ref, 'alt_phone', required: false);

        if (mb_strlen($values['customer_name']) < ParcelDraft::NAME_MIN) {
            $this->error($first, $ref, 'customer_name', 'The customer name is missing or shorter than 3 letters.');
        }

        $destination = null;

        try {
            $destination = SalesOrderDestination::fromInput([
                'name' => mb_substr($values['customer_name'], 0, 255),
                'phone' => $phone ?? $values['phone'],
                'district' => $values['district'],
                'thana' => $values['thana'],
                'area' => $values['area'],
                'address_line' => mb_substr($values['address_line'], 0, 500),
                'landmark' => mb_substr($values['landmark'], 0, 255),
            ]);

            if ($values['area'] !== '' && $destination->area === null) {
                $this->warn($first, $ref, "Area \"{$values['area']}\" is not in the ERP's list for {$destination->thana}, so it is left out of the address fields (it stays readable if it is in address_line).");
            }

            if (mb_strlen($values['address_line']) > 500) {
                $this->warn($first, $ref, 'The address was longer than 500 letters and is cut.');
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $field = $field === 'name' ? 'customer_name' : $field;
                $hint = in_array($field, ['district', 'thana'], true) ? " (\"{$values[$field]}\")" : '';
                $this->error($first, $ref, $field, rtrim($messages[0], '.').$hint.'.');
            }
        }

        // Where the price comes from: a store of this brand prices the order the
        // way its website would (ADR-005); otherwise the customer's own group.
        $store = $brand === null ? null : Store::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)->where('brand_id', $brand->id)->orderBy('id')->first();
        $customer = $phone === null ? null : Customer::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)->where('phone_normalized', $phone)
            ->where('is_active', true)->where('is_system', false)->orderBy('id')->first();

        if ($customer !== null && mb_strtolower(trim($customer->name)) !== mb_strtolower($values['customer_name'])) {
            $this->warn($first, $ref, "Phone {$phone} belongs to customer \"{$customer->name}\"; the order goes to that customer, addressed to \"{$values['customer_name']}\".");
        }

        $lineRows = [];

        foreach ($lines as $row) {
            $line = $this->line($row, $ref, $products, $customer, $store, $date, $by);

            if ($line !== null) {
                $lineRows[] = $line;
            }
        }

        $goods = Money::sum(array_map(fn (array $l) => $l['net'], $lineRows));
        $orderDiscount = $this->money($values['order_discount'], $first, $ref, 'order_discount') ?? Money::zero();
        $delivery = $values['delivery_charge'] === '' ? null : $this->money($values['delivery_charge'], $first, $ref, 'delivery_charge');
        $advance = $this->money($values['advance_paid'], $first, $ref, 'advance_paid') ?? Money::zero();

        if ($orderDiscount->minor > $goods->minor) {
            $this->error($first, $ref, 'order_discount', "The order discount ({$orderDiscount->format()}) is larger than the goods ({$goods->format()}).");
        }

        $total = Money::ofMinor($goods->minor - $orderDiscount->minor + ($delivery?->minor ?? 0));

        $measured = $this->discountRefusal($ref, $first, $lineRows, $orderDiscount, $by, $businessId);

        $method = strtolower($values['advance_method']);
        $method = $method === '' ? null : $method;

        if ($advance->minor > 0 && $method === null) {
            $this->error($first, $ref, 'advance_method', 'Say how the advance was paid ('.implode(', ', ShopOrderSheet::ADVANCE_METHODS).').');
        } elseif ($method !== null && ! in_array($method, ShopOrderSheet::ADVANCE_METHODS, true)) {
            $this->error($first, $ref, 'advance_method', "Advance method \"{$values['advance_method']}\" is not one of: ".implode(', ', ShopOrderSheet::ADVANCE_METHODS).'.');
        }

        if ($advance->minor > $total->minor) {
            $this->error($first, $ref, 'advance_paid', "The advance ({$advance->format()}) is more than the order ({$total->format()}).");
        }

        if (mb_strlen($values['advance_reference']) > 255) {
            $this->error($first, $ref, 'advance_reference', 'The advance reference is longer than 255 characters.');
        }

        $payment = strtolower($values['payment_method']) ?: 'cod';

        if (! in_array($payment, ShopOrderSheet::PAYMENT_METHODS, true)) {
            $this->error($first, $ref, 'payment_method', "Payment method \"{$values['payment_method']}\" is not cod or prepaid.");
        } elseif ($payment === 'prepaid' && $advance->minor < $total->minor) {
            $this->warn($first, $ref, "Marked prepaid but the advance ({$advance->format()}) is less than the order ({$total->format()}); the courier will be asked to collect the rest.");
        }

        $weight = null;

        if ($values['weight_grams'] !== '') {
            if (! ctype_digit($values['weight_grams']) || (int) $values['weight_grams'] < 1 || (int) $values['weight_grams'] > ProductWeight::MAX_GRAMS) {
                $this->error($first, $ref, 'weight_grams', 'The weight must be whole grams between 1 and '.ProductWeight::MAX_GRAMS.'.');
            } else {
                $weight = (int) $values['weight_grams'];
            }
        }

        if ($lineRows === [] && count($this->errors) === $before) {
            $this->error($first, $ref, 'sku', 'The order has no product lines.');
        }

        if (count($this->errors) !== $before || $destination === null || $date === null) {
            return null;
        }

        return [
            'order_ref' => $ref,
            'line' => $first,
            'order_date' => $date->toDateString(),
            'brand_id' => $brand?->id,
            'brand_name' => $brand?->name,
            'channel' => $channel,
            'customer_name' => $values['customer_name'],
            'phone' => $phone,
            'alt_phone' => $altPhone,
            'customer' => $customer,
            'store' => $store,
            'destination' => $destination,
            'lines' => $lineRows,
            'goods' => $goods,
            'order_discount' => $orderDiscount,
            'discount_given' => Money::ofMinor($measured['given']),
            'discount_goods' => Money::ofMinor($measured['goods']),
            'delivery_charge' => $delivery,
            'total' => $total,
            'advance' => $advance,
            'advance_method' => $advance->minor > 0 ? $method : null,
            'advance_reference' => $values['advance_reference'] === '' ? null : $values['advance_reference'],
            'payment_method' => $payment,
            'note' => $values['note'] === '' ? null : $values['note'],
            'weight_grams' => $weight,
        ];
    }

    /**
     * ⚠⚠ Rule #3 (Joy, 14 Oct 2026, after the 13 Oct audit): an imported
     * order's discount follows the COUNTER's policy, not a looser one of its
     * own. Before this a sheet could carry any line or order discount with no
     * permission and no cap, so the import was the way round the counter.
     *
     * - Any discount (a line's or the order's) needs `sales.price.override`,
     *   exactly as the counter asks it for a line or a bill discount. A price
     *   below the list already needs it (line()).
     * - The cap is DiscountCap's, measured on the WHOLE order: price cuts
     *   below the list + every line discount + the order discount, against the
     *   goods at list price. One sum, so splitting a discount across lines or
     *   between a line and the order cannot slip under it. The Owner has no
     *   cap there and none here; nobody else may go over it.
     * - A line priced by the sheet with NO list price is measured against the
     *   price the sheet gave, so its line discount still counts.
     * - Nothing is promotion or coupon here: the counter leaves out only what
     *   the ERP itself applies (a promotion's free line), and a sheet's number
     *   is typed, not applied, so all of it counts.
     *
     * Refused at the preview with the reason, so nothing is half imported.
     *
     * @param  list<array<string, mixed>>  $lineRows
     * @return array{goods: int, given: int} in paisa, kept on the order note for audit
     */
    private function discountRefusal(string $ref, int $first, array $lineRows, Money $orderDiscount, User $by, int $businessId): array
    {
        $capLines = array_map(fn (array $l) => [
            'quantity' => $l['quantity'],
            'unitPrice' => $l['unit_price']->toTaka(),
            'discount' => $l['discount']->toTaka(),
            'listPrice' => ($l['list_price'] ?? $l['unit_price'])->toTaka(),
        ], $lineRows);

        $measured = DiscountCap::measure($capLines, $orderDiscount->toTaka());
        $discounted = $orderDiscount->minor > 0 || collect($lineRows)->contains(fn (array $l) => $l['discount']->minor > 0);

        if ($discounted && ! $by->can('sales.price.override')) {
            $this->error($first, $ref, 'order_discount', "Order {$ref} carries a discount. Giving a discount needs the price-override permission, as at the counter; import it without the discount, or ask a Manager.");
        } elseif ($measured['given'] > 0) {
            $overCap = DiscountCap::refusal($by, $businessId, $capLines, $orderDiscount->toTaka());

            if ($overCap !== null) {
                $this->error($first, $ref, 'order_discount', "Order {$ref}: ".$overCap);
            }
        }

        return $measured;
    }

    /**
     * The order-level values: the first line's, filled from later lines where
     * the first left a column blank; a later line that DISAGREES is an error.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $lines
     * @return array<string, string>
     */
    private function orderValues(string $ref, array $lines): array
    {
        $values = [];
        $from = [];

        foreach (self::ORDER_COLUMNS as $column) {
            $values[$column] = '';

            foreach ($lines as $row) {
                $value = $row['values'][$column] ?? '';

                if ($value === '') {
                    continue;
                }

                if ($values[$column] === '') {
                    $values[$column] = $value;
                    $from[$column] = $row['line'];
                } elseif (! $this->same($column, $values[$column], $value)) {
                    $this->error($row['line'], $ref, $column, "Order {$ref} has a different {$column} here (\"{$value}\") than on row {$from[$column]} (\"{$values[$column]}\"). One order, one value.");
                }
            }
        }

        return $values;
    }

    private function same(string $column, string $a, string $b): bool
    {
        if (in_array($column, ['phone', 'alt_phone'], true)) {
            return PhoneIdentity::normalize($a) === PhoneIdentity::normalize($b);
        }

        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * @param  array{line: int, values: array<string, string>}  $row
     * @param  Collection<string, Product>  $products
     * @return array<string, mixed>|null
     */
    private function line(array $row, string $ref, Collection $products, ?Customer $customer, ?Store $store, ?Carbon $date, User $by): ?array
    {
        $line = $row['line'];
        $values = $row['values'];
        $sku = trim($values['sku']);
        $before = count($this->errors);

        $product = $sku === '' ? null : $products->get(mb_strtolower($sku));

        if ($sku === '') {
            $this->error($line, $ref, 'sku', 'The SKU is missing.');
        } elseif ($product === null) {
            $this->error($line, $ref, 'sku', "No product in the ERP has SKU \"{$sku}\".");
        } elseif (! $product->is_active) {
            $this->error($line, $ref, 'sku', "{$product->name} ({$sku}) is switched off in the ERP.");
        } elseif (! $product->type->isStocked()) {
            $this->error($line, $ref, 'sku', "{$product->name} is a service, not a product that ships.");
        }

        $quantity = str_replace(',', '', $values['quantity']);

        if ($quantity === '' || ! preg_match('/^\d+(\.\d{1,4})?$/', $quantity) || (float) $quantity <= 0) {
            $this->error($line, $ref, 'quantity', "Quantity \"{$values['quantity']}\" must be a number greater than 0 (up to 4 decimals).");
        }

        $given = $values['unit_price'] === '' ? null : $this->money($values['unit_price'], $line, $ref, 'unit_price');
        $discount = $this->money($values['line_discount'], $line, $ref, 'line_discount') ?? Money::zero();

        if (count($this->errors) !== $before || $product === null || $date === null) {
            return null;
        }

        $list = null;

        try {
            $resolved = $this->prices->resolve(
                product: $product,
                customer: $store !== null ? null : $customer,
                quantity: $quantity,
                on: $date,
                priceGroupId: $store?->price_group_id,
            );
            $list = Money::ofTaka((string) $resolved->unitPrice);
        } catch (NoSellingPriceException) {
            $list = null;
        }

        $override = $given !== null && ($list === null || $given->minor !== $list->minor);

        if ($given === null && $list === null) {
            $this->error($line, $ref, 'unit_price', "{$product->name} has no selling price on {$date->toDateString()}. Give unit_price, or set the price first.");

            return null;
        }

        if ($override && ! $by->can('sales.price.override')) {
            $this->error($line, $ref, 'unit_price', "{$product->name}: the sheet says {$given->format()}, the price list says ".($list?->format() ?? 'nothing').'. Changing a price needs the price-override permission; leave unit_price blank to use the list.');

            return null;
        }

        if ($override) {
            $this->warn($line, $ref, "{$product->name}: price {$given->format()} from the shop instead of the list ".($list?->format() ?? '(none)').'.');
        }

        $price = $override ? $given : $list;
        $gross = Money::ofProduct($quantity, $price->toTaka());

        if ($discount->minor > $gross->minor) {
            $this->error($line, $ref, 'line_discount', "The discount on {$product->name} ({$discount->format()}) is larger than the line ({$gross->format()}).");

            return null;
        }

        return [
            'line' => $line,
            'sku' => $sku,
            'product' => $product,
            'quantity' => $quantity,
            'unit_price' => $price,
            'list_price' => $list,
            'override' => $override,
            'discount' => $discount,
            'net' => Money::ofMinor($gross->minor - $discount->minor),
        ];
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return Collection<string, Product> lower-case SKU => product
     */
    private function products(int $businessId, array $rows): Collection
    {
        $skus = collect($rows)->map(fn (array $r) => trim($r['values']['sku']))->filter()->unique()->values();

        if ($skus->isEmpty()) {
            return collect();
        }

        // ⚠ MySQL compares SKUs without case, SQLite with it: lower() on both
        // sides makes the two agree.
        return Product::query()->withoutGlobalScopes()->where('business_id', $businessId)
            ->whereIn(DB::raw('lower(sku)'), $skus->map(fn (string $s) => mb_strtolower($s))->all())->get()
            ->keyBy(fn (Product $p) => mb_strtolower((string) $p->sku));
    }

    private function date(string $value, int $line, string $ref): ?Carbon
    {
        $value = trim($value);
        $date = null;

        try {
            if (preg_match('/^\d{5}(\.\d+)?$/', $value) === 1) {
                // An Excel date cell with no date format arrives as its serial number.
                $date = Carbon::create(1899, 12, 30)->addDays((int) $value);
            } elseif (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m)) {
                $date = checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? Carbon::create((int) $m[1], (int) $m[2], (int) $m[3]) : null;
            } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?:\s.*)?$/', $value, $m)) {
                $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
                $date = checkdate((int) $m[2], (int) $m[1], $year) ? Carbon::create($year, (int) $m[2], (int) $m[1]) : null;
            }
        } catch (Throwable) {
            $date = null;
        }

        if ($date === null) {
            $this->error($line, $ref, 'order_date', $value === '' ? 'The order date is missing.' : "\"{$value}\" is not a date. Write 2026-10-10 or 10/10/2026.");

            return null;
        }

        $date = $date->startOfDay();

        if ($date->greaterThan(Carbon::today())) {
            $this->error($line, $ref, 'order_date', "The order date {$date->toDateString()} is in the future.");

            return null;
        }

        return $date;
    }

    private function phone(string $value, int $line, string $ref, string $field, bool $required): ?string
    {
        $normalized = PhoneIdentity::normalize($value);

        if ($normalized === null) {
            if ($required) {
                $this->error($line, $ref, $field, 'The phone number is missing.');
            }

            return null;
        }

        if (preg_match(ParcelDraft::PHONE_PATTERN, $normalized) !== 1) {
            $this->error($line, $ref, $field, "\"{$value}\" is not a Bangladeshi mobile number (01XXXXXXXXX).");

            return null;
        }

        return $normalized;
    }

    private function money(string $value, int $line, string $ref, string $field): ?Money
    {
        $value = trim(str_ireplace(['৳', 'tk', 'bdt'], '', $value));

        if ($value === '') {
            return null;
        }

        try {
            $money = Money::ofTaka($value);
        } catch (InvalidArgumentException) {
            $this->error($line, $ref, $field, "\"{$value}\" is not an amount.");

            return null;
        }

        if ($money->minor < 0) {
            $this->error($line, $ref, $field, 'An amount cannot be negative.');

            return null;
        }

        return $money;
    }

    public static function brandKey(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name));
    }

    private function error(?int $line, ?string $ref, string $field, string $message): void
    {
        $this->errors[] = ['line' => $line, 'order_ref' => $ref, 'field' => $field, 'message' => $message];
    }

    private function warn(?int $line, ?string $ref, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'order_ref' => $ref, 'message' => $message];
    }
}
