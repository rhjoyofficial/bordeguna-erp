<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\ApplyPromotions;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The counter.
 *
 * ⚠ A screen over the sale, not a parallel system. It posts to the same
 * RecordCounterSale action that composes the same three documents a distributor
 * sale uses. Nothing here knows a different way to sell.
 */
class CounterSaleController extends Controller
{
    public function create(Request $request, ResolveSellingPrice $resolver): Response
    {
        $products = Product::query()->where('is_active', true)->stocked()
            ->orderBy('name')->get(['id', 'name', 'sku', 'category_id']);

        return Inertia::render('sales/counter/create', [
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'code', 'is_default']),
            'products' => $products,
            // Only categories the grid actually shows - a filter chip that can
            // never match anything is furniture.
            'categories' => Category::query()
                ->whereIn('id', $products->pluck('category_id')->filter()->unique())
                ->orderBy('name')->get(['id', 'name']),
            // What the walk-in pays today, on every card, answered by the SAME
            // resolver the sale uses (one query for the whole grid). Display
            // only: the cart re-resolves per customer and quantity, so a card
            // is a shelf label, never the till.
            'cardPrices' => collect($resolver->findMany($products, Customer::walkIn()))
                ->map(fn ($resolved) => (float) $resolved->unitPrice),
            'customers' => Customer::query()->active()->orderBy('name')
                ->get(['id', 'name', 'code', 'is_system', 'price_group_id']),
            // ⚠ Only accounts the sale's receipt can be booked into (see
            // MoneyAccount::withLedger). The screen preselects the first one.
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
            'walkInId' => Customer::walkIn()?->id,
            // Held carts: bookmarks, not transactions. Newest first, with just
            // enough to recognise whose cart it was.
            'heldCarts' => DB::table('pos_held_carts')->where('business_id', app(BusinessContext::class)->id())->orderByDesc('id')->get()
                ->map(fn ($cart) => [
                    'id' => $cart->id,
                    'label' => $cart->label,
                    'customer_id' => $cart->customer_id,
                    'item_count' => count(json_decode($cart->payload, true)['lines'] ?? []),
                    'held_by' => DB::table('users')->where('id', $cart->held_by)->value('name'),
                    'held_at' => $cart->created_at,
                ]),
            // ⚠ Resuming does NOT delete the hold - a browser closed mid-resume
            // loses nothing. The hold is consumed only when its sale completes,
            // or discarded on purpose.
            'resumeCart' => $request->query('resume')
                ? tap(DB::table('pos_held_carts')->where('business_id', app(BusinessContext::class)->id())->find((int) $request->query('resume')), function (&$cart) {
                    if ($cart !== null) {
                        $payload = json_decode($cart->payload, true);
                        $cart = [
                            'id' => $cart->id,
                            'label' => $cart->label,
                            'customer_id' => $cart->customer_id,
                            'warehouse_id' => $cart->warehouse_id,
                            'lines' => $payload['lines'] ?? [],
                        ];
                    }
                })
                : null,
        ]);
    }

    /**
     * Park the cart so the queue keeps moving. Saves the counter state and
     * NOTHING else: no stock reserved, no document posted, no price frozen -
     * on resume the resolver prices the lines again, as it always does.
     */
    public function hold(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::table('pos_held_carts')->insert([
            'business_id' => app(BusinessContext::class)->id(),
            'label' => $data['label'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'],
            'payload' => json_encode(['lines' => $data['lines']]),
            'held_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('sales.counter.create')->with('success', 'Cart held. Resume it from the held list when the customer returns.');
    }

    public function discard(int $cart): RedirectResponse
    {
        DB::table('pos_held_carts')->where('business_id', app(BusinessContext::class)->id())->where('id', $cart)->delete();

        return back()->with('success', 'Held cart discarded.');
    }

    /**
     * ⚠ A price check before anything is committed. The counter needs to show a
     * price the moment a product is picked, and it must be the SAME resolver the
     * sale will use - a screen that computes its own price is a second pricing
     * engine that disagrees with the invoice.
     */
    public function price(Request $request, ResolveSellingPrice $resolver): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        $resolved = $resolver->find($product, $customer, (string) $data['quantity']);

        return response()->json([
            'unit_price' => $resolved?->unitPrice,
            'explanation' => $resolved?->explain(),
        ]);
    }

    public function store(Request $request, RecordCounterSale $action): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            // ⚠ Nullable on purpose: no money account means the customer took
            // the goods on account, the invoice stands open, and the same
            // documents describe both. That is the seam where retail meets
            // credit, and it is a deliberate feature of the counter screen.
            'money_account_id' => ['nullable', 'integer', 'exists:money_accounts,id'],
            'held_cart_id' => ['nullable', 'integer', 'exists:pos_held_carts,id'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        // ⚠⚠ C-SLS-E1. Four different refusals can reach this counter: no price
        // in force, not enough stock, a closed month, a missing mapping. Each
        // already carried a message written for the person standing at the till;
        // uncaught, every one of them arrived as a blank 500 instead. This
        // changes nothing about which sales are refused, only whether the
        // refusal can be read. The key is `lines` because that is what the
        // counter screen renders.
        $saleLines = collect($data['lines'])->map(fn (array $line) => [
            'product' => $products[$line['product_id']],
            'quantity' => (string) $line['quantity'],
            'unitPrice' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
            'discount' => isset($line['discount']) ? (string) $line['discount'] : '0',
        ])->all();

        // Buy X Get Y: the SERVER decides what the cart earned, and the free
        // line arrives priced at zero - revenue 0, cost still posts.
        $saleLines = array_merge($saleLines, app(ApplyPromotions::class)->earnedLines($saleLines));

        $moneyAccount = isset($data['money_account_id'])
            ? MoneyAccount::findOrFail($data['money_account_id'])
            : null;

        try {
            $sale = $action->handle(
                customer: Customer::findOrFail($data['customer_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: $saleLines,
                createdBy: $request->user(),
                moneyAccount: $moneyAccount,
                invoiceDiscount: (string) ($data['invoice_discount'] ?? '0'),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (MappingNotFound|JournalException $e) {
            // ⚠ "Paid into" an account with no ledger head is the usual cause,
            // and the picker offered one - first, alphabetically - until 21 Sep
            // 2026. Said on the field the cashier can change; any other missing
            // mapping keeps its own sentence on `lines`, as before.
            if ($moneyAccount !== null && $moneyAccount->account_id === null) {
                throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($moneyAccount, $e)]);
            }

            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (InsufficientStockException|PeriodClosedException|DomainException $e) {
            // DomainException is NoSellingPriceException's parent, and a missing
            // unit conversion's.
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        // The bookmark became a real invoice - it must not linger to be
        // sold twice.
        if (isset($data['held_cart_id'])) {
            DB::table('pos_held_carts')->where('business_id', app(BusinessContext::class)->id())->where('id', $data['held_cart_id'])->delete();
        }

        return redirect()
            ->route('sales.invoices.show', $sale->invoice->id)
            ->with('success', "Sale complete. Invoice {$sale->invoice->invoice_number}.");
    }
}
