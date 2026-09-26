<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CheckCustomerCredit;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\ApplyPromotions;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        // ⚠ `barcode` too: a scanner types the code and presses Enter, and the
        // screen adds the product whose SKU or barcode is exactly that code
        // (Sales UX audit #4). Inventory prints the labels; the till must read them.
        $products = Product::query()->where('is_active', true)->stocked()
            ->orderBy('name')->get(['id', 'name', 'sku', 'barcode', 'category_id']);

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
            // The phone (as typed, and in its matching form) so the picker finds a
            // regular by the number they give at the counter, not only by name.
            'customers' => Customer::query()->active()->orderBy('name')
                ->get(['id', 'name', 'code', 'phone', 'phone_normalized', 'is_system', 'price_group_id']),
            // ⚠ Only accounts the sale's receipt can be booked into (see
            // MoneyAccount::withLedger). ⚠⚠ NONE IS PRESELECTED FROM THIS LIST:
            // until 21 Sep 2026 the screen took the first one, alphabetically,
            // so cash landed in whichever account sorted first. The default is
            // the cashier's own till, below, or nothing.
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
            'myTill' => $this->openTillOf($request),
            'canOverridePrice' => $request->user()->can('sales.price.override'),
            // The sale just finished, once: its slip opens in a new tab and the
            // change is shown. ⚠ A flashed key is not a prop until it is passed
            // here (dead engine #9), so it is passed here.
            'lastSale' => $request->session()->get('counterSale'),
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
     * The till this person has an open shift on, which is where their cash goes.
     *
     * ⚠ Read-only use of the shift table: the shift screen owns it. The newest
     * open shift wins if somebody holds two tills at once, which the shift
     * screen does not stop.
     *
     * @return array{shift_id: int, money_account_id: int, name: string|null}|null
     */
    private function openTillOf(Request $request): ?array
    {
        $shift = DB::table('pos_shifts')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('status', 'open')
            ->where('opened_by', $request->user()->id)
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->first();

        return $shift === null ? null : [
            'shift_id' => (int) $shift->id,
            'money_account_id' => (int) $shift->money_account_id,
            'name' => DB::table('money_accounts')->where('id', $shift->money_account_id)->value('name'),
        ];
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
            // Kept with a held discount, so a resumed cart still says why. The
            // permission is asked again when the sale is finished, not here.
            'lines.*.override_reason' => ['nullable', 'string', 'max:255'],
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

    public function store(Request $request, RecordCounterSale $action, ResolveSellingPrice $resolver, CheckCustomerCredit $credit): RedirectResponse
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
            // ⚠ `decimal`, not only `numeric`: "1e3" is numeric, and Money
            // refuses it with a 500 instead of a sentence.
            'invoice_discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            // What the customer handed over for a cash sale. Only SHOWN (the
            // change to give back), never stored as money.
            'cash_given' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // ⚠ Sent ONLY when the cashier changed the price (see priceLines).
            // A plain number, because it is compared digit for digit with the
            // list price.
            'lines.*.unit_price' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.*.unit_price.regex' => 'Type the price as a plain number, like 850 or 850.50.',
            'cash_given.regex' => 'Type the cash given as a plain number, like 1000 or 1000.50.',
        ]);

        $user = $request->user();
        $customer = Customer::findOrFail($data['customer_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $moneyAccount = isset($data['money_account_id'])
            ? MoneyAccount::findOrFail($data['money_account_id'])
            : null;

        // ⚠⚠ A WALK-IN CANNOT OWE MONEY (Sales UX audit #13). The walk-in row is
        // everybody nobody knows, so a debt on it is a debt nobody can be asked
        // for. The counter refuses it; a named customer can take goods on
        // account under the credit rules below.
        // ⚠ Only the counter: a staff purchase deducted from salary is also
        // booked to the walk-in on account, and settles through payroll.
        if ($moneyAccount === null && $customer->isWalkIn()) {
            throw ValidationException::withMessages([
                'money_account_id' => 'A walk-in customer cannot take goods on account: nobody would know whom to ask for the money. Take the payment, or choose the named customer who will owe it.',
            ]);
        }

        // ⭐⭐ CASH GOES INTO THE CASHIER'S OWN OPEN TILL, OR IT IS REFUSED
        // (Joy, 26 Sep 2026, Q3: "follow recommendation": refuse). Until now the
        // screen only warned. Cash that is not in a counted till can never be
        // reconciled at shift close, and cash put into somebody else's till
        // makes THEIR count wrong. Bank and mobile money are not tills and
        // still work without a shift.
        if ($moneyAccount?->type === MoneyAccountType::Cash) {
            $till = $this->openTillOf($request);

            if ($till === null) {
                throw ValidationException::withMessages([
                    'money_account_id' => 'Open your shift first: cash is counted into a till, and you have none open. Bank or mobile money still works.',
                ]);
            }

            if ($till['money_account_id'] !== $moneyAccount->id) {
                throw ValidationException::withMessages([
                    'money_account_id' => "Cash goes into the till of your own open shift, {$till['name']}, not into {$moneyAccount->name}. Choose your till, or bank or mobile money.",
                ]);
            }
        }

        // Cash tendered means something only for cash going into a cash till.
        $cashGiven = $moneyAccount?->type === MoneyAccountType::Cash && isset($data['cash_given'])
            ? Money::ofTaka((string) $data['cash_given'])
            : null;

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        // One instant for pricing and for the documents, so the price the
        // server checked is the price of the moment the sale is dated.
        $at = Carbon::now();

        // ⚠⚠ C-SLS-E1. Four different refusals can reach this counter: no price
        // in force, not enough stock, a closed month, a missing mapping. Each
        // already carried a message written for the person standing at the till;
        // uncaught, every one of them arrived as a blank 500 instead. This
        // changes nothing about which sales are refused, only whether the
        // refusal can be read. The key is `lines` because that is what the
        // counter screen renders.
        try {
            [$saleLines, $refusals] = $this->priceLines($data['lines'], $products, $customer, $user, $at, $resolver);

            $invoiceDiscount = (string) ($data['invoice_discount'] ?? '0');
            $discountReason = trim((string) ($data['discount_reason'] ?? ''));
            $billDiscounted = Money::ofTaka($invoiceDiscount)->minor > 0;

            // ⚠ The whole-bill discount is the same concession as a line one,
            // so it asks the same permission and a reason. Without this a line
            // discount refused on its row could simply be typed on the bill.
            if ($billDiscounted && ! $user->can('sales.price.override')) {
                $refusals['invoice_discount'] = 'Giving a discount needs the price-override permission, which your role does not have. Sell at the list price, or ask a Manager.';
            } elseif ($billDiscounted && $discountReason === '') {
                $refusals['discount_reason'] = 'Say why the bill is discounted.';
            }

            // Every refusal at once: a cashier fixing one row at a time, with a
            // customer waiting, is the slowest way to learn about three.
            if ($refusals !== []) {
                throw ValidationException::withMessages($refusals);
            }

            // Buy X Get Y: the SERVER decides what the cart earned, and the free
            // line arrives priced at zero - revenue 0, cost still posts.
            $saleLines = array_merge($saleLines, app(ApplyPromotions::class)->earnedLines($saleLines, $at));

            $sale = DB::transaction(function () use ($action, $credit, $customer, $warehouse, $saleLines, $user, $moneyAccount, $at, $invoiceDiscount, $billDiscounted, $discountReason, $cashGiven, $data) {
                // ⚠ One credit decision per customer at a time: two tills selling
                // on account to the same shop at once must not both see the room
                // that only one of them has. (A no-op on SQLite; real on MySQL.)
                if ($moneyAccount === null) {
                    Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
                }

                $sale = $action->handle(
                    customer: $customer,
                    warehouse: $warehouse,
                    lines: $saleLines,
                    createdBy: $user,
                    moneyAccount: $moneyAccount,
                    soldAt: $at,
                    invoiceDiscount: $invoiceDiscount,
                    reference: $data['reference'] ?? null,
                    note: $data['note'] ?? null,
                    discountReason: $billDiscounted ? $discountReason : null,
                    discountGivenBy: $billDiscounted ? $user : null,
                );

                // ⚠⚠ Asked AFTER the invoice is written, in this transaction, so
                // the amount is the invoice's own total (VAT and promotions
                // included) and a refusal takes the whole sale back with it:
                // no stock moved, no number used. The same two rules an order
                // meets at approval (CheckCustomerCredit), with no override
                // here - the cashier takes the payment, or an Owner approves a
                // sales order instead.
                $total = Money::ofTaka((string) $sale->invoice->total);

                if ($moneyAccount === null && ($refusal = $credit->refusalForAmount($customer, $total, alreadyOwed: true)) !== null) {
                    throw ValidationException::withMessages(['money_account_id' => $refusal->getMessage()]);
                }

                // Less cash than the bill is not a cash sale; recording it as
                // one would leave the till short by the difference.
                if ($cashGiven !== null && $cashGiven->minor < $total->minor) {
                    throw ValidationException::withMessages([
                        'cash_given' => "The customer gave {$cashGiven->format()}, less than the total of {$total->format()}. Take the rest, or clear Cash given.",
                    ]);
                }

                return $sale;
            });
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

        $total = Money::ofTaka((string) $sale->invoice->total);

        // ⚠⚠ BACK TO A FRESH COUNTER, not the invoice page (Sales UX audit #4).
        // The invoice page is the internal view: it shows Staff the cost of the
        // goods and the journal, and it left the cashier one click from the
        // slip and two from the next customer. The slip opens in its own tab.
        return redirect()
            ->route('sales.counter.create')
            ->with('success', "Sale complete. Invoice {$sale->invoice->invoice_number}.")
            ->with('counterSale', [
                'invoice_id' => $sale->invoice->id,
                'invoice_number' => $sale->invoice->invoice_number,
                'total' => $total->toTaka(),
                'on_account' => $moneyAccount === null,
                'slip_url' => route('sales.invoices.slip', $sale->invoice->id),
                'cash_given' => $cashGiven?->toTaka(),
                'change' => $cashGiven === null ? null : Money::ofMinor($cashGiven->minor - $total->minor)->toTaka(),
            ]);
    }

    /**
     * Price every line on the server, and say which lines break the price rules.
     *
     * ⚠⚠ THE SERVER PRICES THE SALE, NOT THE SCREEN (ChatGPT V1, 21 Sep 2026).
     * The counter used to send a price with every line and the server took
     * whatever arrived, so anybody who could sell could type any price with no
     * trace. Now every line is priced here by the same resolver the invoice
     * would use. The screen sends `unit_price` only when the cashier changed
     * it, and a sent price equal to the list is simply the list price.
     *
     * A changed price, or a discount on the line, needs `sales.price.override`
     * AND a reason; the invoice line then keeps the list price, the reason and
     * who (RecordSalesInvoice). ⚠ No cap: the owner has not set one.
     *
     * ⚠ An override changes a price that EXISTS. A product with no price for
     * this buyer is still refused (NoSellingPriceException) even for an Owner:
     * Joy keeps groups unpriced precisely so they cannot be sold to until he
     * sets their price.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Product>  $products
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function priceLines(array $lines, Collection $products, Customer $customer, User $user, Carbon $at, ResolveSellingPrice $resolver): array
    {
        $mayOverride = $user->can('sales.price.override');
        $priced = [];
        $refusals = [];

        foreach (array_values($lines) as $index => $line) {
            $product = $products[$line['product_id']];
            $quantity = (string) $line['quantity'];
            $listPrice = (string) $resolver->resolve($product, $customer, $quantity, $at)->unitPrice;

            $typed = isset($line['unit_price']) ? (string) $line['unit_price'] : null;
            $changed = $typed !== null && bccomp($typed, $listPrice, 4) !== 0;
            $discount = isset($line['discount']) ? (string) $line['discount'] : '0';
            $discounted = Money::ofTaka($discount)->minor > 0;
            $reason = trim((string) ($line['override_reason'] ?? ''));

            if ($changed && ! $mayOverride) {
                $refusals["lines.{$index}.unit_price"] = "Changing the price of {$product->name} needs the price-override permission, which your role does not have. The price list says ".Money::ofTaka($listPrice)->format().'.';
            }

            if ($discounted && ! $mayOverride) {
                $refusals["lines.{$index}.discount"] = "A discount on {$product->name} needs the price-override permission, which your role does not have.";
            }

            if ($mayOverride && ($changed || $discounted) && $reason === '') {
                $refusals["lines.{$index}.override_reason"] = $changed
                    ? "Say why {$product->name} is sold at a price other than the list price."
                    : "Say why {$product->name} is discounted.";
            }

            $priced[] = [
                'product' => $product,
                'quantity' => $quantity,
                // ⚠ The list price unless the cashier changed it: a figure the
                // screen sends that matches the list is not an override.
                'unitPrice' => $changed ? $typed : $listPrice,
                'discount' => $discount,
                'listPrice' => $listPrice,
                'overrideReason' => $reason === '' ? null : $reason,
                'overriddenBy' => $user,
            ];
        }

        return [$priced, $refusals];
    }
}
