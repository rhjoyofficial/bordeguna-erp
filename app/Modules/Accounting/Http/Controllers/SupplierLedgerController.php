<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Accounting\Support\SupplierLedger;
use App\Modules\Accounting\Support\UntaggedGrni;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ The supplier ledger view on a page of its own (A1, 18 Oct 2026), the
 * same view the General Ledger opens for a supplier's label head, reachable
 * from the supplier's profile and the supplier statement. Plus the exception
 * list of GRNI lines that name no supplier.
 */
class SupplierLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        $suppliers = Supplier::query()->orderBy('name')->get()->map(fn (Supplier $option) => [
            'id' => $option->id,
            'label' => $option->code ? "{$option->name} ({$option->code})" : $option->name,
            'is_active' => $option->is_active,
        ]);

        $supplier = $request->filled('supplier')
            ? Supplier::query()->find($request->integer('supplier'))
            : null;

        return Inertia::render('accounting/supplier-ledger/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'suppliers' => $suppliers,
            'ledger' => $supplier === null ? null : SupplierLedger::for($supplier, $from, $to)->toArray(),
        ]);
    }

    /**
     * Every GRNI line that names no supplier, with the supplier an exact
     * document link derives it to (or "unknown"), and the open amount per
     * supplier. Read-only: posted lines are never rewritten.
     */
    public function untaggedGrni(): Response
    {
        $rows = UntaggedGrni::rows();

        $bySupplier = $rows->groupBy(fn (array $row) => $row['supplier_id'] ?? 0)
            ->map(fn ($group) => [
                'supplier_id' => $group->first()['supplier_id'],
                'supplier' => $group->first()['supplier'],
                'lines' => $group->count(),
                'open' => Money::sum($group->map(fn (array $row) => Money::ofMinor($row['received_minor'])))->format(),
            ])
            ->sortBy(fn (array $row) => $row['supplier'] ?? "\u{FFFF}")
            ->values();

        return Inertia::render('accounting/grni-untagged/index', [
            'rows' => $rows->map(fn (array $row) => collect($row)->except('received_minor')->all() + [
                'received' => Money::ofMinor($row['received_minor'])->format(),
            ])->values(),
            'suppliers' => $bySupplier,
            'total' => Money::sum($rows->map(fn (array $row) => Money::ofMinor($row['received_minor'])))->format(),
        ]);
    }

    /**
     * Defaults to the fiscal year to date, like every other accounting report.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();

        $yearStart = $business === null
            ? $today->copy()->startOfYear()
            : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? ReportDates::parse($request, 'from') : $yearStart;
        $to = $request->filled('to') ? ReportDates::parse($request, 'to') : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
