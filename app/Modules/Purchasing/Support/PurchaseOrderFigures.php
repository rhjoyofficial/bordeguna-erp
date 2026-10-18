<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Exceptions\UnitNotConvertibleException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\UnitConverter;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ⭐ The arithmetic of the full purchase order page (purchase redesign PR1,
 * 18 Oct 2026), Tally's purchase voucher order: items, discount, VAT, the
 * additional costs, rounding, the party's total.
 *
 * Per line, in paisa, integers only:
 *   gross      what was typed: quantity x rate, or the typed total (in the
 *              unit it was typed in);
 *   discount   the line's own (% of gross, rounded half up, or an amount);
 *   share      of the order discount, spread by each line's value after its
 *              own discount (the largest line takes the rounding remainder);
 *   VAT        of what is left (gross - discount - share) at the line's rate.
 *
 * ⚠⚠ WHAT IS STORED AS THE LINE'S VALUE (`line_total_paisa`, which GRNI, the
 * receipt's batch cost and the bill's three-way match read) is the GROSS, with
 * the VAT added only while the business charges no VAT on the order date
 * (C-ACC-1: unit cost is inclusive of whatever tax was paid, so VAT nobody
 * can reclaim is part of what the goods cost). The DISCOUNTS are not taken
 * off it: they reach the stock cost as the bill's trade discount (wave A's
 * rule, RecordSupplierDiscount), which the bill opens with from this order.
 * So nothing that already reads an order line changes meaning.
 *
 * ⚠ The quantity stored is in the PURCHASE unit (what the receipt converts to
 * stock from). A line typed in another unit is converted here, once, through
 * UnitConverter, and the typed quantity and rate are kept beside it.
 *
 * The page works the same figures out in TypeScript for the live preview
 * (lib/purchase-order-figures.ts); the server decides.
 */
