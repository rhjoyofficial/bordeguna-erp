<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\PendingAdjustments;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens a count: snapshots what the book expects per product in scope and
 * writes NOTHING else - no adjustment, no movement, no journal (acceptance 1).
 */
class StartStockCount
{
    public function handle(
        Warehouse $warehouse,
        User $createdBy,
        ?Category $category = null,
        ?Brand $brand = null,
        ?string $note = null,
    ): StockCount {
        return DB::transaction(function () use ($warehouse, $createdBy, $category, $brand, $note) {
            // The book per product: every active batch, because the shelf
            // holds expired stock too and the counter will meet it.
            $expected = StockBatch::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('status', StockBatchStatus::Active)
                ->where('current_quantity', '>', 0)
                ->selectRaw('product_id, SUM(current_quantity) as quantity')
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id');

            $inScope = Product::query()
                ->whereIn('id', $expected->keys())
                ->stocked()
                ->when($category, fn ($query) => $query->where('category_id', $category->id))
                ->when($brand, fn ($query) => $query->where('brand_id', $brand->id))
                ->pluck('id');

            // ⚠ Bug hunt 16 Oct 2026: two counts started at once computed the
            // same number and the second died on the unique index (a 500).
            // Claimed like every other document number: a collision retries.
            $count = DocumentNumber::claim('count_number', fn () => $this->nextNumber($warehouse->business_id, now()), fn (string $number) => StockCount::create([
                'business_id' => $warehouse->business_id,
                'warehouse_id' => $warehouse->id,
                'count_number' => $number,
                'category_id' => $category?->id,
                'brand_id' => $brand?->id,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]));

            // INV-002 (14 Oct 2026): what the book will say once the corrections
            // already waiting for approval are approved, so a count started
            // before that approval does not expect the old figure.
            $pending = PendingAdjustments::netByProduct($warehouse->id, $inScope->all());

            foreach ($inScope as $productId) {
                $count->lines()->create([
                    'product_id' => $productId,
                    'expected_quantity' => number_format(max(0.0, (float) $expected[$productId] + ($pending[$productId] ?? 0.0)), 4, '.', ''),
                ]);
            }

            return $count->load('lines');
        });
    }

    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'CNT-'.FiscalYear::containing($date, $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(StockCount::query(), 'count_number', $prefix);
    }
}
