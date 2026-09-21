<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quotations (Module 4, Slice 2; CRM spec section 13).
 *
 * A promise of a price with an expiry - it reserves no stock and posts
 * nothing. ACCEPTING is the event: it creates the sales order through the
 * one existing action, carrying the quoted prices verbatim, because the
 * negotiated number is the whole point of having quoted. A quote past its
 * validity refuses; a quote to a lead refuses until the lead has become a
 * customer through the gated conversion that already exists.
 */
class QuotationController extends Controller
{
    public function index(): Response
    {
        $businessId = app(BusinessContext::class)->id();

        $quotations = DB::table('quotations')
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->paginate(20);

        $customerNames = Customer::query()->whereIn('id', collect($quotations->items())->pluck('customer_id')->filter())->pluck('name', 'id');
        $leadNames = Lead::query()->whereIn('id', collect($quotations->items())->pluck('lead_id')->filter())->pluck('name', 'id');

        $quotations->through(fn ($quotation) => [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'party' => $quotation->customer_id
                ? ($customerNames[$quotation->customer_id] ?? '?')
                : ($leadNames[$quotation->lead_id] ?? '?'),
            'is_lead' => $quotation->customer_id === null,
            'valid_until' => $quotation->valid_until,
            'status' => $quotation->status,
            'total' => (float) DB::table('quotation_lines')->where('quotation_id', $quotation->id)
                ->selectRaw('SUM(quantity * unit_price) as total')->value('total'),
            'sales_order_id' => $quotation->sales_order_id,
        ]);

        return Inertia::render('sales/quotations/index', [
            'quotations' => $quotations,
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
            'leads' => Lead::query()->whereNull('converted_customer_id')->whereNull('merged_at')
                ->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('is_active', true)->stocked()
                ->orderBy('name')->get(['id', 'name', 'sku']),
            // ⚠ The default warehouse first and flagged: accepting a quotation
            // used to send whichever came first by name (21 Sep 2026).
            'warehouses' => Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
        ]);
    }

