<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Inventory\Actions\ApplyProductCatalogueImport;
use App\Modules\Inventory\Actions\PlanProductCatalogueImport;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductCatalogueFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The catalogue importer, as a screen.
 *
 * ⚠⚠ WHY THIS EXISTS. The engine underneath has been live and exercised against
 * real data for weeks - and only ever from an artisan command on the VPS, so the
 * only way Joy's data reached production was to send it to me and wait. That is
 * the bottleneck, not the code. See feedback_joy_self_service_data.
 *
 * ⚠ The Plan and Apply actions are untouched. This is a screen over proven code,
 * which is exactly why it can ship quickly and be trusted.
 */
class ProductImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('inventory/products/import', [
            'preview' => null,
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
            'columns' => [
                'mandatory' => ProductCatalogueFile::MANDATORY,
                'optional' => ProductCatalogueFile::OPTIONAL,
            ],
        ]);
    }

    /**
     * C-IMP-1. Upload, and show what it WOULD do. Writes nothing.
     */
    public function preview(Request $request, PlanProductCatalogueImport $planner): Response|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
        ], [], ['file' => 'CSV file']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '
                    .number_format(StagedImportFile::MAX_ROWS).'. Split it and import the parts.',
            ]);
        }

        try {
            $rows = ProductCatalogueFile::read($staged->path);
        } catch (RuntimeException $e) {
            $staged->discard($businessId);

            // ⚠ A header problem is fatal in the reader on purpose: a file whose
            // columns are not understood produces hundreds of row errors all
            // saying the same thing badly.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($rows);

        return Inertia::render('inventory/products/import', [
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
            'columns' => [
                'mandatory' => ProductCatalogueFile::MANDATORY,
                'optional' => ProductCatalogueFile::OPTIONAL,
            ],
            'preview' => [
                'token' => $staged->token,
                'hash' => $staged->hash,
                'file_name' => $staged->originalName,
                'rows' => $staged->rows,
                'errors' => $plan->errors,
                'warnings' => $plan->warnings,
                'creates' => array_map(fn ($c) => ['line' => $c['line'], 'sku' => $c['sku'], 'name' => $c['name']], $plan->creates),
                'updates' => array_map(fn ($u) => [
                    'line' => $u['line'], 'sku' => $u['sku'], 'name' => $u['name'],
                    // What would actually change, field by field, so nobody has
                    // to guess what "42 to update" means.
                    'changes' => collect($u['changes'])->map(fn ($pair, $field) => [
                        'field' => $field, 'from' => (string) $pair[0], 'to' => (string) $pair[1],
                    ])->values()->all(),
                ], $plan->updates),
                'unchanged' => count($plan->unchanged),
                'new_categories' => array_values($plan->newCategories),
                'new_units' => array_values(array_map(fn ($u) => $u['name'].' ('.$u['abbreviation'].')', $plan->newUnits)),
                // C-CAT-1: reported so the number is never a surprise, and
                // otherwise left completely alone.
                'absent_from_file' => $plan->absentFromFile,
            ],
        ]);
    }

    /**
     * C-IMP-2 and C-IMP-3. Re-read the file, prove it is the reviewed one, and
     * apply it in the Apply action's own single transaction.
     */
    public function store(Request $request, PlanProductCatalogueImport $planner, ApplyProductCatalogueImport $applier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedImportFile::reopen($data['token'], $data['hash'], $businessId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        // ⚠⚠ Re-planned from the bytes on disk, never from anything carried
        // through the form. The preview is a picture; this is the decision.
        $plan = $planner->handle(ProductCatalogueFile::read($staged->path));

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so nothing was imported. Fix them and upload it again.',
            ]);
        }

        $applier->handle($plan);
        $staged->discard($businessId);

        return redirect()
            ->route('inventory.products.index')
            ->with('success', count($plan->creates).' product(s) created, '.count($plan->updates).' updated.');
    }

    /**
     * C-IMP-5. The importer's own header, in the importer's own order, so the
     * round trip is export, edit in Excel, upload, preview, apply.
     */
    public function export(): StreamedResponse
    {
        $products = Product::with(['category', 'brand', 'unitOfMeasure', 'contentUnit'])->orderBy('sku')->get();

        return response()->streamDownload(function () use ($products) {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM. Without it Excel on Windows renders every Bangla
            // product name as mojibake, and the file comes back full of
            // corrupted names that then import as new products.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, array_merge(ProductCatalogueFile::MANDATORY, ProductCatalogueFile::OPTIONAL));

            foreach ($products as $product) {
                fputcsv($out, [
                    $product->sku,
                    $product->name,
                    $product->category?->name,
                    $product->unitOfMeasure?->name,
                    $product->unitOfMeasure?->abbreviation,
                    $product->type->value,
                    $product->brand?->code,
                    $product->barcode,
                    $product->description,
                    $product->low_stock_threshold,
                    $product->is_active ? '1' : '0',
                    // ProductCatalogueFile::PRODUCT_DETAILS, so the round trip keeps them.
                    $product->tracks_expiry ? '1' : '0',
                    $product->shelf_life_warning_days,
                    $product->content_quantity,
                    $product->contentUnit?->abbreviation,
                    $product->weight_grams,
                ]);
            }

            fclose($out);
        }, 'products-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
