<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Inventory\Actions\ApplyOpeningStockImport;
use App\Modules\Inventory\Actions\PlanOpeningStockImport;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpeningStockFile;
use App\Modules\Inventory\Support\OpeningStockImportPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The 31 August count, typed back from a file instead of a screen.
 *
 * ⚠⚠ THE ONE IRREVERSIBLE DOCUMENT OF THE WHOLE CUTOVER. Every other importer
 * on this project can be re-run tomorrow: a price rule is superseded, a product
 * is updated, a supplier is corrected. An opening stock document is written
 * ONCE PER WAREHOUSE for the life of the business, its unit costs become the
 * FIFO layers behind every margin reported until that stock is sold through,
 * and the only way to change it afterwards is an inventory adjustment against
 * the real company's books. So this screen previews louder than the other two,
 * and it is the only one that asks the operator to type something back.
 *
 * C-IMP-8 (amends C-IMP-4). Gated on `inventory.stock.adjust`, the SAME
 * permission as recording opening stock by hand, and NOT on a new
 * `inventory.opening-stock.import`. The reasoning that earned products and
 * prices their own import permission does not hold here: importing a catalogue
 * replaces three hundred rows where the edit screen changes one, but the
 * opening stock screen ALREADY posts an entire warehouse's document in one
 * submit. A file is the same power through a different door, and inventing a
 * second permission would mean a deploy-time seeder - one more step to miss in
 * the week of the cutover - to fence a power the person already has.
 *
 * ⚠⚠ THE COUNT DATE IS PART OF WHAT IS REVIEWED, exactly like the price
 * importer's effective date, and it has NO DEFAULT. "Today" would silently date
 * the opening position on the day somebody happened to run the import; the
 * position is defined by the day the shelves were walked - 31 August - not by
 * when the file reached the ERP.
 */
class OpeningStockImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('inventory/opening-stock/import', $this->page(null));
    }

    /**
     * C-IMP-1. Upload, name the day it was counted, and see the whole document
     * before any of it exists.
     */
    public function preview(Request $request, PlanOpeningStockImport $planner): Response
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
            'as_at' => ['required', 'date'],
        ], [], ['file' => 'CSV file', 'as_at' => 'count date']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '
                    .number_format(StagedImportFile::MAX_ROWS).'.',
            ]);
        }

        $asAt = Carbon::parse($data['as_at'])->startOfDay();

        try {
            $rows = OpeningStockFile::read($staged->path);
        } catch (Throwable $e) {
            $staged->discard($businessId);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($rows, $asAt);

        return Inertia::render('inventory/opening-stock/import', $this->page([
            'token' => $staged->token,
            'hash' => $staged->hash,
            'file_name' => $staged->originalName,
            'as_at' => $asAt->toDateString(),
            'rows' => $staged->rows,
        ] + $this->describe($plan)));
    }

    /**
     * C-IMP-2 and C-IMP-3.
     */
    public function store(Request $request, PlanOpeningStockImport $planner, ApplyOpeningStockImport $applier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string'],
            'as_at' => ['required', 'date'],
            'confirm_total' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['confirm_total' => 'total value']);

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedImportFile::reopen($data['token'], $data['hash'], $businessId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $asAt = Carbon::parse($data['as_at'])->startOfDay();

        // ⚠⚠ Re-read from the bytes on disk and re-planned against the live
        // database, never trusted from the form. The preview was a picture of a
        // catalogue that may have moved since; this is the decision, and it is
        // taken again from the source.
        $plan = $planner->handle(OpeningStockFile::read($staged->path), $asAt);

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so no opening stock was written.',
            ]);
        }

        if (! $plan->wouldChangeAnything()) {
            throw ValidationException::withMessages([
                'file' => 'Every row in that file was left blank, so there is nothing to write.',
            ]);
        }

        // ⚠⚠ THE ONE THING THE SYSTEM CANNOT CHECK FOR ITSELF. This total is
        // debited to Stock on Hand and credited to Opening Balance Equity, and
        // it has to equal the stock figure on the accountant's opening trial
        // balance to the paisa - otherwise Opening Balance Equity never reaches
        // zero, and that account reaching zero is the only check that catches
        // an opening position which balances while being wrong. No validation
        // rule can compare it with a document the ERP has never seen. Typing it
        // back is the act of reading it, and it is asked for on this screen
        // alone because this is the document that cannot be re-imported.
        $expected = $plan->totalValue();

        if ($this->normalise($data['confirm_total']) !== $expected->toTaka()) {
            throw ValidationException::withMessages([
                'confirm_total' => 'That is not the total this file comes to. It comes to '.$expected->format()
                    .'. Check the figure against the accountant\'s opening trial balance, then type it exactly.',
            ]);
        }

        try {
            // ⚠ `nullable` leaves the key ABSENT when the field is not sent at
            // all, which is not the same as sent-and-empty. Reading it directly
            // threw on the one document that cannot be re-imported.
            $result = $applier->handle($plan, $asAt, $request->user(), ($data['note'] ?? '') ?: null);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.opening-stock.index')
            ->with('success', $result['documents'].' opening stock document(s) written as at '.$asAt->toDateString()
                .', '.$result['lines'].' line(s), '.$expected->format().' debited to Stock on Hand.');
    }

    /**
     * Everything the plan would do, in the shape the screen reads.
     *
     * @return array<string,mixed>
     */
    private function describe(OpeningStockImportPlan $plan): array
    {
        $warehouses = [];

        foreach ($plan->warehouses as $entry) {
            $warehouses[] = [
                'code' => $entry['warehouseCode'],
                'name' => $entry['warehouseName'],
                'lines' => count($entry['lines']),
                'value' => $plan->valueFor($entry['warehouseId'])->format(),
                // The whole document, not a sample. Somebody about to write a
                // once-per-warehouse record is entitled to read every line of it.
                'rows' => array_map(fn (array $line) => [
                    'line' => $line['line'],
                    'sku' => $line['sku'],
                    'product' => $line['productName'],
                    'quantity' => rtrim(rtrim($line['quantity'], '0'), '.'),
                    'unitCost' => $line['unitCost'],
                    'value' => $line['value']->format(),
                    'batchNumber' => $line['batchNumber'],
                    'expiryDate' => $line['expiryDate'],
                ], $entry['lines']),
            ];
        }

        return [
            'errors' => $plan->errors,
            'warnings' => $plan->warnings,
            'not_counted' => $plan->notCounted,
            'warehouses' => $warehouses,
            'line_count' => $plan->lineCount(),
            'counted_products' => $plan->countedProducts(),
            'active_stocked_products' => $plan->activeStockedProducts,
            // Both forms: the human one to read, the machine one to type back.
            'total_value' => $plan->totalValue()->format(),
            'total_value_exact' => $plan->totalValue()->toTaka(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $preview
     * @return array<string,mixed>
     */
    private function page(?array $preview): array
    {
        $recorded = OpeningStock::query()->pluck('warehouse_id')->all();

        return [
            'preview' => $preview,
            // ⚠ C-OPN-1 surfaced BEFORE the upload, not after it. Opening stock
            // is written once per warehouse, ever, and discovering that at the
            // apply step means a day of counting spent on a document that can
            // never be written.
            'awaiting' => Warehouse::query()->where('is_active', true)->whereNotIn('id', $recorded)
                ->orderBy('name')->get(['name', 'code'])->all(),
            'alreadyRecorded' => Warehouse::query()->whereIn('id', $recorded)
                ->orderBy('name')->get(['name', 'code'])->all(),
            'stockedProducts' => Product::query()->where('is_active', true)->stocked()->count(),
            'columns' => [
                'mandatory' => OpeningStockFile::MANDATORY,
                'optional' => OpeningStockFile::OPTIONAL,
            ],
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
        ];
    }

    /**
     * "1,234.56", "1234.5" and " 1234.50 " are the same figure typed by three
     * people. A thousands separator or a trailing zero is not a disagreement
     * about the amount, and refusing over one would train the operator to stop
     * reading the number and start copying the string.
     *
     * ⚠ Through `Money`, not through a regex of its own: the ledger's parser
     * already refuses anything that is not an amount, and it is the same code
     * that produced the figure being compared against. Two parsers for one
     * comparison is how a rounding disagreement gets invented.
     */
    private function normalise(string $typed): string
    {
        try {
            return Money::ofTaka(str_replace('৳', '', $typed))->toTaka();
        } catch (Throwable) {
            return '';
        }
    }
}
