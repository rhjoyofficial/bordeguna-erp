<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the stock was worth on a stated day.
 *
 * ⚠⚠ DERIVED FROM MOVEMENTS, NEVER FROM `stock_batches`. The obvious
 * implementation is one line - `SUM(current_quantity * unit_cost)` - and it is
 * wrong three separate ways, each of which this project has already paid for:
 *
 *   1. It cannot answer an AS-AT question. `current_quantity` is what the batch
 *      holds NOW, so a valuation as at 31 August would include a delivery
 *      received on 3 September. Character for character the defect the ageing
 *      work found in `SalesInvoice::outstanding()`.
 *   2. It trusts a CACHE. `StockLedger` is explicit that `current_quantity` is
 *      derived from movements and never authoritative alone, and Joy's rule is
 *      that a disagreement between the two is a defect, not two valid answers.
 *   3. `unit_cost` is MUTATED IN PLACE by a revaluation, so today's cost belongs
 *      to no past date at all.
 *
 * ⚠⚠ AND SUMMING THE MOVEMENTS IS THE REPLAY (C-IVL-2). A layer received at 100
 * and revalued to 120 on 15 August is worth 100 on the 10th, because the
 * revaluation movement is dated the 15th and simply falls outside the filter.
 * No separate "rebuild the historical cost" pass is needed, and that is exactly
 * why this ties to the ledger for the same date: the GL had not received the
 * revaluation posting on the 10th either.
 *
 * ⚠ Every movement is valued through `StockValue`, the same definition the
 * posting engine and the reconciliation use. Three implementations of "what is
 * this movement worth" is how the revaluation seam opened in the first place.
 */
final class InventoryValuation
{
    /**
     * @param  Collection<int, ValuationRow>  $rows
     */
    private function __construct(
        public readonly Carbon $asAt,
        public readonly Collection $rows,
        public readonly ?Money $controlBalance,
        public readonly ?string $controlAccountLabel,
    ) {}

    public static function asAt(Carbon $asAt): self
    {
        $on = $asAt->toDateString();

        // ⚠ `occurred_at`, not `created_at`. The first answers "what was on the
        // shelf that day", the second "when was the row typed" - and they stop
        // being the same the moment a Saturday delivery is entered on Monday.
        // ⚠ Date-string comparison, the fifth application of the same lesson.
        $movements = StockMovement::query()
            ->whereDate('occurred_at', '<=', $on)
            ->get(array_merge(StockValue::columns(), ['product_id', 'warehouse_id', 'batch_id']));

        $batches = StockBatch::query()->get()->keyBy('id');
        $products = Product::query()->with('brand')->get()->keyBy('id');
        $warehouses = Warehouse::query()->get()->keyBy('id');

        $rows = $movements
            ->groupBy(fn (StockMovement $movement) => implode('|', [
                $movement->product_id,
                $movement->warehouse_id,
                $movement->batch_id ?? 0,
            ]))
            ->map(function (Collection $group, string $key) use ($batches, $products, $warehouses) {
                [$productId, $warehouseId, $batchId] = array_map('intval', explode('|', $key));

                $product = $products[$productId] ?? null;
                $batch = $batchId === 0 ? null : ($batches[$batchId] ?? null);

                return new ValuationRow(
                    productId: $productId,
                    productSku: $product?->sku ?? "id {$productId}",
                    productName: $product?->name ?? "id {$productId}",
                    brand: $product?->brand?->name,
                    warehouseId: $warehouseId,
                    warehouseName: $warehouses[$warehouseId]->name ?? "id {$warehouseId}",
                    batchId: $batchId === 0 ? null : $batchId,
                    batchNumber: $batch?->batch_number,
                    expiryDate: $batch?->expiry_date,
                    // ⚠ Revaluations carry quantity zero, so they add nothing
                    // here for free - which is the whole reason that zero was
                    // chosen when the revaluation feature was designed.
                    quantity: self::quantityOf($group),
                    value: StockValue::sum($group),
                );
            })
            // C-IVL-3. A depleted layer at zero on BOTH sides is noise and goes.
            // A layer with quantity but no value, or value but no quantity, or a
            // NEGATIVE of either, stays and is shown loudly: those are the
            // signatures of a real problem, and flooring them at zero to make the
            // total look sane is the one thing a valuation must never do.
            ->reject(fn (ValuationRow $row) => $row->isEmpty())
            ->sortBy([
                fn (ValuationRow $a, ValuationRow $b) => strcmp($a->productSku, $b->productSku),
                fn (ValuationRow $a, ValuationRow $b) => strcmp($a->warehouseName, $b->warehouseName),
            ])
            ->values();

        $account = AccountMapping::forKeyOn('inventory.stock_on_hand', $asAt)?->account;

        return new self(
            asAt: $asAt,
            rows: $rows,
            controlBalance: $account === null ? null : self::controlBalance($account->id, $asAt),
            controlAccountLabel: $account?->label(),
        );
    }

