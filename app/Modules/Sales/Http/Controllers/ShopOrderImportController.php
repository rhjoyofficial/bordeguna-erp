<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Actions\ApplyShopOrderImport;
use App\Modules\Sales\Actions\PlanShopOrderImport;
use App\Modules\Sales\Models\SalesOrderImport;
use App\Modules\Sales\Models\SalesOrderImportOrder;
use App\Modules\Sales\Support\ShopOrderSheet;
use App\Modules\Sales\Support\StagedShopOrderFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales > Orders > Import orders: shop orders from a sheet (Joy, 11 Oct 2026,
 * until the ERP website module takes them directly).
 *
 * ⚠ Upload, preview, apply - the price importer's shape (C-IMP-1/2): the file's
 * SHA-256 travels from the preview to Apply and is checked against the bytes on
 * disk, and Apply plans again from those bytes.
 *
 * Permissions: `sales.orders.record` on the routes; a changed price needs
 * `sales.price.override` (checked per line by the planner), recording an advance
 * `sales.receipt.record`, auto-approving `sales.orders.approve`.
 */
class ShopOrderImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('sales/orders/import', $this->page($request, null, null));
    }

    public function show(Request $request, SalesOrderImport $import): Response
    {
        return Inertia::render('sales/orders/import', $this->page($request, null, $import));
    }

    public function template(Request $request): StreamedResponse
    {
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        if ($format === 'csv') {
            $csv = ShopOrderSheet::csvTemplate();

            return response()->streamDownload(fn () => print ($csv), 'shop-orders-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $brands = Brand::query()->where('is_active', true)->orderBy('name')->get(['name', 'code'])
            ->map(fn (Brand $b) => $b->name.($b->code ? " ({$b->code})" : ''))->values()->all();
        $bytes = ShopOrderSheet::xlsxTemplate($brands);

        return response()->streamDownload(fn () => print ($bytes), 'shop-orders-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(Request $request, PlanShopOrderImport $planner): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
        ], [], ['file' => 'order sheet']);

        $businessId = app(BusinessContext::class)->id();
        $upload = $request->file('file');
        $staged = StagedShopOrderFile::stage($upload, $businessId);

        $first = SalesOrderImport::query()->where('file_sha256', $staged->hash)->first();

        if ($first !== null) {
            $staged->discard();

            throw ValidationException::withMessages([
                'file' => "This exact file was already imported on {$first->created_at?->format('Y-m-d H:i')} (import #{$first->id}). Nothing new to do.",
            ]);
        }

        try {
            $read = ShopOrderSheet::read($staged->path, $staged->extension);
        } catch (RuntimeException $e) {
            $staged->discard();

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if (count($read['rows']) > StagedImportFile::MAX_ROWS) {
            $staged->discard();

            throw ValidationException::withMessages(['file' => 'That file has '.number_format(count($read['rows'])).' rows. The limit is '.number_format(StagedImportFile::MAX_ROWS).'.']);
        }

        $plan = $planner->handle($read['rows'], $read['ignored'], $request->user(), $businessId);

        return Inertia::render('sales/orders/import', $this->page($request, [
            'token' => $staged->token,
            'hash' => $staged->hash,
            'file_name' => $upload->getClientOriginalName(),
            ...$plan->toArray(),
        ], null));
    }

    public function store(Request $request, PlanShopOrderImport $planner, ApplyShopOrderImport $applier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string', 'size:64'],
            'file_name' => ['required', 'string', 'max:255'],
            'auto_approve' => ['nullable', 'boolean'],
            'mappings' => ['nullable', 'array'],
            'mappings.*' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $autoApprove = (bool) ($data['auto_approve'] ?? false);

        if ($autoApprove && ! $user->can('sales.orders.approve')) {
            throw ValidationException::withMessages(['auto_approve' => 'Approving orders needs the approve permission. Import them to wait for approval instead.']);
        }

        $mappings = collect($data['mappings'] ?? [])->filter(fn ($id) => $id !== null && $id !== '')
            ->filter(fn ($id, $method) => in_array($method, ShopOrderSheet::ADVANCE_METHODS, true));

        if ($mappings->isNotEmpty() && ! $user->can('sales.receipt.record')) {
            throw ValidationException::withMessages(['mappings' => 'Recording an advance as a receipt needs the receipt permission. Leave the accounts empty and the advance is written into the order note.']);
        }

        $accounts = MoneyAccount::query()->active()->handled()->withLedger()->whereIn('id', $mappings->values()->all())->get()->keyBy('id');
        $moneyAccounts = [];

        foreach ($mappings as $method => $id) {
            $account = $accounts->get((int) $id);

            if ($account === null) {
                throw ValidationException::withMessages(["mappings.{$method}" => 'Choose an active bank, cash or wallet account of this business.']);
            }

            $moneyAccounts[$method] = $account;
        }

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedShopOrderFile::reopen($data['token'], $data['hash'], $businessId);
            $read = ShopOrderSheet::read($staged->path, $staged->extension);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        // ⚠⚠ Planned again from the bytes on disk; the preview was a picture.
        $plan = $planner->handle($read['rows'], $read['ignored'], $user, $businessId);
        $import = $applier->handle($plan, $staged, $data['file_name'], $businessId, $user, $autoApprove, $moneyAccounts);

        return redirect()->route('sales.orders.imports.show', $import)->with(
            'success',
            "{$import->orders_created} order(s) imported, {$import->orders_skipped} already there".($import->orders_failed > 0 ? ", {$import->orders_failed} failed" : '').'.'
        );
    }

    /**
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function page(Request $request, ?array $preview, ?SalesOrderImport $result): array
    {
        $user = $request->user();

        return [
            'preview' => $preview,
            'result' => $result === null ? null : $this->result($result),
            'recent' => SalesOrderImport::query()->with('createdBy:id,name')->latest('id')->limit(10)->get()
                ->map(fn (SalesOrderImport $i) => [
                    'id' => $i->id,
                    'file_name' => $i->file_name,
                    'at' => $i->created_at?->format('Y-m-d H:i'),
                    'by' => $i->createdBy?->name,
                    'created' => $i->orders_created,
                    'skipped' => $i->orders_skipped,
                    'failed' => $i->orders_failed,
                ])->values(),
            'columns' => collect(ShopOrderSheet::COLUMNS)->map(fn (array $c, string $name) => [
                'name' => $name,
                'level' => $c[0],
                'help' => $c[1],
                'required' => in_array($name, ShopOrderSheet::REQUIRED_COLUMNS, true),
            ])->values(),
            'money_accounts' => $user->can('sales.receipt.record')
                ? MoneyAccount::query()->active()->handled()->withLedger()->orderBy('name')->get(['id', 'name', 'type'])
                    ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type->label()])->values()
                : [],
            'can' => [
                'approve' => $user->can('sales.orders.approve'),
                'override_price' => $user->can('sales.price.override'),
                'record_receipt' => $user->can('sales.receipt.record'),
                'book_parcels' => $user->can('sales.sell') && $user->can('couriers.parcels.book'),
            ],
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function result(SalesOrderImport $import): array
    {
        $import->load(['orders.order:id,order_number,status', 'orders.order.approvalRequest', 'createdBy:id,name']);

        return [
            'id' => $import->id,
            'file_name' => $import->file_name,
            'at' => $import->created_at?->format('Y-m-d H:i'),
            'by' => $import->createdBy?->name,
            'rows' => $import->rows,
            'created' => $import->orders_created,
            'skipped' => $import->orders_skipped,
            'failed' => $import->orders_failed,
            'auto_approve' => $import->auto_approve,
            'orders' => $import->orders->sortBy('id')->map(fn (SalesOrderImportOrder $o) => [
                'order_ref' => $o->order_ref,
                'outcome' => $o->outcome,
                'message' => $o->message,
                'brand' => $o->brand_name,
                'channel' => $o->source_channel,
                'order' => $o->order === null ? null : [
                    'id' => $o->order->id,
                    'order_number' => $o->order->order_number,
                    'status' => ucfirst(str_replace('_', ' ', $o->order->status->value)),
                    'waiting' => (bool) $o->order->approvalRequest?->isPending(),
                ],
            ])->values(),
        ];
    }
}