    public function store(Request $request, ResolveSellingPrice $resolver): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'required_without:lead_id', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'lead_id' => ['nullable', 'integer', 'required_without:customer_id', Rule::exists('leads', 'id')->where('business_id', $businessId)],
            'opportunity_id' => ['nullable', 'integer', Rule::exists('crm_opportunities', 'id')->where('business_id', $businessId)],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        // ⚠⚠ A blank price on a product nothing prices was a 500 (audit #2,
        // 21 Sep 2026): this controller caught nothing at all. The resolver's
        // refusal names the product and the date; it lands on `lines`, which
        // the form renders, with what to do about it. The transaction rolls the
        // half-written quote back, so no draft is left without its lines.
        try {
            DB::transaction(function () use ($data, $customer, $resolver, $request, $businessId) {
                $quotationId = DB::table('quotations')->insertGetId([
                    'business_id' => $businessId,
                    'quotation_number' => $this->nextNumber($businessId),
                    'customer_id' => $data['customer_id'] ?? null,
                    'lead_id' => $data['lead_id'] ?? null,
                    'opportunity_id' => $data['opportunity_id'] ?? null,
                    'valid_until' => $data['valid_until'],
                    'status' => 'draft',
                    'terms' => $data['terms'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                foreach ($data['lines'] as $line) {
                    $product = Product::findOrFail($line['product_id']);
                    // ⚠ Same habit as the order desk: an override is taken verbatim
                    // and marked; anything else is the resolver's answer, so the
                    // quote never invents a price the list would not have given.
                    $override = $line['unit_price'] ?? null;
                    if ($override !== null) {
                        $unitPrice = $override;
                        $priceNote = 'Quoted price';
                    } else {
                        $resolved = $resolver->resolve($product, $customer, (string) $line['quantity'], Carbon::now());
                        $unitPrice = $resolved->unitPrice;
                        $priceNote = $resolved->explain();
                    }

                    DB::table('quotation_lines')->insert([
                        'business_id' => $businessId,
                        'quotation_id' => $quotationId,
                        'product_id' => $product->id,
                        'quantity' => $line['quantity'],
                        'unit_price' => $unitPrice,
                        'price_note' => $priceNote,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        } catch (NoSellingPriceException $e) {
            throw ValidationException::withMessages([
                'lines' => $e->getMessage().' Type the price you are quoting for it, or set a selling price first.',
            ]);
        }

        return back()->with('success', 'Quotation saved as a draft.');
    }

    public function send(int $quotation): RedirectResponse
    {
        $row = $this->quote($quotation, ['draft']);

        DB::table('quotations')->where('id', $row->id)->update([
            'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Marked sent. Print it or send the PDF to the customer.');
    }

    /**
     * ⚠ THE event. Everything before this was words; accepting creates the
     * sales order through the one existing action, at the quoted prices.
     */
    public function accept(Request $request, int $quotation, CreateSalesOrder $action): RedirectResponse
    {
        $row = $this->quote($quotation, ['draft', 'sent']);

        if (Carbon::parse($row->valid_until)->isPast()) {
            throw ValidationException::withMessages([
                'quotation' => "Quotation {$row->quotation_number} expired on {$row->valid_until}. Quote again at today's prices.",
            ]);
        }

        if ($row->customer_id === null) {
            throw ValidationException::withMessages([
                'quotation' => 'This quote went to a lead. Convert the lead to a customer first (CRM > Leads), then accept.',
            ]);
        }

        $data = $request->validate([
            // ⚠ Scoped: a bare exists: accepted another business's warehouse.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
        ]);

        $lines = DB::table('quotation_lines')->where('quotation_id', $row->id)->get()
            ->map(fn ($line) => [
                'product' => Product::findOrFail($line->product_id),
                'quantity' => $line->quantity,
                // Verbatim: the negotiated number is the point of the quote.
                'unit_price' => $line->unit_price,
            ])->all();

        $order = DB::transaction(function () use ($action, $row, $lines, $data, $request) {
            // ⚠ Accepted is a bare button in a list, so the order's own
            // refusals ("that customer is no longer active", a product turned
            // into a service) are put on `quotation`, the one key the list
            // shows, instead of fields that exist only on the order form.
            try {
                $order = $action->handle(
                    customer: Customer::findOrFail($row->customer_id),
                    lines: $lines,
                    createdBy: $request->user(),
                    warehouse: isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null,
                    note: "From quotation {$row->quotation_number}",
                );
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'quotation' => "Quotation {$row->quotation_number} was not accepted: ".collect($e->errors())->flatten()->first(),
                ]);
            }

            // The price note names the quote, so the order can answer "why
            // this price" long after the negotiation is forgotten.
            DB::table('sales_order_lines')->where('sales_order_id', $order->id)
                ->update(['price_note' => "Quoted on {$row->quotation_number}"]);

            DB::table('quotations')->where('id', $row->id)->update([
                'status' => 'accepted', 'sales_order_id' => $order->id,
                'decided_at' => now(), 'updated_at' => now(),
            ]);

            return $order;
        });

        return redirect()->route('sales.orders.show', $order->id)
            ->with('success', "Quotation accepted. Sales order {$order->order_number} created at the quoted prices.");
    }

    public function reject(int $quotation): RedirectResponse
    {
        $row = $this->quote($quotation, ['draft', 'sent']);

        DB::table('quotations')->where('id', $row->id)->update([
            'status' => 'rejected', 'decided_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Quotation marked rejected.');
    }

    public function print(int $quotation): Response
    {
        $row = $this->quote($quotation);

        $lines = DB::table('quotation_lines')->where('quotation_id', $row->id)->get()
            ->map(fn ($line) => [
                'id' => $line->id,
                'product' => Product::withoutGlobalScopes()->find($line->product_id)?->only(['id', 'name', 'sku']),
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => round((float) $line->quantity * (float) $line->unit_price, 2),
            ]);

        return Inertia::render('sales/quotations/print', [
            'quotation' => [
                'id' => $row->id,
                'quotation_number' => $row->quotation_number,
                'party' => $row->customer_id
                    ? Customer::find($row->customer_id)?->name
                    : Lead::find($row->lead_id)?->name,
                'valid_until' => $row->valid_until,
                'status' => $row->status,
                'terms' => $row->terms,
                'note' => $row->note,
                'created_at' => $row->created_at,
                'lines' => $lines,
                'total' => round($lines->sum('line_total'), 2),
            ],
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    private function quote(int $id, ?array $statuses = null): object
    {
        $row = DB::table('quotations')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $id)
            ->first();
        abort_if($row === null, 404);

        if ($statuses !== null && ! in_array($row->status, $statuses, true)) {
            throw ValidationException::withMessages([
                'quotation' => "Quotation {$row->quotation_number} is {$row->status} and cannot change now.",
            ]);
        }

        return $row;
    }

    private function nextNumber(int $businessId): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'QT-'.FiscalYear::containing(Carbon::now(), $business)->label().'-';

        $latest = DB::table('quotations')
            ->where('quotation_number', 'like', $prefix.'%')
            ->orderByDesc('quotation_number')
            ->value('quotation_number');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
