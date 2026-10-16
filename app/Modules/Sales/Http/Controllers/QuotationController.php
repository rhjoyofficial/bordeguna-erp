<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
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
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Support\DiscountCap;
use App\Modules\Sales\Support\ResolvedPrice;
use App\Support\DocumentNumber;
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
        // Rule #6: which of these quotes carry a line at the retail fallback.
        $retailFallback = DB::table('quotation_lines')
            ->whereIn('quotation_id', collect($quotations->items())->pluck('id'))
            ->where('price_source', 'retail_fallback')
            ->distinct()->pluck('quotation_id')->flip();

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
            'retail_fallback' => $retailFallback->has($quotation->id),
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

        $this->assertQuotedPricesAllowed($request, $data['lines'], $customer, $resolver, $businessId);

        // ⚠⚠ A blank price on a product nothing prices was a 500 (audit #2,
        // 21 Sep 2026): this controller caught nothing at all. The resolver's
        // refusal names the product and the date; it lands on `lines`, which
        // the form renders, with what to do about it. The transaction rolls the
        // half-written quote back, so no draft is left without its lines.
        try {
            DB::transaction(function () use ($data, $customer, $resolver, $request, $businessId) {
                $quotationId = DocumentNumber::claim('quotation_number', fn () => $this->nextNumber($businessId), fn (string $number) => DB::table('quotations')->insertGetId([
                    'business_id' => $businessId,
                    'quotation_number' => $number,
                    'customer_id' => $data['customer_id'] ?? null,
                    'lead_id' => $data['lead_id'] ?? null,
                    'opportunity_id' => $data['opportunity_id'] ?? null,
                    'valid_until' => $data['valid_until'],
                    'status' => 'draft',
                    'terms' => $data['terms'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]));

                foreach ($data['lines'] as $line) {
                    $product = Product::findOrFail($line['product_id']);
                    // ⚠ Same habit as the order desk: an override is taken verbatim
                    // and marked; anything else is the resolver's answer, so the
                    // quote never invents a price the list would not have given.
                    $override = $line['unit_price'] ?? null;
                    if ($override !== null) {
                        $unitPrice = $override;
                        $priceSource = 'quoted';
                        $priceNote = 'Quoted price';

                        // Rule #6: name the yardstick when it was the retail
                        // fallback, so "quoted below what?" has an answer.
                        try {
                            [$listed, $source] = $this->listPrice($resolver, $product, $customer, (string) $line['quantity'], $businessId);

                            if ($source === 'retail_fallback') {
                                $priceNote = 'Quoted price; the retail list ('.Money::ofTaka((string) $listed->unitPrice)->format().') was the yardstick: '.$this->fallbackReason($customer);
                            }
                        } catch (NoSellingPriceException) {
                            // No list at all: the typed price is the only one.
                        }
                    } else {
                        [$resolved, $priceSource] = $this->listPrice($resolver, $product, $customer, (string) $line['quantity'], $businessId);
                        $unitPrice = $resolved->unitPrice;
                        $priceNote = $priceSource === 'retail_fallback'
                            ? 'Retail price used: '.$this->fallbackReason($customer)
                            : $resolved->explain();
                    }

                    DB::table('quotation_lines')->insert([
                        'business_id' => $businessId,
                        'quotation_id' => $quotationId,
                        'product_id' => $product->id,
                        'quantity' => $line['quantity'],
                        'unit_price' => $unitPrice,
                        'price_note' => mb_substr($priceNote, 0, 255),
                        'price_source' => $priceSource,
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

    /**
     * ⭐ 13 Oct 2026: the counter's price rules on a quotation too. A quoted
     * price becomes the order price when the customer accepts, so a quote was
     * a way round the price-override permission and the per-bill discount cap
     * (Owner none, Manager 3%, others 1% or Tk 300, the lower). A product with
     * no list price for this buyer can only be quoted by someone who may set a
     * price.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertQuotedPricesAllowed(Request $request, array $lines, ?Customer $customer, ResolveSellingPrice $resolver, int $businessId): void
    {
        $user = $request->user();
        $mayOverride = $user->can('sales.price.override');
        $refusals = [];
        $capLines = [];

        foreach (array_values($lines) as $index => $line) {
            $typed = isset($line['unit_price']) ? (string) $line['unit_price'] : null;

            if ($typed === null) {
                continue;
            }

            $product = Product::find($line['product_id']);
            $quantity = (string) $line['quantity'];

            // Rule #6: the yardstick is the same list the quote would use, the
            // retail one when the buyer's group has none, so the override and
            // the cap are measured as before, against that price.
            try {
                $listPrice = (string) $this->listPrice($resolver, $product, $customer, $quantity, $businessId)[0]->unitPrice;
            } catch (NoSellingPriceException) {
                $listPrice = null;
            }

            if ($listPrice !== null && bccomp($typed, $listPrice, 4) === 0) {
                continue;
            }

            if (! $mayOverride) {
                $refusals["lines.{$index}.unit_price"] = $listPrice === null
                    ? "{$product->name} has no list price for this buyer, and quoting one needs the price-override permission. Ask a Manager."
                    : "Quoting {$product->name} at a price other than the list needs the price-override permission, which your role does not have. The price list says ".Money::ofTaka($listPrice)->format().'.';

                continue;
            }

            if ($listPrice !== null) {
                $capLines[] = ['quantity' => $quantity, 'unitPrice' => $typed, 'discount' => '0', 'listPrice' => $listPrice];
            }
        }

        if ($refusals === []) {
            $overCap = DiscountCap::refusal($user, $businessId, $capLines, '0');

            if ($overCap !== null) {
                $refusals['lines'] = $overCap;
            }
        }

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }
    }

    /**
     * ⚠⚠ Rule #6 (Joy, 14 Oct 2026, after the 13 Oct audit, checked with
     * ChatGPT): the price a quote reads, and where it came from.
     *
     * The buyer's own list first (their price, their group's, or the MRP when
     * their group falls back to it): source `list`. When their group has NO
     * list (a dealer or a distributor nobody priced yet) the quote used to be
     * refused "no price for this group"; now it falls back to the RETAIL list
     * (the RETAIL group read as a shop reads it: its rows, the standard tier,
     * then the MRP) with source `retail_fallback`. A lead has no group yet, so
     * it is always quoted at the retail list, marked the same way: that is the
     * 13 Oct finding, a lead quoted at retail who then became a dealer.
     *
     * ⚠ NEVER SILENT: the source is stored on the line and shown on the quote
     * list and the print. Only the QUOTE falls back; the counter, the order
     * and the invoice still refuse an unpriced group (ResolveSellingPrice).
     *
     * @return array{0: ResolvedPrice, 1: string}
     *
     * @throws NoSellingPriceException when even the retail list has no price
     */
    private function listPrice(ResolveSellingPrice $resolver, Product $product, ?Customer $customer, string $quantity, int $businessId): array
    {
        if ($customer !== null) {
            $own = $resolver->find($product, $customer, $quantity, Carbon::now());

            if ($own !== null) {
                return [$own, 'list'];
            }
        }

        $retailGroupId = PriceGroup::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)->where('code', PriceGroup::DEFAULT_CODE)->value('id');

        return [$resolver->resolve($product, null, $quantity, Carbon::now(), $retailGroupId === null ? null : (int) $retailGroupId), 'retail_fallback'];
    }

    private function fallbackReason(?Customer $customer): string
    {
        return $customer === null
            ? 'a lead has no price group yet.'
            : "no price list for this buyer's group.";
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

        // ⚠ SAL-005 (audit 14 Oct 2026): "valid until 3 Sep" is valid ALL of
        // 3 Sep. The date was compared as midnight, so a quote valid until
        // today could never be accepted today. Compared as Dhaka calendar days
        // (the application timezone) instead.
        if (Carbon::parse($row->valid_until, 'Asia/Dhaka')->startOfDay()->lt(Carbon::today('Asia/Dhaka'))) {
            throw ValidationException::withMessages([
                'quotation' => "Quotation {$row->quotation_number} expired on {$row->valid_until}. Quote again at today's prices.",
            ]);
        }

        // ⚠ Bug hunt 16 Oct 2026: converting the lead never moved its quotes
        // to the new customer, so a quote to a lead stayed refused for ever.
        // The lead's customer is taken now, and kept on the quote.
        if ($row->customer_id === null && $row->lead_id !== null) {
            $convertedTo = Lead::query()->whereKey($row->lead_id)->value('converted_customer_id');

            if ($convertedTo !== null) {
                DB::table('quotations')->where('id', $row->id)->update(['customer_id' => $convertedTo, 'updated_at' => now()]);
                $row->customer_id = $convertedTo;
            }
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
            // ⚠⚠ SAL-003 (audit 14 Oct 2026): the status was checked above,
            // outside this transaction and unlocked, so a double click (or two
            // people) both read "sent" and both created a sales order. The
            // quote is re-read under a row lock here and checked again; the
            // second request waits for the first and is then told it is
            // accepted.
            $this->quote($row->id, ['draft', 'sent'], lock: true);

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

            // Rule #6: a line quoted at the retail fallback says so on the
            // order too, so a dealer's order cannot pass as their own price.
            $fallbackProducts = DB::table('quotation_lines')->where('quotation_id', $row->id)
                ->where('price_source', 'retail_fallback')->pluck('product_id');

            if ($fallbackProducts->isNotEmpty()) {
                DB::table('sales_order_lines')->where('sales_order_id', $order->id)
                    ->whereIn('product_id', $fallbackProducts)
                    ->update(['price_note' => "Quoted on {$row->quotation_number} at the retail price (no list for the buyer's group)"]);
            }

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
                'price_source' => $line->price_source,
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
                'retail_fallback' => $lines->contains('price_source', 'retail_fallback'),
            ],
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    private function quote(int $id, ?array $statuses = null, bool $lock = false): object
    {
        $row = DB::table('quotations')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
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

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(DB::table('quotations'), 'quotation_number', $prefix);
    }
}
