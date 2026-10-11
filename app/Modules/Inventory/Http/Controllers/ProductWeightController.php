<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Inventory\Actions\ApplyProductWeightImport;
use App\Modules\Inventory\Actions\PlanProductWeightImport;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductWeightFile;
use App\Modules\Inventory\Support\ProductWeightImportPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The weights sheet (courier hub slice 3, Joy 11 Oct 2026): download every
 * product with its current weight, fill the blanks in a spreadsheet, upload,
 * read the preview, apply. So the whole catalogue gets weights in one sitting
 * instead of one product dialog at a time.
 *
 * ⚠ The importers' contract (C-IMP-1/2/3): the preview writes nothing, the
 * apply re-reads the bytes it was shown (StagedImportFile's hash), and a sheet
 * with one error writes nothing at all. Gated with catalogue management, the
 * same permission that edits a product's weight on the dialog.
 */
class ProductWeightController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/products/weights', $this->pageProps(null));
    }

    /**
     * Every active stocked product, with its current weight, in the order the
     * importer reads. Services have no weight and are left out.
     */
    public function template(): StreamedResponse
    {
        $products = Product::query()->stocked()->where('is_active', true)->with('contentUnit')->orderBy('sku')->get();

        return response()->streamDownload(function () use ($products) {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM, or Excel on Windows shows every Bangla name as mojibake.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ProductWeightFile::TEMPLATE);

            foreach ($products as $product) {
                fputcsv($out, [
                    $product->sku,
                    $product->name,
                    $product->content_quantity === null ? '' : rtrim(rtrim((string) $product->content_quantity, '0'), '.').' '.($product->contentUnit?->abbreviation ?? ''),
                    $product->weight_grams,
                ]);
            }

            fclose($out);
        }, 'product-weights-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Upload, and show what it WOULD do. Writes nothing. */
    public function preview(Request $request, PlanProductWeightImport $planner): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
        ], [], ['file' => 'CSV file']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '.number_format(StagedImportFile::MAX_ROWS).'. Split it and import the parts.',
            ]);
        }

        try {
            $rows = ProductWeightFile::read($staged->path);
        } catch (RuntimeException $e) {
            $staged->discard($businessId);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($rows);

        return Inertia::render('inventory/products/weights', $this->pageProps([
            'token' => $staged->token,
            'hash' => $staged->hash,
            'file_name' => $staged->originalName,
            'rows' => $staged->rows,
            'errors' => $plan->errors,
            'changes' => array_map(fn (array $c) => [
                'line' => $c['line'], 'sku' => $c['sku'], 'name' => $c['name'], 'from' => $c['from'], 'to' => $c['to'],
            ], $plan->changes),
            'unchanged' => $plan->unchanged,
            'blank' => count($plan->blank),
        ]));
    }

    /** Re-read the reviewed file, prove it is the same bytes, and apply it. */
    public function store(Request $request, PlanProductWeightImport $planner, ApplyProductWeightImport $applier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedImportFile::reopen($data['token'], $data['hash'], $businessId);
            $plan = $planner->handle(ProductWeightFile::read($staged->path));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so nothing was changed. Fix them and upload it again.',
            ]);
        }

        $changed = $applier->handle($plan);
        $staged->discard($businessId);

        return redirect()
            ->route('inventory.products.weights')
            ->with('success', "{$changed} product weight(s) saved.");
    }

    /**
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function pageProps(?array $preview): array
    {
        $stocked = Product::query()->stocked()->where('is_active', true);

        return [
            'preview' => $preview,
            'summary' => [
                'products' => (clone $stocked)->count(),
                'missing' => (clone $stocked)->whereNull('weight_grams')->count(),
            ],
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
        ];
    }
}
