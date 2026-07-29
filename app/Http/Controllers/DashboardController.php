<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $canViewInventory = $user->can('inventory.reports.view');
        $canViewPurchasing = $user->can('purchasing.reports.view');
        $canSeeApprovals = $user->can('inventory.stock.approve') || $user->can('purchasing.po.approve');

        return Inertia::render('dashboard', [
            'stock_value' => $canViewInventory ? $this->stockValue() : null,
            'low_stock_count' => $canViewInventory ? $this->lowStockCount() : null,
            'open_po_count' => $canViewPurchasing ? $this->openPurchaseOrderCount() : null,
            'pending_approval_count' => $canSeeApprovals ? $this->pendingApprovalCount() : null,
            'purchase_trend' => $canViewPurchasing ? $this->purchaseTrend() : null,
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /**
     * Total value of stock on hand, formatted for display.
     *
     * ⚠⚠ THE FORMATTING IS NOT COSMETIC — it is a driver-portability fix, and
     * the THIRD instance of this shape on the project.
     *
     * `SUM(current_quantity * unit_cost)` multiplies two decimal columns, so
     * MySQL returns `19200.00000000` (eight decimal places) where SQLite
     * returns `19200`. The tile printed the raw string, so production showed
     * "19200.00000000" to a user while every test passed.
     *
     * ⚠ Grouped to two decimals rather than lakh–crore: BDT lakh/crore
     * formatting is deliberately unimplemented pending the W5 language
     * decision, and it belongs in one place (`ReportValue::forDisplay`) when it
     * arrives — not invented separately here.
     */
    private function stockValue(): string
    {
        $value = StockBatch::where('status', StockBatchStatus::Active)
            ->selectRaw('SUM(current_quantity * unit_cost) as value')
            ->value('value') ?? 0;

        return number_format((float) $value, 2);
    }

    private function lowStockCount(): int
    {
        return Product::stocked()->where('is_active', true)->get()
            ->filter->isBelowLowStockThreshold()
            ->count();
    }

    private function openPurchaseOrderCount(): int
    {
        return PurchaseOrder::whereIn('status', [
            PurchaseOrderStatus::Open,
            PurchaseOrderStatus::PartiallyReceived,
        ])->count();
    }

    private function pendingApprovalCount(): int
    {
        return ApprovalRequest::where('status', ApprovalStatus::Pending)->count();
    }

    /**
     * Total purchase order value per month for the trailing 6 months
     * (ordered value, not just received) — one series, so no legend needed.
     */
    private function purchaseTrend(): array
    {
        $months = collect(range(5, 0))->map(fn (int $i) => now()->subMonths($i)->startOfMonth());

        $totalsByMonth = PurchaseOrder::with('lines')
            ->where('created_at', '>=', $months->first())
            ->get()
            ->groupBy(fn (PurchaseOrder $po) => $po->created_at->format('Y-m'))
            ->map(fn ($purchaseOrders) => $purchaseOrders->flatMap->lines
                ->sum(fn ($line) => (float) $line->quantity_ordered * (float) $line->unit_cost));

        return $months->map(fn ($month) => [
            'month' => $month->format('M'),
            'value' => round($totalsByMonth->get($month->format('Y-m'), 0), 2),
        ])->values()->all();
    }

    private function recentActivity(): array
    {
        return ActivityLogEntry::with('causer')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (ActivityLogEntry $entry) => [
                'id' => $entry->id,
                'description' => trim($this->subjectLabel($entry->subject_type) . ' ' . $entry->description),
                'causer_name' => $entry->causer?->name,
                'created_at' => $entry->created_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Turns "App\Modules\Purchasing\Models\PurchaseOrder" into "Purchase Order"
     * so the feed reads "Purchase Order updated" instead of a bare "updated".
     */
    private function subjectLabel(?string $subjectType): string
    {
        if ($subjectType === null) {
            return '';
        }

        $shortName = class_basename($subjectType);

        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $shortName));
    }
}
