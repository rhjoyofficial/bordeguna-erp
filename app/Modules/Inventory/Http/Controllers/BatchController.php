<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\CsvSafe;
use App\Modules\Inventory\Actions\HoldStockBatch;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockBatchHold;
use App\Support\Traceability\BatchTrace;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batches: find one, trace it, hold it (Traceability M2 + M3, 17 Oct 2026).
 *
 * The trace page needs no new typing: it reads the movements, run, challan,
 * invoice, parcel and return links the ERP already keeps (see BatchTrace).
 *
 * Gates: reading rides `inventory.reports.view` like every stock screen; a
 * customer's phone number is shown (and exported) only to people who may see
 * customers (`sales.reports.view`, the customer list's own gate); holding and
 * releasing is for the hands that correct or approve stock
 * (`inventory.stock.adjust` or `inventory.stock.approve`).
 */
class BatchController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());

        $batches = StockBatch::query()
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('batch_number', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))))
            // Without a search: the newest batches that carry a number.
            ->when($search === '', fn ($query) => $query->whereNotNull('batch_number'))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (StockBatch $batch) => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $batch->product?->name,
                'product_id' => $batch->product_id,
                'sku' => $batch->product?->sku,
                'warehouse' => $batch->warehouse?->name,
                'on_hand' => BatchTrace::qty((float) $batch->current_quantity),
                'received_at' => $batch->received_at?->toDateString(),
                'manufactured_date' => $batch->manufactured_date?->toDateString(),
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'held' => $batch->isHeld(),
            ]);

        return Inertia::render('inventory/batches/index', [
            'batches' => $batches,
            'search' => $search,
        ]);
    }

    public function show(Request $request, StockBatch $batch): Response
    {
        $batch->load(['product:id,name,sku,brand_id,unit_of_measure_id', 'product.brand:id,name', 'product.unitOfMeasure:id,abbreviation', 'warehouse:id,name', 'heldBy:id,name']);
        $trace = new BatchTrace($batch);
        $user = $request->user();
        $seesCustomers = $user->can('sales.reports.view');

        $recipients = collect($trace->recipients())->map(fn (array $row) => $this->forViewer($row, $seesCustomers))->all();

        return Inertia::render('inventory/batches/show', [
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $batch->product?->name,
                'product_id' => $batch->product_id,
                'sku' => $batch->product?->sku,
                'brand' => $batch->product?->brand?->name,
                'unit' => $batch->product?->unitOfMeasure?->abbreviation,
                'warehouse' => $batch->warehouse?->name,
                'manufactured_date' => $batch->manufactured_date?->toDateString(),
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'mrp' => $batch->mrp,
                'received_at' => $batch->received_at?->toDateString(),
                'held' => $batch->isHeld(),
                'origin_id' => $trace->origin()->id,
            ],
            'backward' => $trace->backward(),
            'places' => $trace->places(),
            'runs' => $trace->runs(),
            'recipients' => $recipients,
            'returns' => $trace->returns(),
            'history' => $trace->history(),
            'holds' => StockBatchHold::query()
                ->whereIn('stock_batch_id', $trace->family()->pluck('id'))
                ->with(['user:id,name', 'batch.warehouse:id,name'])
                ->latest('id')
                ->get()
                ->map(fn (StockBatchHold $hold) => [
                    'id' => $hold->id,
                    'action' => $hold->action,
                    'reason' => $hold->reason,
                    'by' => $hold->user?->name,
                    'at' => $hold->created_at?->toDateTimeString(),
                    'warehouse' => $hold->batch?->warehouse?->name,
                ]),
            'can' => [
                'hold' => $this->mayHold($request),
                'see_customers' => $seesCustomers,
                'see_runs' => $user->can('inventory.production.manage'),
                'see_purchases' => $user->can('purchasing.reports.view'),
            ],
        ]);
    }

    /**
     * The recall list: everyone who received this batch (or, for a raw
     * material, what was made from it), as a spreadsheet.
     */
    public function export(Request $request, StockBatch $batch): StreamedResponse
    {
        $batch->load('product:id,name,sku');
        $trace = new BatchTrace($batch);
        $seesCustomers = $request->user()->can('sales.reports.view');
        $rows = $trace->recipients();
        $name = 'recall-list-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $batch->batch_number ?? 'batch-'.$batch->id).'.csv';

        return response()->streamDownload(function () use ($rows, $seesCustomers) {
            $out = fopen('php://output', 'w');

            // ⚠ UTF-8 BOM, or Excel on Windows shows every Bangla name as mojibake.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, array_values(array_filter([
                'Batch', 'Product', 'Customer',
                $seesCustomers ? 'Phone' : null,
                'Recipient',
                $seesCustomers ? 'Recipient phone' : null,
                'Challan', 'Date', 'Quantity', 'Returned', 'Invoice', 'Consignment', 'Courier', 'Made in run',
            ])));

            foreach ($rows as $row) {
                $line = [
                    CsvSafe::cell($row['batch_number']),
                    CsvSafe::cell($row['product']),
                    CsvSafe::cell($row['customer']),
                ];

                if ($seesCustomers) {
                    $line[] = CsvSafe::cell($row['phone']);
                }

                $line[] = CsvSafe::cell($row['recipient']);

                if ($seesCustomers) {
                    $line[] = CsvSafe::cell($row['recipient_phone']);
                }

                array_push(
                    $line,
                    CsvSafe::cell($row['delivery_number']),
                    $row['date'],
                    $row['quantity'],
                    $row['returned'],
                    CsvSafe::cell(collect($row['invoices'])->pluck('number')->implode(', ')),
                    CsvSafe::cell($row['consignment']),
                    CsvSafe::cell($row['courier']),
                    CsvSafe::cell($row['via_run']['run_number'] ?? null),
                );

                fputcsv($out, $line);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function hold(Request $request, StockBatch $batch, HoldStockBatch $action): RedirectResponse
    {
        return $this->change($request, $batch, fn (StockBatch $b, string $reason) => $action->hold($b, $request->user(), $reason), true);
    }

    public function release(Request $request, StockBatch $batch, HoldStockBatch $action): RedirectResponse
    {
        return $this->change($request, $batch, fn (StockBatch $b, string $reason) => $action->release($b, $request->user(), $reason), false);
    }

    /**
     * @param  callable(StockBatch, string): StockBatch  $apply
     */
    private function change(Request $request, StockBatch $batch, callable $apply, bool $holding): RedirectResponse
    {
        abort_unless($this->mayHold($request), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            // Everywhere = every warehouse's batch with this number (a recall);
            // otherwise only the one row (stock that got wet in one store).
            'everywhere' => ['boolean'],
        ], [
            'reason.required' => $holding ? 'Write why the batch is on hold.' : 'Write why the batch is released.',
        ]);

        $targets = ($data['everywhere'] ?? false)
            ? (new BatchTrace($batch))->family()->filter(fn (StockBatch $b) => $b->isHeld() !== $holding)
            : collect([$batch]);

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['reason' => $holding ? 'This batch is already on hold everywhere.' : 'This batch is not on hold anywhere.']);
        }

        try {
            foreach ($targets as $target) {
                $apply($target, $data['reason']);
            }
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $label = $batch->batch_number ?? "#{$batch->id}";

        return back()->with('success', $holding
            ? "Batch {$label} is on hold. It will not be picked for sales, challans, transfers or production runs."
            : "Batch {$label} is released and can be picked again.");
    }

    private function mayHold(Request $request): bool
    {
        return $request->user()->canAny(['inventory.stock.adjust', 'inventory.stock.approve']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function forViewer(array $row, bool $seesCustomers): array
    {
        if (! $seesCustomers) {
            $row['phone'] = null;
            $row['recipient_phone'] = null;
        }

        return $row;
    }
}
