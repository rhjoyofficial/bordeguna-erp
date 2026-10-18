<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Outstanding orders (purchase redesign PR2, 18 Oct 2026; Tally's Purchase
 * Order Outstanding): every order line still waiting for goods, with what was
 * ordered, what came and what is still to come, its value, and how late it is
 * against the order's expected date. By line, by supplier or by product.
 *
 * - An order counts while it is Open or Partly received and not rejected; one
 *   still waiting for approval is listed and marked, because the goods are
 *   booked even if they cannot be received yet.
 * - The pending value is the order line's own value of the pending quantity
 *   (PurchaseOrderLine::valueOf), the figure the receipt would credit to GRNI.
 *   Integer paisa throughout.
 */
class OutstandingOrdersController extends Controller
{
    /** The most lines the screen draws at once; the totals always count all. */
    private const MAX_ROWS = 500;

    public function index(Request $request): Response
    {
        $filters = [
            'supplier_id' => $request->string('supplier_id')->toString(),
            'product_id' => $request->string('product_id')->toString(),
            'warehouse_id' => $request->string('warehouse_id')->toString(),
            'overdue' => $request->boolean('overdue') ? '1' : '',
            'view' => in_array($request->string('view')->toString(), ['supplier', 'product'], true) ? $request->string('view')->toString() : 'line',
        ];

        $today = Carbon::today()->toDateString();

        $lines = PurchaseOrderLine::query()
            ->whereColumn('quantity_received', '<', 'quantity_ordered')
            ->whereHas('purchaseOrder', fn (Builder $po) => $po
                ->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->whereDoesntHave('approvalRequest', fn (Builder $approval) => $approval->where('status', ApprovalStatus::Rejected->value))
                ->when($filters['supplier_id'], fn (Builder $q, string $id) => $q->where('supplier_id', (int) $id))
                ->when($filters['warehouse_id'], fn (Builder $q, string $id) => $q->where('warehouse_id', (int) $id))
                ->when($filters['overdue'], fn (Builder $q) => $q->whereNotNull('expected_date')->whereDate('expected_date', '<', $today)))
            ->when($filters['product_id'], fn (Builder $q, string $id) => $q->where('product_id', (int) $id))
            ->with([
                'purchaseOrder:id,po_number,supplier_id,warehouse_id,status,order_date,expected_date',
                'purchaseOrder.supplier:id,name',
                'purchaseOrder.warehouse:id,name',
                'product:id,name,sku,unit_of_measure_id,purchase_unit_id',
                'product.unitOfMeasure:id,abbreviation',
                'product.purchaseUnit:id,abbreviation',
            ])
            ->get()
            ->map(fn (PurchaseOrderLine $line) => $this->row($line, $today))
            ->sort(fn (array $a, array $b) => [$a['expected_date'] ?? '9999-12-31', $a['po_number'], $a['id']] <=> [$b['expected_date'] ?? '9999-12-31', $b['po_number'], $b['id']])
            ->values();

        $totals = [
            'lines' => $lines->count(),
            'orders' => $lines->pluck('purchase_order_id')->unique()->count(),
            'overdue_lines' => $lines->where('overdue_days', '>', 0)->count(),
            'pending_value' => Money::ofMinor((int) $lines->sum('pending_paisa'))->toTaka(),
            'overdue_value' => Money::ofMinor((int) $lines->where('overdue_days', '>', 0)->sum('pending_paisa'))->toTaka(),
        ];

        return Inertia::render('purchasing/outstanding-orders/index', [
            'rows' => $filters['view'] === 'line' ? $lines->take(self::MAX_ROWS)->values() : [],
            'groups' => $filters['view'] === 'line' ? [] : $this->grouped($lines, $filters['view']),
            'truncated' => $filters['view'] === 'line' && $lines->count() > self::MAX_ROWS,
            'totals' => $totals,
            'filters' => $filters,
            'today' => $today,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            // Only products that are on an open line, so the picker stays short.
            'products' => Product::query()
                ->whereIn('id', PurchaseOrderLine::query()->whereColumn('quantity_received', '<', 'quantity_ordered')->select('product_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
            'can_receive' => $request->user()->can('purchasing.receipt.record'),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(PurchaseOrderLine $line, string $today): array
    {
        $order = $line->purchaseOrder;
        $pendingScaled = PurchaseLineEntry::scaled((string) $line->quantity_ordered) - PurchaseLineEntry::scaled((string) $line->quantity_received);
        $pending = number_format(max(0, $pendingScaled) / 10000, 4, '.', '');
        $expected = $order?->expected_date?->toDateString();

        return [
            'id' => $line->id,
            'purchase_order_id' => $order?->id,
            'po_number' => (string) $order?->po_number,
            'status' => $order?->status?->value,
            'approved' => $order?->approvalRequest?->status === ApprovalStatus::Approved,
            'supplier' => $order?->supplier === null ? null : ['id' => $order->supplier->id, 'name' => $order->supplier->name],
            'warehouse' => $order?->warehouse?->name,
            'product' => $line->product === null ? null : ['id' => $line->product->id, 'name' => $line->product->name, 'sku' => $line->product->sku],
            'unit' => $line->product?->purchaseUnit?->abbreviation ?? $line->product?->unitOfMeasure?->abbreviation,
            'ordered' => (string) $line->quantity_ordered,
            'received' => (string) $line->quantity_received,
            'pending' => $pending,
            'unit_cost' => (string) $line->unit_cost,
            'pending_paisa' => $line->valueOf($pending)->minor,
            'pending_value' => $line->valueOf($pending)->toTaka(),
            'order_date' => $order?->order_date?->toDateString(),
            'expected_date' => $expected,
            // ⚠ Whole days, compared as dates in the app's Dhaka clock.
            'overdue_days' => $expected !== null && $expected < $today
                ? (int) Carbon::parse($expected)->diffInDays(Carbon::parse($today))
                : 0,
        ];
    }

    /**
     * Rows summed by supplier or by product: lines, orders, pending value,
     * and how much of it is overdue.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function grouped(Collection $lines, string $by): array
    {
        return $lines
            ->groupBy(fn (array $row) => $by === 'supplier' ? ($row['supplier']['id'] ?? 0) : ($row['product']['id'] ?? 0))
            ->map(function (Collection $rows) use ($by) {
                $first = $rows->first();
                $overdue = $rows->where('overdue_days', '>', 0);

                return [
                    'key' => $by === 'supplier' ? ($first['supplier']['id'] ?? 0) : ($first['product']['id'] ?? 0),
                    'name' => $by === 'supplier' ? ($first['supplier']['name'] ?? '-') : ($first['product']['name'] ?? '-'),
                    'sku' => $by === 'product' ? ($first['product']['sku'] ?? null) : null,
                    // A product's pending quantity adds up only in one unit.
                    'pending' => $by === 'product' && $rows->pluck('unit')->unique()->count() === 1
                        ? number_format($rows->sum(fn (array $row) => PurchaseLineEntry::scaled($row['pending'])) / 10000, 4, '.', '')
                        : null,
                    'unit' => $by === 'product' ? $first['unit'] : null,
                    'lines' => $rows->count(),
                    'orders' => $rows->pluck('purchase_order_id')->unique()->count(),
                    'pending_value' => Money::ofMinor((int) $rows->sum('pending_paisa'))->toTaka(),
                    'overdue_value' => Money::ofMinor((int) $overdue->sum('pending_paisa'))->toTaka(),
                    'overdue_lines' => $overdue->count(),
                    'pending_paisa' => (int) $rows->sum('pending_paisa'),
                ];
            })
            ->sortByDesc('pending_paisa')
            ->values()
            ->all();
    }
}
