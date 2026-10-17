<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Support\Code128;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printing the barcodes that were already on every product and had never been
 * put on paper.
 *
 * ⚠⚠ Products have carried a `barcode` column since 4 August and NOTHING has
 * ever rendered one. The data was entered and then had no way out of the
 * system.
 *
 * ⚠ A separate print page rather than a print stylesheet over the picker, which
 * is the pattern the invoice, challan, money receipt and purchase order already
 * follow here: browser print IS the download, and a sheet of labels has nothing
 * in common with the screen that chose them.
 */
class LabelSheetController extends Controller
{
    /** Sizes that fit the packs Bor de Guna actually sells. */
    private const SIZES = [
        'small' => ['label' => 'Small - 38 x 21 mm (jars, bottles)', 'w' => 38, 'h' => 21, 'bar_h' => 9],
        'medium' => ['label' => 'Medium - 50 x 30 mm (pouches)', 'w' => 50, 'h' => 30, 'bar_h' => 13],
        'large' => ['label' => 'Large - 70 x 40 mm (cartons)', 'w' => 70, 'h' => 40, 'bar_h' => 18],
    ];

    public function index(Request $request): Response
    {
        // ⚠ Bug hunt 16 Oct 2026: the route, the button and this check now
        // ask for the same permission. A role with import alone saw the button
        // and got a 403 here.
        $this->authorize('inventory.catalogue.import');

        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'barcode', 'name']);

        return Inertia::render('inventory/labels/index', [
            // ⚠ Products WITHOUT a barcode are sent too, flagged rather than
            // filtered away. Hiding them would answer "why is this product
            // missing" with silence; the screen says it has no barcode yet.
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'barcode' => $p->barcode,
            ])->values(),
            'with_barcode' => $products->whereNotNull('barcode')->count(),
            'without_barcode' => $products->whereNull('barcode')->count(),
            'sizes' => collect(self::SIZES)
                ->map(fn (array $s, string $key) => ['value' => $key, 'label' => $s['label']])
                ->values(),
        ]);
    }

    /**
     * The sheet itself. Quantities arrive as `qty[<product id>]`.
     */
    public function print(Request $request): Response
    {
        // ⚠ Bug hunt 16 Oct 2026: the route, the button and this check now
        // ask for the same permission. A role with import alone saw the button
        // and got a 403 here.
        $this->authorize('inventory.catalogue.import');

        $data = $request->validate([
            'size' => ['nullable', 'string'],
            'show_price' => ['nullable'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $size = self::SIZES[$data['size'] ?? 'small'] ?? self::SIZES['small'];

        $wanted = collect($data['qty'])
            ->map(fn ($n) => (int) $n)
            ->filter(fn (int $n) => $n > 0);

        $products = Product::query()
            ->whereIn('id', $wanted->keys())
            ->get(['id', 'sku', 'barcode', 'name'])
            ->keyBy('id');

        $labels = [];
        $skipped = [];

        foreach ($wanted as $id => $count) {
            $product = $products->get((int) $id);

            if ($product === null) {
                continue;
            }

            // ⚠⚠ A product with no barcode is NAMED on the sheet's header, not
            // silently dropped. Someone who asked for 40 labels and got 30 needs
            // to know which ten are missing and why.
            if (blank($product->barcode)) {
                $skipped[] = $product->sku.' - '.$product->name;

                continue;
            }

            // ⚠ Bug hunt 16 Oct 2026: Code 128 takes printable ASCII only, and
            // one barcode pasted with Bangla digits or a non-breaking space
            // threw, so no label printed at all. That product is named instead.
            if (preg_match('/^[\x20-\x7E]+$/', (string) $product->barcode) !== 1) {
                $skipped[] = $product->sku.' - '.$product->name.' (the barcode has characters a label cannot print)';

                continue;
            }

            $svg = Code128::svg($product->barcode, widthMm: $size['w'] - 6, heightMm: $size['bar_h']);

            for ($i = 0; $i < $count; $i++) {
                $labels[] = [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'svg' => $svg,
                ];
            }
        }

        return Inertia::render('inventory/labels/print', [
            'labels' => $labels,
            'skipped' => $skipped,
            'size' => [
                'w' => $size['w'],
                'h' => $size['h'],
                'label' => $size['label'],
            ],
        ]);
    }

    /**
     * ⭐ Traceability M1 (17 Oct 2026): the jar's own label, printed from the
     * production run that made it. Batch no. (the run's output batch), MFG,
     * EXP and MRP are what tie a jar in a customer's hand back to this run,
     * and the Bangladesh labelling rules ask for them on the pack anyway.
     *
     * The same sheet and the same Code 128 as the barcode labels; the number
     * of labels starts at the quantity the run made. English digits only.
     */
    public function batch(Request $request, ProductionRun $run): Response
    {
        $run->load(['outputBatch', 'outputProduct.brand:id,name', 'outputProduct.contentUnit:id,abbreviation']);
        $batch = $run->outputBatch;
        $product = $run->outputProduct;

        // Only a confirmed run has a batch on the shelf to label.
        abort_unless($run->isConfirmed() && $batch !== null && $product !== null, 404);

        $data = $request->validate([
            'size' => ['nullable', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $sizeKey = isset(self::SIZES[$data['size'] ?? '']) ? $data['size'] : 'medium';
        $size = self::SIZES[$sizeKey];
        $count = (int) ($data['count'] ?? max(1, (int) ceil((float) $run->output_quantity)));

        $barcode = $product->barcode;
        $printable = filled($barcode) && preg_match('/^[\x20-\x7E]+$/', (string) $barcode) === 1;
        $mrp = $batch->mrp ?? $product->mrp;

        $label = [
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $printable ? $barcode : null,
            'svg' => $printable ? Code128::svg($barcode, widthMm: $size['w'] - 6, heightMm: max(6, $size['bar_h'] - 5)) : null,
            'brand' => $product->brand?->name,
            'net_content' => $product->content_quantity !== null
                ? self::number((float) $product->content_quantity).' '.($product->contentUnit?->abbreviation ?? '')
                : null,
            'batch_number' => $batch->batch_number,
            'mfg' => $batch->manufactured_date?->format('d M Y'),
            'exp' => $batch->expiry_date?->format('d M Y'),
            'mrp' => $mrp !== null ? 'Tk '.self::number((float) $mrp, 2) : null,
        ];

        return Inertia::render('inventory/labels/print', [
            'labels' => array_fill(0, $count, $label),
            'skipped' => $printable ? [] : [$product->sku.' - '.$product->name.' (no printable barcode on the product: the labels print without bars)'],
            'size' => [
                'w' => $size['w'],
                'h' => $size['h'],
                'label' => $size['label'],
            ],
            'batch' => [
                'run_number' => $run->run_number,
                'back_url' => route('inventory.production-runs.show', $run, false),
                'count' => $count,
                'size' => $sizeKey,
                'sizes' => collect(self::SIZES)->map(fn (array $s, string $key) => ['value' => $key, 'label' => $s['label']])->values(),
            ],
        ]);
    }

    /** 500 → "500", 12.5 → "12.5"; with $decimals = 2, 950 → "950", 952.5 → "952.50". English digits. */
    private static function number(float $value, int $decimals = 4): string
    {
        if (abs($value - round($value)) < 0.00005) {
            return number_format($value, 0, '.', ',');
        }

        return $decimals === 2
            ? number_format($value, 2, '.', ',')
            : rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');
    }
}