    public function total(): Money
    {
        return Money::sum($this->rows->map(fn (ValuationRow $row) => $row->value));
    }

    /**
     * ⚠⚠ C-IVL-4. The tie-out is ON THE SCREEN, not left to a nightly command
     * nobody runs. A tie-out that is only performed in a cron job is a tie-out
     * that is not performed.
     */
    public function tiesOut(): bool
    {
        return $this->controlBalance === null
            || $this->controlBalance->minor === $this->total()->minor;
    }

    public function difference(): Money
    {
        return $this->controlBalance === null
            ? Money::zero()
            : Money::ofMinor($this->controlBalance->minor - $this->total()->minor);
    }

    /**
     * ⚠ Expected to be FALSE for LaLaDia, Bionic and Azmion, which hold stock
     * documents with no postings behind them at all. That is the known
     * operations-predate-accounting state `accounting:reconcile` warns about
     * nightly, and per Joy's A4 decision those three stay operational-only. The
     * screen must say WHY rather than showing a bare red number.
     */
    public function hasControlAccount(): bool
    {
        return $this->controlBalance !== null;
    }

    /**
     * Rows the screen must not let a reader scroll past.
     *
     * @return Collection<int, ValuationRow>
     */
    public function anomalies(): Collection
    {
        return $this->rows->filter(fn (ValuationRow $row) => $row->isAnomalous())->values();
    }

    /**
     * C-IVL-7. Brand as a REPORTING dimension, which is a group-by over data
     * that already exists - no schema change, no ledger change, and none of the
     * tenant-boundary risk that a brand-tagged ledger would carry.
     *
     * @return Collection<int, array{brand:string, quantity:string, value:Money}>
     */
    public function byBrand(): Collection
    {
        return $this->groupedBy(fn (ValuationRow $row) => $row->brand ?? 'Unbranded', 'brand');
    }

    /**
     * @return Collection<int, array{warehouse:string, quantity:string, value:Money}>
     */
    public function byWarehouse(): Collection
    {
        return $this->groupedBy(fn (ValuationRow $row) => $row->warehouseName, 'warehouse');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function groupedBy(callable $key, string $label): Collection
    {
        return $this->rows
            ->groupBy($key)
            ->map(fn (Collection $group, string $name) => [
                $label => $name,
                'quantity' => self::formatQuantity($group->sum(fn (ValuationRow $row) => (float) $row->quantity)),
                'value' => Money::sum($group->map(fn (ValuationRow $row) => $row->value)),
            ])
            ->sortByDesc(fn (array $entry) => $entry['value']->minor)
            ->values();
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    private static function quantityOf(Collection $movements): string
    {
        $total = 0.0;

        foreach ($movements as $movement) {
            $quantity = (float) $movement->quantity;
            $total += $movement->direction->value === 'in' ? $quantity : -$quantity;
        }

        return self::formatQuantity($total);
    }

    private static function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 4, '.', '');
    }

    private static function controlBalance(int $accountId, Carbon $asAt): Money
    {
        return Money::ofMinor((int) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereDate('journals.journal_date', '<=', $asAt->toDateString())
            ->sum('journal_lines.amount_minor'));
    }
}
