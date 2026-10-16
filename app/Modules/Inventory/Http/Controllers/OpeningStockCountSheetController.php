<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The blank sheet somebody carries around the warehouse on counting day.
 *
 * ⚠⚠ THIS WRITES NOTHING AND MUST NEVER LEARN TO. It exists so the count is
 * made on paper against SKUs the system already knows, and typed back through
 * `OpeningStockFile`, which matches on SKU and WAREHOUSE CODE and refuses
 * anything it cannot read unambiguously. A count sheet whose SKU column was
 * hand-typed defeats the entire point of that reader being strict.
 *
 * ⚠ NO QUANTITY AND NO COST IS EVER PRE-FILLED, not even zero. Opening stock is
 * written once per warehouse for the life of the business and its unit costs
 * become the FIFO layers behind every margin reported until that stock sells
 * through. A number the counter did not write is a number nobody owns.
 *
 * The CSV and the printed page are generated from ONE query, so the paper the
 * counter holds and the file that imports cannot disagree about which products
 * exist.
 *
 * ⚠ Browser print, not dompdf. Every product name carries Bangla and no Bangla
 * font is bundled for dompdf, so a PDF would render the names as empty boxes -
 * silently, and only on the names, which is the worst possible place to find
 * out. Chrome's own print dialog uses the system fonts and gets it right.
 */
class OpeningStockCountSheetController extends Controller
{
    public function show(Request $request): StreamedResponse|View
    {
        $data = $request->validate([
            // Scoped (bug hunt 16 Oct 2026), as everywhere else.
            'warehouse' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'as_at' => ['nullable', 'date'],
            'format' => ['nullable', 'in:print,csv'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse']);
        $asAt = Carbon::parse($data['as_at'] ?? now()->toDateString())->toDateString();

        $products = $this->countableProducts();

        // ⚠ C-OPN-1 surfaced BEFORE the count, not after it. Opening stock is
        // written once per warehouse, ever. Printing a sheet for a warehouse
        // that already has one spends a day counting a document that can never
        // be written, and the refusal would only appear at import.
        $alreadyRecorded = OpeningStock::query()->where('warehouse_id', $warehouse->id)->exists();

        if (($data['format'] ?? 'print') === 'csv') {
            return $this->csv($warehouse, $products, $asAt);
        }

        return view('inventory.opening-stock-count-sheet', [
            'warehouse' => $warehouse,
            'products' => $products,
            'asAt' => $asAt,
            'alreadyRecorded' => $alreadyRecorded,
            'business' => Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id())?->name,
        ]);
    }

    /**
     * Exactly what the opening stock document will accept: active, stocked,
     * this business. A service cannot hold stock and an inactive product is
     * refused by the importer, so neither belongs on paper either.
     *
     * Ordered by category then name because that is how somebody walks a
     * shelf. Sorting by SKU groups the brands and scatters the products.
     *
     * @return Collection<int, Product>
     */
    private function countableProducts(): Collection
    {
        return Product::query()
            ->with(['unitOfMeasure:id,name,abbreviation', 'category:id,name', 'brand:id,name,code'])
            ->where('is_active', true)
            ->stocked()
            ->get()
            ->sortBy([
                fn (Product $a, Product $b) => strcmp((string) $a->category?->name, (string) $b->category?->name),
                fn (Product $a, Product $b) => strcmp((string) $a->name, (string) $b->name),
            ])
            ->values();
    }

    /**
     * The typing-back template.
     *
     * ⚠⚠ Every column here is one `OpeningStockFile` recognises. An unknown
     * column is FATAL to that reader by design, so adding a helpful
     * "instructions" column would break the very file it is trying to help
     * with. The instructions live on the printed page instead.
     *
     * ⚠ UTF-8 BOM on purpose. Without it Excel on Windows renders every Bangla
     * product name as mojibake, and the reader already strips the BOM from the
     * first header cell because Excel writes one back.
     *
     * @param  Collection<int, Product>  $products
     */
    private function csv(Warehouse $warehouse, Collection $products, string $asAt): StreamedResponse
    {
        $name = 'opening-stock-'.strtolower((string) $warehouse->code).'-'.$asAt.'.csv';

        return response()->streamDownload(function () use ($warehouse, $products) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['sku', 'product_name', 'unit', 'warehouse', 'quantity', 'unit_cost', 'batch_number', 'expiry_date', 'note']);

            foreach ($products as $product) {
                fputcsv($out, [
                    $product->sku,
                    $product->name,
                    // ⚠ The ABBREVIATION, not the name. C-OPN-4 compares this
                    // string against `unitOfMeasure->abbreviation`; "Piece"
                    // where the system says "pcs" fails every row in the file.
                    $product->unitOfMeasure?->abbreviation,
                    $warehouse->code,
                    // Never pre-filled. See the class comment.
                    '', '', '', '', '',
                ]);
            }

            fclose($out);
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
