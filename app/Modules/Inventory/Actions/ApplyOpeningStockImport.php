<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpeningStockImportPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes an opening stock plan that has already been reviewed.
 *
 * ⚠⚠ ONE TRANSACTION ACROSS EVERY WAREHOUSE. `RecordOpeningStock` is already
 * transactional per document, which would leave three warehouses written and
 * the fourth refused - and an opening stock document cannot be written a second
 * time, so that state is unrecoverable without adjusting the real company's
 * books. The outer transaction is what makes the import all-or-nothing.
 *
 * ⚠⚠ THE ONE-PER-WAREHOUSE RULE IS RE-CHECKED HERE, inside the transaction,
 * against the live database. The plan was computed earlier and the world may
 * have moved; a stale plan must fail loudly rather than collide with a
 * constraint halfway through.
 */
class ApplyOpeningStockImport
{
    public function __construct(private readonly RecordOpeningStock $record) {}

    /**
     * @return array{documents:int, lines:int}
     */
    public function handle(OpeningStockImportPlan $plan, Carbon $asAt, User $by, ?string $note = null): array
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('An opening stock plan carrying errors is never applied.');
        }

        if (! $plan->wouldChangeAnything()) {
            throw new RuntimeException('The plan has nothing to write.');
        }

        return DB::transaction(function () use ($plan, $asAt, $by, $note) {
            $documents = 0;
            $lines = 0;

            foreach ($plan->warehouses as $entry) {
                // ⚠ Re-read, not trusted from the plan.
                if (OpeningStock::query()->where('warehouse_id', $entry['warehouseId'])->exists()) {
                    throw new RuntimeException(
                        "Opening stock for {$entry['warehouseName']} was recorded after this plan was built. "
                        .'Nothing has been written. Re-run the review and check what changed.'
                    );
                }

                $warehouse = Warehouse::query()->findOrFail($entry['warehouseId']);
                $products = Product::query()
                    ->whereIn('id', array_column($entry['lines'], 'productId'))
                    ->get()
                    ->keyBy('id');

                $documentLines = [];

                foreach ($entry['lines'] as $line) {
                    $documentLines[] = [
                        'product' => $products[$line['productId']],
                        'quantity' => $line['quantity'],
                        'unitCost' => $line['unitCost'],
                        'batchNumber' => $line['batchNumber'],
                        'expiryDate' => $line['expiryDate'],
                    ];
                }

                // ⚠ Through the module's own entry point. It creates the batches,
                // writes the movements and posts Dr Stock on Hand / Cr Opening
                // Balance Equity as ONE opening-balance journal - none of which
                // this importer reimplements.
                $this->record->handle(
                    warehouse: $warehouse,
                    lines: $documentLines,
                    createdBy: $by,
                    asAt: $asAt,
                    note: $note ?? 'Imported opening stock count',
                );

                $documents++;
                $lines += count($documentLines);
            }

            return ['documents' => $documents, 'lines' => $lines];
        });
    }
}
