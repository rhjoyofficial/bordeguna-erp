<?php

namespace App\Http\Controllers\Api\Hq;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HqApiContext;
use App\Modules\Crm\Http\Controllers\TicketController;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-first HQ API. Every method runs inside the tenant scope entered by HqApiContext, so
 * BusinessScope filters every model query. Each endpoint checks the token ability matching the
 * ERP permission the equivalent screen requires; a token without it gets 403, never partial data.
 *
 * Nothing here recomputes business figures with new logic: the queries mirror DashboardController
 * and the existing screens. What HQ may never read is simply not exposed (payroll rows, journals,
 * salary structures, approval decisions).
 */
final class HqReadController extends Controller
{
    public function businesses(Request $request): JsonResponse
    {
        // Ask the token about each business the user belongs to, rather than parsing the ability
        // list: that keeps a test-time transient token and a stored token on one code path.
        $user = $request->user();
        // ⚠ Outside the {business} group, so HqApiContext never runs here.
        if ($refused = HqApiContext::refuseInactive($user)) {
            return $refused;
        }
        $businesses = Business::withoutGlobalScopes()->where('is_active', true)
            ->whereIn('id', $user->businesses()->pluck('businesses.id'))->orderBy('name')->get(['id', 'name', 'is_active'])
            ->filter(fn ($b) => $user->tokenCan("hq:business:{$b->id}"))->values();

        return $this->ok($businesses->map(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'is_active' => (bool) $b->is_active,
        ]));
    }

    public function overview(Request $request, int $business): JsonResponse
    {
        $can = fn (string $ability) => $request->user()->tokenCan($ability);
        $month = now()->startOfMonth();

        return $this->ok([
            'sales' => $can('sales.reports.view') ? [
                'invoiced_this_month' => (float) SalesInvoice::query()->trading()->where('invoice_date', '>=', $month)->sum('total'),
                // Invoices carry no paid flag; "past due date" is what the Sales screens themselves show.
                'invoices_past_due_date' => (int) SalesInvoice::where('due_date', '<', now()->startOfDay())->count(),
                'customers' => Customer::where('is_active', true)->count(),
            ] : null,
            'inventory' => $can('inventory.reports.view') ? [
                'stock_value' => (float) (StockBatch::where('status', StockBatchStatus::Active)->selectRaw('SUM(current_value) / 100.0 as value')->value('value') ?? 0),
                'low_stock_count' => Product::stocked()->where('is_active', true)->get()->filter->isBelowLowStockThreshold()->count(),
                'active_products' => Product::where('is_active', true)->count(),
            ] : null,
            'purchasing' => $can('purchasing.reports.view') ? [
                'open_purchase_orders' => PurchaseOrder::whereIn('status', [PurchaseOrderStatus::Open, PurchaseOrderStatus::PartiallyReceived])->count(),
            ] : null,
            'approvals' => $can('sales.reports.view') || $can('inventory.reports.view') || $can('purchasing.reports.view') ? [
                'pending' => ApprovalRequest::where('status', ApprovalStatus::Pending)->count(),
            ] : null,
            'tickets' => $can('crm.tickets.view') ? [
                'open' => DB::table('crm_tickets')->where('business_id', $business)->where('status', 'open')->count(),
                'overdue' => DB::table('crm_tickets')->where('business_id', $business)->where('status', 'open')->where('resolve_by', '<', now())->count(),
            ] : null,
        ]);
    }

    public function stock(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'inventory.reports.view');
        $rows = StockBatch::query()->where('status', StockBatchStatus::Active)
            ->join('products', 'products.id', '=', 'stock_batches.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_batches.warehouse_id')
            ->when($request->filled('filter.sku'), fn ($q) => $q->where('products.sku', $request->input('filter.sku')))
            ->when($request->filled('filter.warehouse'), fn ($q) => $q->where('warehouses.code', $request->input('filter.warehouse')))
            ->groupBy('products.id', 'products.sku', 'products.name', 'warehouses.id', 'warehouses.code', 'warehouses.name')
            ->selectRaw('products.id as product_id, products.sku, products.name, warehouses.code as warehouse, warehouses.name as warehouse_name, SUM(stock_batches.current_quantity) as on_hand')
            ->orderBy('products.sku')->paginate(min((int) $request->integer('per_page', 100), 500));

        return $this->paginated($rows, $business);
    }

    public function lowStock(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'inventory.reports.view');
        $low = Product::stocked()->where('is_active', true)->get()->filter->isBelowLowStockThreshold()->values()->map(fn (Product $p) => [
            'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'on_hand' => (float) $p->currentStockQuantity(), 'threshold' => $p->low_stock_threshold,
        ]);

        return $this->ok($low, ['business_id' => $business, 'count' => $low->count()]);
    }

    public function products(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'inventory.view');
        $rows = Product::query()->with(['brand:id,code,name', 'unitOfMeasure:id,name,abbreviation'])->where('is_active', true)
            ->when($request->filled('filter.type'), fn ($q) => $q->where('type', $request->input('filter.type')))
            ->orderBy('sku')->paginate(min((int) $request->integer('per_page', 100), 500));
        $rows->through(fn (Product $p) => $this->productRow($p, $request));

        return $this->paginated($rows, $business);
    }

    public function product(Request $request, int $business, string $sku): JsonResponse
    {
        $this->requireAbility($request, 'inventory.view');
        $p = Product::with(['brand:id,code,name', 'unitOfMeasure:id,name,abbreviation'])->where('sku', $sku)->first();
        if (! $p) {
            return $this->error('not_found', "No product with SKU {$sku}.", 404);
        }

        return $this->ok($this->productRow($p, $request) + ['on_hand' => (float) $p->currentStockQuantity()]);
    }

    public function customers(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'sales.customers.view');
        $rows = Customer::query()->where('is_active', true)
            ->when($request->filled('filter.phone'), fn ($q) => $q->where('phone', 'like', '%'.preg_replace('/\D+/', '', (string) $request->input('filter.phone')).'%'))
            ->orderBy('name')->paginate(min((int) $request->integer('per_page', 100), 500));
        $rows->through(fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type, 'phone' => $c->phone, 'email' => $c->email, 'price_group_id' => $c->price_group_id]);

        return $this->paginated($rows, $business);
    }

    public function customer(Request $request, int $business, int $customer): JsonResponse
    {
        $this->requireAbility($request, 'sales.customers.view');
        $c = Customer::find($customer);
        if (! $c) {
            return $this->error('not_found', 'Unknown customer.', 404);
        }

        return $this->ok([
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type, 'phone' => $c->phone, 'email' => $c->email,
            'payment_terms' => $c->payment_terms, 'credit_limit' => $c->credit_limit, 'price_group_id' => $c->price_group_id,
            'invoices' => SalesInvoice::where('customer_id', $c->id)->latest('invoice_date')->limit(20)->get(['invoice_number', 'invoice_date', 'due_date', 'total']),
            'tickets' => DB::table('crm_tickets')->where('customer_id', $c->id)->latest('id')->limit(20)->get(['ticket_number', 'subject', 'status', 'priority', 'resolve_by', 'created_at']),
        ]);
    }

    public function tickets(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'crm.tickets.view');
        $rows = DB::table('crm_tickets')->where('business_id', $business)
            ->when($request->filled('filter.status'), fn ($q) => $q->where('status', $request->input('filter.status')), fn ($q) => $q->where('status', 'open'))
            ->orderByDesc('id')->paginate(min((int) $request->integer('per_page', 100), 500), ['id', 'ticket_number', 'subject', 'category', 'priority', 'status', 'customer_id', 'caller_name', 'caller_phone', 'resolve_by', 'resolved_at', 'external_ref', 'created_at']);

        return $this->paginated($rows, $business);
    }

    /** The one Phase 1 write: opens a ticket exactly like the CRM screen does (source `hq`). */
    public function storeTicket(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'crm.tickets.manage');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'integer', 'required_without:caller_name', Rule::exists('customers', 'id')->where('business_id', $business)],
            'caller_name' => ['nullable', 'string', 'max:120', 'required_without:customer_id'],
            'caller_phone' => ['nullable', 'string', 'max:30'],
            'category' => ['required', Rule::in(['complaint', 'question', 'delivery', 'quality', 'other'])],
            'priority' => ['required', Rule::in(array_keys(TicketController::SLA_HOURS))],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $externalRef = ! empty($data['idempotency_key']) ? 'hq:'.$data['idempotency_key'] : null;

        if ($externalRef) {
            $existing = DB::table('crm_tickets')->where('business_id', $business)->where('external_ref', $externalRef)->first();
            if ($existing) {
                return $this->ok(['ticket_number' => $existing->ticket_number, 'id' => $existing->id, 'replayed' => true]);
            }
        }

        // Same numbering and SLA clock as the CRM screen, so an HQ ticket is indistinguishable in the queue.
        $number = app(TicketController::class)->nextNumber($business);
        $id = DB::table('crm_tickets')->insertGetId([
            'business_id' => $business,
            'ticket_number' => $number,
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'caller_name' => $data['caller_name'] ?? null,
            'caller_phone' => $data['caller_phone'] ?? null,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'status' => 'open',
            'external_ref' => $externalRef,
            'resolve_by' => now()->addHours(TicketController::SLA_HOURS[$data['priority']]),
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ok(['ticket_number' => $number, 'id' => $id, 'replayed' => false], status: 201);
    }

    public function pendingApprovals(Request $request, int $business): JsonResponse
    {
        $this->requireAbilityAny($request, ['sales.reports.view', 'inventory.reports.view', 'purchasing.reports.view']);
        $rows = ApprovalRequest::query()->where('status', ApprovalStatus::Pending)->with('requestedBy:id,name')->latest('id')->limit(100)->get()
            ->map(fn ($r) => ['id' => $r->id, 'type' => class_basename($r->approvable_type), 'requested_by' => $r->requestedBy?->name, 'current_stage' => $r->current_stage, 'created_at' => $r->created_at?->toIso8601String()]);

        return $this->ok($rows, ['business_id' => $business]);
    }

    public function reports(Request $request, int $business, ReportRegistry $registry): JsonResponse
    {
        $available = $registry->all()->filter(fn ($d) => $request->user()->tokenCan($d->permission()))->values()
            ->map(fn ($d) => ['key' => $d->key(), 'name' => $d->name(), 'group' => $d->group(), 'permission' => $d->permission(), 'parameters' => $d->parameters()]);

        return $this->ok($available, ['business_id' => $business]);
    }

    public function runReport(Request $request, int $business, string $key, ReportRegistry $registry): JsonResponse
    {
        $definition = $registry->find($key);
        if (! $definition) {
            return $this->error('not_found', "No report {$key}.", 404);
        }
        $this->requireAbility($request, $definition->permission());
        $parameters = new ReportParameters((array) $request->input('parameters', []));
        $limit = min((int) $request->integer('limit', 500), 5000);
        $rows = $definition->rows($parameters)->take($limit)->values()->all();

        return $this->ok(['columns' => $definition->columns($parameters), 'rows' => $rows, 'truncated' => count($rows) >= $limit], ['business_id' => $business, 'report' => $key]);
    }

    // ---- helpers ----------------------------------------------------------------------------

    private function productRow(Product $p, Request $request): array
    {
        $row = ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'type' => $p->type instanceof \BackedEnum ? $p->type->value : (string) $p->type, 'unit' => $p->unitOfMeasure?->abbreviation, 'is_active' => (bool) $p->is_active,
            'brand' => $p->brand ? ['code' => $p->brand->code, 'name' => $p->brand->name] : null, 'low_stock_threshold' => $p->low_stock_threshold,
            // ⭐ Phase 2 / 1B (17 Oct 2026): fixed_mrp or market, so a shop or HQ
            // can tell a product priced from the market each day. Market
            // products are not published to shops until shops.market_pricing.
            'pricing_mode' => $p->pricingMode()->value];
        if ($request->user()->tokenCan('inventory.reports.view')) {
            // Cost is an inventory-report fact; exposed only with that ability (docs/35 P-2).
            $row['avg_unit_cost'] = (float) (StockBatch::where('product_id', $p->id)->where('status', StockBatchStatus::Active)->where('current_quantity', '>', 0)->avg('unit_cost') ?? 0);
        }

        return $row;
    }

    private function requireAbility(Request $request, string $ability): void
    {
        if (! $request->user()->tokenCan($ability)) {
            abort(response()->json(['error' => ['code' => 'forbidden', 'message' => "Token lacks ability {$ability}."]], 403));
        }
    }

    private function requireAbilityAny(Request $request, array $abilities): void
    {
        foreach ($abilities as $a) {
            if ($request->user()->tokenCan($a)) {
                return;
            }
        }
        abort(response()->json(['error' => ['code' => 'forbidden', 'message' => 'Token lacks the required ability.']], 403));
    }

    private function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta + ['as_of' => now()->toIso8601String()]], $status);
    }

    private function paginated($paginator, int $business): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => ['business_id' => $business, 'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'as_of' => now()->toIso8601String()],
            'links' => ['next' => $paginator->nextPageUrl(), 'prev' => $paginator->previousPageUrl()],
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