final class PurchaseOrderFigures
{
    /** Rounding is a matter of paisa; a bigger cut is a discount. */
    public const MAX_ROUNDING_PAISA = 100;

    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly UnitConverter $units,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines  each: product (Product), quantity, unit_cost?, line_total?, entry_mode?, unit?: ?UnitOfMeasure, description?, discount_type?, discount_value?, tax_rate?
     * @param  array{type?: ?string, value?: ?string}|null  $discount
     * @param  list<array{amount_paisa: int, supplier_id: ?int}>  $charges
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>}
     *
     * @throws ValidationException each refusal under the box it is about
     */
    public function work(array $lines, ?array $discount, array $charges, ?string $rounding, int $supplierId, ?Business $business, Carbon $on): array
    {
        $vatInCost = ! $this->tax->appliesOn($business, $on);
        $problems = [];
        $worked = [];

        foreach (array_values($lines) as $index => $line) {
            try {
                $worked[$index] = $this->line($line, $business, $on);
            } catch (FigureRefused $e) {
                $problems["lines.{$index}.{$e->field}"] = $e->getMessage();
            } catch (InvalidArgumentException $e) {
                $problems["lines.{$index}.".(str_contains($e->getMessage(), 'quantity') ? 'quantity' : 'unit_cost')] = $e->getMessage();
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        // ── The order discount, spread by value after the lines' own ──────
        $bases = array_map(fn (array $line) => $line['gross_paisa'] - $line['discount_paisa'], $worked);
        $base = array_sum($bases);
        $orderDiscount = $this->discountOf($discount['type'] ?? null, $discount['value'] ?? null, $base);

        if ($orderDiscount === null) {
            throw ValidationException::withMessages(['discount_value' => 'A discount percentage has to be more than 0 and less than 100.']);
        }

        if ($orderDiscount > $base) {
            throw ValidationException::withMessages(['discount_value' => 'The order discount is more than the goods are worth.']);
        }

        foreach (self::spread($orderDiscount, $bases) as $index => $share) {
            $worked[$index]['order_discount_paisa'] = $share;
        }

        // ── VAT on what is left, and the value the line is stored at ──────
        $taxTotal = 0;

        foreach ($worked as $index => $line) {
            $net = $line['gross_paisa'] - $line['discount_paisa'] - $line['order_discount_paisa'];
            $vat = $this->tax->split(Money::ofMinor($net), $line['tax_rate'], false)->tax->minor;
            $taxTotal += $vat;

            $value = $line['gross_paisa'] + ($vatInCost ? $vat : 0);
            $worked[$index] = $this->stored($line, $value) + ['tax_amount' => Money::ofMinor($vat)->toTaka(), 'tax_paisa' => $vat];
        }

        $roundingPaisa = trim((string) $rounding) === '' ? 0 : Money::ofTaka((string) $rounding)->minor;

        if (abs($roundingPaisa) > self::MAX_ROUNDING_PAISA) {
            throw ValidationException::withMessages(['rounding' => 'Rounding is at most Tk 1.00 either way. A bigger cut is a discount.']);
        }

        $goods = array_sum(array_column($worked, 'gross_paisa'));
        $discounts = array_sum(array_column($worked, 'discount_paisa')) + $orderDiscount;
        $chargesTotal = array_sum(array_map(fn (array $charge) => (int) $charge['amount_paisa'], $charges));
        $supplierCharges = array_sum(array_map(fn (array $charge) => (int) ($charge['supplier_id'] ?? 0) === $supplierId ? (int) $charge['amount_paisa'] : 0, $charges));

        return [
            'lines' => array_values($worked),
            'totals' => [
                'goods_paisa' => $goods,
                'discount_paisa' => $discounts,
                'tax_paisa' => $taxTotal,
                'tax_total' => Money::ofMinor($taxTotal)->toTaka(),
                'charges_paisa' => $chargesTotal,
                'supplier_charges_paisa' => $supplierCharges,
                'rounding_paisa' => $roundingPaisa,
                'grand_total_paisa' => $goods - $discounts + $taxTotal + $supplierCharges + $roundingPaisa,
                'vat_in_cost' => $vatInCost,
                'discount_type' => $orderDiscount > 0 ? ($discount['type'] ?? null) : null,
                'discount_value' => $orderDiscount > 0 ? (string) ($discount['value'] ?? '') : null,
            ],
        ];
    }

    /**
     * One line, before the order discount.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(array $line, ?Business $business, Carbon $on): array
    {
        /** @var Product $product */
        $product = $line['product'];
        $purchaseUnit = $product->purchaseUnit ?? $product->unitOfMeasure;
        /** @var UnitOfMeasure|null $unit */
        $unit = $line['unit'] ?? null;
        $entered = (string) $line['quantity'];

        $typed = PurchaseLineEntry::resolve(
            $entered,
            isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (string) $line['unit_cost'] : null,
            isset($line['line_total']) && $line['line_total'] !== '' ? (string) $line['line_total'] : null,
            $line['entry_mode'] ?? null,
        );

        $quantity = PurchaseLineEntry::roundTo($entered, 4);
        $converted = false;

        if ($unit !== null && $purchaseUnit !== null && $unit->id !== $purchaseUnit->id) {
            try {
                [$factor] = $this->units->factor($product, $unit, $purchaseUnit, $on);
            } catch (UnitNotConvertibleException) {
                throw new FigureRefused('unit_id', "{$product->name} cannot be ordered in {$unit->abbreviation}: there is no conversion to {$purchaseUnit->abbreviation}. Add one on the product, or order in {$purchaseUnit->abbreviation}.");
            }

            $quantity = UnitConverter::apply($entered, $factor);

            if (PurchaseLineEntry::scaled($quantity) <= 0) {
                throw new FigureRefused('quantity', "{$entered} {$unit->abbreviation} is less than the smallest {$purchaseUnit->abbreviation} the order can hold.");
            }

            $converted = true;
        }

        $gross = $typed['line_total']->minor;
        $own = $this->discountOf($line['discount_type'] ?? null, isset($line['discount_value']) ? (string) $line['discount_value'] : null, $gross);

        if ($own === null) {
            throw new FigureRefused('discount_value', 'A discount percentage has to be more than 0 and less than 100.');
        }

        if ($own > $gross) {
            throw new FigureRefused('discount_value', 'The discount is more than the line is worth.');
        }

        // ⚠ A caller that never mentions VAT (Buy and receive's older lines, a
        // seeder) gets none, exactly as before this page; the page always
        // sends the box, and a blank box means the product's tax class rate.
        $rate = match (true) {
            ! array_key_exists('tax_rate', $line) => '0',
            trim((string) $line['tax_rate']) !== '' => (string) $line['tax_rate'],
            default => $business === null ? '0' : $this->tax->rateFor($business, $product->tax_class_id, $on),
        };

        if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate >= 100) {
            throw new FigureRefused('tax_rate', 'The VAT rate is a percentage from 0 to under 100.');
        }

        return [
            'product' => $product,
            'description' => trim((string) ($line['description'] ?? '')) ?: null,
            'unit_id' => $unit?->id ?? $purchaseUnit?->id,
            'entered_quantity' => PurchaseLineEntry::roundTo($entered, 4),
            'entered_rate' => $typed['unit_cost'],
            'typed' => $typed,
            'quantity_ordered' => $quantity,
            'converted' => $converted,
            'gross_paisa' => $gross,
            'discount_type' => $own > 0 ? ($line['discount_type'] ?? null) : null,
            'discount_value' => $own > 0 ? (string) $line['discount_value'] : null,
            'discount_paisa' => $own,
            'order_discount_paisa' => 0,
            'tax_rate' => $rate,
        ];
    }

    /**
     * The stored value columns. ⚠ A line whose value is exactly what was typed,
     * in the purchase unit, keeps its typed entry (rate or total) so it reads
     * as every line before this page did; anything else is stored as a total
     * with its six-place rate derived.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function stored(array $line, int $value): array
    {
        $typed = $line['typed'];
        unset($line['typed']);

        if (! $line['converted'] && $value === $line['gross_paisa']) {
            return $line + [
                'unit_cost' => $typed['unit_cost'],
                'line_total_paisa' => $typed['line_total']->minor,
                'entry_mode' => $typed['entry_mode'],
            ];
        }

        return $line + [
            'unit_cost' => PurchaseLineEntry::unitCostFromTotal(Money::ofMinor($value), $line['quantity_ordered']),
            'line_total_paisa' => $value,
            'entry_mode' => PurchaseEntryMode::Total,
        ];
    }

    /**
     * A discount in paisa: percent of `$of` (half up) or an amount. Zero when
     * nothing was typed; null when the percentage is outside (0, 100).
     */
    private function discountOf(?string $type, ?string $value, int $of): ?int
    {
        $value = trim((string) $value);

        if ($type === null || $type === '' || $value === '' || (float) $value == 0.0) {
            return 0;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        if ($type === 'percent') {
            if ((float) $value >= 100) {
                return null;
            }

            $percent4 = (int) round(((float) $value) * 10000);

            return intdiv($of * $percent4 * 2 + 1_000_000, 2_000_000);
        }

        return Money::ofTaka($value)->minor;
    }

    /**
     * `$amount` shared in proportion to `$weights`, exactly: each share rounded
     * down, the remainder onto the largest weight.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function spread(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $shares = array_map(fn () => 0, $weights);

        if ($amount === 0 || $total <= 0) {
            return $shares;
        }

        foreach ($weights as $index => $weight) {
            $shares[$index] = intdiv($amount * $weight, $total);
        }

        $largest = array_keys($weights, max($weights), true)[0];
        $shares[$largest] += $amount - array_sum($shares);

        return $shares;
    }
}
