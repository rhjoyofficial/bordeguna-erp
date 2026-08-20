<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Sales\Actions\ApplyPriceListImport;
use App\Modules\Sales\Actions\PlanPriceListImport;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\PriceListFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The price-list importer, as a screen.
 *
 * ⚠⚠ THE EFFECTIVE DATE IS PART OF WHAT IS REVIEWED, not a detail chosen at the
 * end. It decides which rules currently in force get CLOSED the day before the
 * new ones start, so the same file previewed for two different dates produces
 * two genuinely different outcomes. It is therefore carried through the preview
 * and re-applied verbatim, exactly like the file's own fingerprint.
 *
 * ⚠ Prices are stored PER GROUP with no fallback: a product priced for RETAIL
 * only still refuses every DEALER and DISTRIBUTOR sale. The screen says so,
 * because a green preflight has hidden that fact before.
 */
class PriceImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('sales/prices/import', $this->page(null));
    }

    /**
     * C-IMP-1. Upload, choose the date it takes effect, and see what it would do.
     */
    public function preview(Request $request, PlanPriceListImport $planner): Response
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
            'effective_from' => ['required', 'date'],
        ], [], ['file' => 'CSV file', 'effective_from' => 'effective date']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '
                    .number_format(StagedImportFile::MAX_ROWS).'.',
            ]);
        }

        $effectiveFrom = Carbon::parse($data['effective_from'])->startOfDay();

        try {
            $read = PriceListFile::read($staged->path);
        } catch (RuntimeException $e) {
            $staged->discard($businessId);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($read['rows'], $read['ignored'], $effectiveFrom);

        return Inertia::render('sales/prices/import', $this->page([
            'token' => $staged->token,
            'hash' => $staged->hash,
            'file_name' => $staged->originalName,
            'effective_from' => $effectiveFrom->toDateString(),
            'rows' => $staged->rows,
            'errors' => $plan->errors,
            'warnings' => $plan->warnings,
            'creates' => $plan->creates,
            'supersedes' => $plan->supersedes,
            'unchanged' => count($plan->unchanged),
            'ignored_columns' => $plan->ignoredColumns,
            // ⚠⚠ The honest answer to "what still cannot be sold after this".
            // A product with no price in force does not sell at a default; the
            // sale is refused.
            'still_unpriced' => $plan->stillUnpriced,
        ]));
    }

    /**
     * C-IMP-2 and C-IMP-3.
     */
    public function store(Request $request, PlanPriceListImport $planner, ApplyPriceListImport $applier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedImportFile::reopen($data['token'], $data['hash'], $businessId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $effectiveFrom = Carbon::parse($data['effective_from'])->startOfDay();
        $read = PriceListFile::read($staged->path);

        // ⚠⚠ Re-planned from the bytes on disk and the SAME date, never from
        // anything carried through the form. The preview is a picture; this is
        // the decision, and it must be taken again from the source.
        $plan = $planner->handle($read['rows'], $read['ignored'], $effectiveFrom);

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so no prices were changed.',
            ]);
        }

        $applier->handle($plan, $effectiveFrom, $request->user());
        $staged->discard($businessId);

        return redirect()
            ->route('sales.prices.index')
            ->with('success', count($plan->creates).' price rule(s) created from '.$effectiveFrom->toDateString()
                .', '.count($plan->supersedes).' closed.');
    }

    /**
     * C-IMP-5. Every rule in force, in the shape the importer reads back.
     */
    public function export(): StreamedResponse
    {
        $groups = PriceGroup::orderBy('code')->get();
        $columnFor = array_flip(PriceListFile::PRICE_COLUMNS);

        $rules = ProductPrice::with(['product', 'priceGroup'])
            ->whereNull('effective_to')
            ->whereNotNull('price_group_id')
            ->get();

        // One row per (product, min_quantity), a column per group, because that
        // is the shape a person edits in a spreadsheet.
        $rows = [];

        foreach ($rules as $rule) {
            $key = $rule->product->sku.'|'.$rule->min_quantity;
            $rows[$key] ??= [
                'sku' => $rule->product->sku,
                'product_name' => $rule->product->name,
                'min_quantity' => $rule->min_quantity,
            ];

            $column = $columnFor[strtoupper($rule->priceGroup->code)] ?? null;

            if ($column !== null) {
                $rows[$key][$column] = $rule->unit_price;
            }
        }

        ksort($rows);

        $columns = array_values(array_filter(
            array_keys(PriceListFile::PRICE_COLUMNS),
            fn ($c) => $groups->contains(fn ($g) => strtoupper($g->code) === PriceListFile::PRICE_COLUMNS[$c])
        ));

        $header = array_merge(['sku', 'product_name', 'min_quantity'], $columns);

        return response()->streamDownload(function () use ($rows, $header, $columns) {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM, or Excel on Windows mangles every Bangla name.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_merge(
                    [$row['sku'], $row['product_name'], $row['min_quantity']],
                    array_map(fn ($c) => $row[$c] ?? '', $columns)
                ));
            }

            fclose($out);
        }, 'prices-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string,mixed>|null  $preview
     * @return array<string,mixed>
     */
    private function page(?array $preview): array
    {
        return [
            'preview' => $preview,
            'groups' => PriceGroup::orderBy('code')->get(['code', 'name'])->all(),
            'price_columns' => array_keys(PriceListFile::PRICE_COLUMNS),
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
        ];
    }
}
