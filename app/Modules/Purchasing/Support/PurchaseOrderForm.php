<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\UnitConverter;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ What the full purchase order page needs (purchase redesign PR1, 18 Oct
 * 2026), one place for the new page and its edit: every default the page
 * fills by itself (Joy: "auto-generate what can be").
 *
 *   suppliers   with their payment terms, lead time and the warehouse their
 *               last order went to;
 *   products    with the purchase unit, the units a line may be typed in (and
 *               the factor to the purchase unit, for the preview), the last
 *               received price (the rate starts there), the tax class rate,
 *               the weight (for a weight split) and whether batch and expiry
 *               are required (for "Goods received now");
 *   the next PO number, today, the buyer (the person typing), money
 *   accounts with their balance, expense heads, charge types, and what this
 *   person may do (each switch shows only when its steps are allowed).
 */
final class PurchaseOrderForm
{
    /**
     * @return array<string, mixed>
     */
    public static function props(User $user, ?PurchaseOrder $order = null): array
    {
        $businessId = (int) app(BusinessContext::class)->id();
        $business = Business::withoutGlobalScopes()->find($businessId);
        $today = Carbon::today();
        $warehouses = Warehouse::query()->operational()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_default']);

        return [
            'order' => $order === null ? null : self::orderForEdit($order),
            'nextNumber' => $order?->po_number ?? DocumentNumber::next(PurchaseOrder::query(), 'po_number', 'PO-', 6),
            'today' => $today->toDateString(),
            'suppliers' => self::suppliers(),
            'warehouses' => $warehouses->map->only(['id', 'name'])->values(),
            'defaultWarehouseId' => $warehouses->firstWhere('is_default', true)?->id ?? $warehouses->first()?->id,
            'products' => self::products($business, $today),
            'units' => UnitOfMeasure::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
            // For a product created from inside the order (Alt+C on the item).
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'buyers' => User::query()->whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
                ->orderBy('name')->get(['id', 'name']),
            'currentUserId' => $user->id,
            // ⭐ Initial plan B1 (R40): HR employees for "Purchasing employee".
            'employees' => PurchasingEmployees::options(),
            'moneyAccounts' => tap(MoneyAccount::query()->handled()->withLedger()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'business_id', 'name', 'type', 'account_id']), function ($accounts) {
                    $balances = MoneyAccountBalances::for($accounts);
                    $accounts->each(fn (MoneyAccount $account) => $account->setAttribute('balance', $balances[$account->id] ?? null)
                        ->makeHidden(['business_id', 'account_id']));
                }),
            'expenseAccounts' => Account::query()
                ->where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->whereNotIn('id', VoucherAccounts::refusedIds($today))
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
                ->all(),
            'costTypes' => collect(PurchaseOrderCharges::ORDER_TYPES)->map(fn (string $value) => LandedCostType::from($value))
                ->map(fn (LandedCostType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'into_cost' => $type->defaultTreatment() === LandedCostTreatment::Capitalize,
                    'needs_head' => $type->expenseMappingKey() === null,
                ])->values(),
            'vat' => ['applies' => app(TaxCalculator::class)->appliesOn($business, $today)],
            'rights' => [
                'approve_order' => $user->can('purchasing.po.approve'),
                'receive' => $user->can('purchasing.receipt.record'),
                // "Bill now" is the one-screen purchase: Buy and receive's own gate.
                'bill' => $user->can('purchasing.quick.purchase') && $user->can('purchasing.bill.record'),
                'pay' => $user->can('purchasing.payment.record'),
                'advance' => $user->can('purchasing.advance.record'),
                'landed_costs' => $user->can('purchasing.landed_cost.manage'),
                'create_product' => $user->can('inventory.catalog.manage'),
                'create_supplier' => $user->can('purchasing.suppliers.manage'),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function suppliers(): Collection
    {
        // The warehouse each supplier's last order went to.
        $lastWarehouse = PurchaseOrder::query()->orderByDesc('id')->limit(2000)
            ->get(['supplier_id', 'warehouse_id'])
            ->unique('supplier_id')
            ->pluck('warehouse_id', 'supplier_id');

        return Supplier::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'code', 'phone', 'payment_terms_type', 'payment_terms_days', 'lead_time_days'])
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'code' => $supplier->code,
                'phone' => $supplier->phone,
                'terms' => PaymentTerms::forSupplier($supplier)?->toArray(),
                'lead_time_days' => $supplier->lead_time_days,
                'last_warehouse_id' => $lastWarehouse[$supplier->id] ?? null,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function products(?Business $business, Carbon $today): Collection
    {
        $lastCosts = LastReceivedCost::forProducts();
        $allUnits = UnitOfMeasure::query()->get();
        $tax = app(TaxCalculator::class);

        // Every unit conversion in force, newest first, read once.
        $rows = ProductUnitConversion::query()
            ->whereIn('purpose', [ProductUnitConversion::PURPOSE_RECIPE, ProductUnitConversion::PURPOSE_PURCHASE])
            ->whereDate('effective_from', '<=', $today->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get(['product_id', 'from_unit_id', 'to_unit_id', 'stock_units_per_purchase_unit'])
            ->groupBy('product_id');

        return Product::where('is_active', true)
            ->with(['unitOfMeasure:id,abbreviation,dimension,factor_to_base', 'purchaseUnit:id,abbreviation,dimension,factor_to_base'])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'type', 'unit_of_measure_id', 'purchase_unit_id', 'tax_class_id', 'weight_grams', 'tracks_expiry', 'default_supplier_id'])
            ->map(function (Product $product) use ($lastCosts, $allUnits, $rows, $tax, $business, $today) {
                $unit = $product->purchaseUnit ?? $product->unitOfMeasure;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'stocked' => $product->type->isStocked(),
                    'unit_id' => $unit?->id,
                    'unit' => $unit?->abbreviation,
                    'units' => $unit === null ? [] : self::unitsFor($unit, $allUnits, $rows[$product->id] ?? collect()),
                    'last_cost' => $lastCosts[$product->id] ?? null,
                    'tax_rate' => $business === null ? '0' : rtrim(rtrim($tax->rateFor($business, $product->tax_class_id, $today), '0'), '.'),
                    'weight_grams' => $product->weight_grams,
                    'tracks_expiry' => (bool) $product->tracks_expiry,
                    // ⭐ Initial plan B4 (24 Oct 2026; R22): proposed as the
                    // supplier when a line picks this item and none is chosen.
                    'default_supplier_id' => $product->default_supplier_id,
                ];
            });
    }

    /**
     * The units a line may be typed in, each with how many PURCHASE units one
     * of it is: the purchase unit itself, the same mass or volume dimension,
     * and the product's own rows into the purchase unit.
     *
     * @param  Collection<int, UnitOfMeasure>  $allUnits
     * @param  Collection<int, ProductUnitConversion>  $rows
     * @return list<array{id: int, abbreviation: string, factor: string}>
     */
    private static function unitsFor(UnitOfMeasure $purchaseUnit, Collection $allUnits, Collection $rows): array
    {
        $options = [$purchaseUnit->id => ['id' => $purchaseUnit->id, 'abbreviation' => $purchaseUnit->abbreviation, 'factor' => '1']];

        foreach ($allUnits as $unit) {
            if (! isset($options[$unit->id]) && ($factor = UnitConverter::globalFactor($unit, $purchaseUnit)) !== null) {
                $options[$unit->id] = ['id' => $unit->id, 'abbreviation' => $unit->abbreviation, 'factor' => $factor];
            }
        }

        foreach ($rows->where('to_unit_id', $purchaseUnit->id) as $row) {
            $unit = $allUnits->firstWhere('id', $row->from_unit_id);

            if ($unit !== null && ! isset($options[$unit->id])) {
                $options[$unit->id] = ['id' => $unit->id, 'abbreviation' => $unit->abbreviation, 'factor' => (string) $row->stock_units_per_purchase_unit];
            }
        }

        return array_values($options);
    }

    /**
     * ⭐ Corrections wave 1 (19 Oct 2026): "Copy" fills a NEW order from
     * another one: the same supplier, lines, charges and terms, today's date,
     * no number and no planned payments (those were promises of the old one).
     *
     * @return array<string, mixed>
     */
    public static function copyOf(PurchaseOrder $order): array
    {
        return array_merge(self::orderForEdit($order), [
            'id' => null,
            'po_number' => null,
            'copied_from' => $order->po_number,
            'order_date' => Carbon::today()->toDateString(),
            'expected_date' => null,
            'schedule' => [],
        ]);
    }

    /**
     * An open order as the page edits it: what was typed, not what was stored.
     *
     * @return array<string, mixed>
     */
    private static function orderForEdit(PurchaseOrder $order): array
    {
        $order->loadMissing(['lines', 'charges', 'paymentSchedule']);
        $lineIndex = $order->lines->values()->mapWithKeys(fn (PurchaseOrderLine $line, int $index) => [$line->id => $index]);
        $number = fn ($value) => $value === null ? '' : rtrim(rtrim((string) $value, '0'), '.');

        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $order->warehouse_id,
            'order_date' => $order->orderDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'note' => $order->note,
            'supplier_reference' => $order->supplier_reference,
            'buyer_id' => $order->buyer_id,
            'purchasing_employee_id' => $order->purchasing_employee_id,
            'terms_text' => $order->terms_text,
            'payment_terms_type' => $order->payment_terms_type,
            'payment_terms_days' => $order->payment_terms_days,
            'discount_type' => $order->discount_type,
            'discount_value' => $number($order->discount_value),
            'rounding' => $order->rounding_paisa === 0 ? '' : Money::ofMinor((int) $order->rounding_paisa)->toTaka(),
            'lines' => $order->lines->values()->map(fn (PurchaseOrderLine $line) => [
                'product_id' => $line->product_id,
                'description' => $line->description,
                'unit_id' => $line->unit_id,
                'quantity' => $number($line->entered_quantity ?? $line->quantity_ordered),
                'unit_cost' => $number($line->entered_rate ?? $line->unit_cost),
                'line_total' => Money::ofMinor((int) ($line->gross_paisa ?: $line->line_total_paisa))->toTaka(),
                // Typed in another unit or with VAT in the value: the rate is what was typed.
                'entry_mode' => $line->entered_rate !== null ? 'rate' : ($line->entry_mode?->value ?? 'rate'),
                'discount_type' => $line->discount_type,
                'discount_value' => $number($line->discount_value),
                'tax_rate' => $number($line->tax_rate),
            ])->all(),
            'charges' => $order->charges->map(fn ($charge) => [
                'cost_type' => $charge->cost_type->value,
                'description' => $charge->description,
                'amount' => $charge->money()->toTaka(),
                'allocation_method' => $charge->allocation_method->value,
                'line_index' => $charge->purchase_order_line_id === null ? null : ($lineIndex[$charge->purchase_order_line_id] ?? null),
                'payee' => $charge->money_account_id !== null ? 'money' : ((int) $charge->supplier_id === (int) $order->supplier_id ? 'this' : 'supplier'),
                'supplier_id' => $charge->supplier_id,
                'money_account_id' => $charge->money_account_id,
                'payee_name' => $charge->payee_name,
                'treatment' => $charge->treatment?->value,
                'override_reason' => $charge->override_reason,
                'expense_account_id' => $charge->expense_account_id,
            ])->all(),
            'schedule' => $order->paymentSchedule->map(fn ($row) => [
                'due_on' => $row->due_on?->toDateString(),
                'amount' => $row->money()->toTaka(),
                'note' => $row->note,
            ])->all(),
        ];
    }
}
