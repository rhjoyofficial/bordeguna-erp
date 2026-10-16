<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
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
}
