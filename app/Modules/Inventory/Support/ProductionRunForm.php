<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Enums\ProductionCostKind;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): the production run form, in ONE
 * place for the three doors it now has: a new run (store), a draft's Edit
 * (update) and a confirmed run's Correct (the corrections engine). The same
 * field rules, the same lookups, the same arguments to RecordProductionRun,
 * so the three cannot drift (the IncomeVoucherRules lesson).
 */
final class ProductionRunForm
{
    /** @return array<string, mixed> */
    public static function rules(int $businessId): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'output_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'bill_of_material_id' => ['nullable', 'integer', Rule::exists('bills_of_material', 'id')->where('business_id', $businessId)],
            'output_expiry_date' => ['nullable', 'date'],
            'output_mrp' => ['nullable', 'numeric', 'min:0'],
            'expense_waste' => ['boolean'],
            'occurred_at' => ['required', 'date', 'before_or_equal:'.Carbon::today()->endOfDay()->toDateTimeString()],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.waste' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            'costs' => ['nullable', 'array'],
            'costs.*.kind' => ['required', Rule::enum(ProductionCostKind::class)],
            'costs.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'costs.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'costs.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The validated form as RecordProductionRun's named arguments (without the
     * person, which each door supplies).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function arguments(array $data): array
    {
        $products = Product::query()->with('unitOfMeasure')
            ->whereIn('id', collect($data['lines'])->pluck('product_id')->push($data['output_product_id']))
            ->get()->keyBy('id');
        $unitIds = collect($data['lines'])->pluck('unit_id')->filter()->unique();
        $units = $unitIds->isEmpty() ? collect() : UnitOfMeasure::query()->whereIn('id', $unitIds)->get()->keyBy('id');
        $accounts = Account::query()->whereIn('id', collect($data['costs'] ?? [])->pluck('account_id'))->get()->keyBy('id');

        return [
            'warehouse' => Warehouse::query()->findOrFail($data['warehouse_id']),
            'outputProduct' => $products[$data['output_product_id']],
            'outputQuantity' => (string) $data['output_quantity'],
            'lines' => collect($data['lines'])->map(fn (array $line) => [
                'product' => $products[$line['product_id']],
                'quantity' => (string) $line['quantity'],
                'waste' => (string) ($line['waste'] ?? '0'),
                'unit' => empty($line['unit_id']) ? null : ($units[(int) $line['unit_id']] ?? null),
            ])->all(),
            'occurredAt' => $data['occurred_at'],
            'outputExpiryDate' => $data['output_expiry_date'] ?? null,
            'outputMrp' => isset($data['output_mrp']) ? (string) $data['output_mrp'] : null,
            'expenseWaste' => (bool) ($data['expense_waste'] ?? false),
            'note' => $data['note'] ?? null,
            'billOfMaterial' => isset($data['bill_of_material_id']) ? BillOfMaterial::with('lines')->find($data['bill_of_material_id']) : null,
            'costs' => collect($data['costs'] ?? [])->map(fn (array $cost) => [
                'kind' => (string) $cost['kind'],
                'account' => $accounts[(int) $cost['account_id']] ?? null,
                'amount' => (string) $cost['amount'],
                'note' => $cost['note'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * A run as the form shows it, for Edit and Correct. The quantities are the
     * ones typed (entered unit), so the screen re-opens what the person saw.
     *
     * @return array<string, mixed>
     */
    public static function of(ProductionRun $run): array
    {
        $run->loadMissing(['lines', 'costs']);
        $trim = fn ($v) => $v === null ? '' : (str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v);

        return [
            'warehouse_id' => (string) $run->warehouse_id,
            'output_product_id' => (string) $run->output_product_id,
            'output_quantity' => $trim($run->output_quantity),
            'bill_of_material_id' => $run->bill_of_material_id === null ? '' : (string) $run->bill_of_material_id,
            'output_expiry_date' => $run->output_expiry_date?->toDateString() ?? '',
            'output_mrp' => $trim($run->output_mrp),
            'occurred_at' => Carbon::parse($run->occurred_at)->toDateString(),
            'expense_waste' => (bool) $run->expense_waste,
            'note' => $run->note ?? '',
            'lines' => $run->lines->map(fn ($line) => [
                'product_id' => (string) $line->product_id,
                'quantity' => $trim($line->entered_quantity ?? $line->quantity_consumed),
                'waste' => $trim(self::enteredWaste($line)),
                'unit_id' => $line->entered_unit_id === null ? '' : (string) $line->entered_unit_id,
            ])->values()->all(),
            'costs' => $run->costs->map(fn ($cost) => [
                'kind' => (string) ($cost->kind instanceof \BackedEnum ? $cost->kind->value : $cost->kind),
                'account_id' => (string) $cost->account_id,
                'amount' => number_format($cost->amount_paisa / 100, 2, '.', ''),
                'note' => $cost->note ?? '',
            ])->values()->all(),
        ];
    }

    /** Waste is stored in the stock unit; shown back in the unit it was typed in. */
    private static function enteredWaste($line): string
    {
        $waste = (string) $line->waste_quantity;
        $factor = $line->conversion_factor === null ? null : (string) $line->conversion_factor;

        if ($factor === null || bccomp($factor, '0', 8) <= 0 || bccomp($factor, '1', 8) === 0) {
            return $waste;
        }

        return bcdiv($waste, $factor, 4);
    }
}
