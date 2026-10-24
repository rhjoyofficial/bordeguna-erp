<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Support\LastReceivedCost;
use App\Modules\Purchasing\Support\LikelyDuplicateBill;
use App\Modules\Purchasing\Support\PaymentSplit;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchasingEmployees;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use App\Modules\Purchasing\Support\RefusalMessage;
use App\Support\Documents\RelatedDocuments;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ Purchases > Buy and receive (supplier finance wave C, ruling P4, 18 Oct
 * 2026): one screen for goods bought and brought in together. The screen
 * collects; RecordQuickPurchase calls the ordinary actions; the result page
 * links every document they made.
 *
 * ⚠ The route needs `purchasing.quick.purchase`; the action then needs each
 * step's own permission, so this screen is never a way round one of them.
 */
class QuickPurchaseController extends Controller
{
    public function create(Request $request): Response
    {
        $user = $request->user();
        $lastCosts = LastReceivedCost::forProducts();
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_default']);

        return Inertia::render('purchasing/buy-and-receive/create', [
            // ⭐ Ruling P6: each with its terms, so the screen shows the due
            // date the terms give and asks why when a different one is typed.
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'payment_terms_type', 'payment_terms_days'])
                ->map(fn (Supplier $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'terms' => PaymentTerms::forSupplier($supplier)?->toArray(),
                ]),
            'warehouses' => $warehouses->map->only(['id', 'name'])->values(),
            'defaultWarehouseId' => $warehouses->firstWhere('is_default', true)?->id ?? $warehouses->first()?->id,
            // As on the order form: the unit the quantity is typed in and the
            // last real cost; plus whether the batch and expiry are required.
            'products' => Product::where('is_active', true)
                ->with(['unitOfMeasure:id,abbreviation', 'purchaseUnit:id,abbreviation'])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_of_measure_id', 'purchase_unit_id', 'tracks_expiry', 'weight_grams'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->purchaseUnit?->abbreviation ?? $product->unitOfMeasure?->abbreviation,
                    'tracks_expiry' => (bool) $product->tracks_expiry,
                    // ⭐ Initial plan B1 (R51): for the total weight row.
                    'weight_grams' => $product->weight_grams,
                    'last_cost' => $lastCosts[$product->id] ?? null,
                ]),
            // ⭐ F20: each with what is in it now, as on the payment form.
            // ⭐ Initial plan B1 (R40): HR employees for "Purchasing employee".
            'employees' => PurchasingEmployees::options(),
            'moneyAccounts' => tap(MoneyAccount::query()->handled()->withLedger()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'business_id', 'name', 'type', 'account_id']), function ($accounts) {
                    $balances = MoneyAccountBalances::for($accounts);
                    $accounts->each(fn (MoneyAccount $account) => $account->setAttribute('balance', $balances[$account->id] ?? null)
                        ->makeHidden(['business_id', 'account_id']));
                }),
            'costTypes' => collect(self::costTypes())->map(fn (LandedCostType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'into_stock' => $type->defaultTreatment() === LandedCostTreatment::Capitalize,
            ])->values(),
            'today' => now()->toDateString(),
            // What this person may do in each step, so the screen offers only
            // that; the action checks every one again.
            'rights' => [
                'approve_order' => $user->can('purchasing.po.approve'),
                'extra_costs' => $user->can('purchasing.landed_cost.manage'),
                'post_extra_costs' => $user->can('purchasing.landed_cost.post'),
                'pay' => $user->can('purchasing.payment.record'),
                'advance' => $user->can('purchasing.advance.record'),
            ],
            'missing' => collect([
                'purchasing.po.create' => 'raise a purchase order',
                'purchasing.receipt.record' => 'receive goods',
                'purchasing.bill.record' => 'record a supplier bill',
            ])->reject(fn (string $label, string $permission) => $user->can($permission))->values(),
        ]);
    }

    public function store(Request $request, RecordQuickPurchase $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('business_id', $businessId);

        $data = $request->validate([
            'request_key' => ['required', 'string', 'max:64'],
            'supplier_id' => ['required', 'integer', $scoped('suppliers')],
            'warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'purchased_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'backdate_reason' => ['nullable', 'string', 'max:500'],
            'bill_number' => ['required', 'string', 'max:100'],
            'challan_number' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchased_on'],
            // ⭐ Ruling P6: why a typed due date differs from the terms.
            'due_date_reason' => ['nullable', 'string', 'max:500'],
            // ⭐ Ruling P5: why a bill that looks like an earlier one is recorded anyway.
            'duplicate_reason' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            // ⭐ Initial plan B1 (R40): the HR employee who handled the purchase.
            'purchasing_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', $scoped('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.entry_mode' => ['nullable', 'in:rate,total'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.manufactured_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'costs' => ['nullable', 'array'],
            'costs.*.cost_type' => ['required', Rule::in(array_map(fn (LandedCostType $type) => $type->value, self::costTypes()))],
            'costs.*.amount' => ['required', 'numeric', 'gt:0'],
            'costs.*.payee' => ['required', 'in:supplier,money'],
            'costs.*.money_account_id' => ['nullable', 'required_if:costs.*.payee,money', 'integer', $scoped('money_accounts')],
            'costs.*.description' => ['nullable', 'string', 'max:255'],
            // ⭐ Initial plan B1 (R49): a one-off payee's name (truck owner, restaurant).
            'costs.*.payee_name' => ['nullable', 'string', 'max:120'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reference' => ['nullable', 'string', 'max:120'],
            'pay_now' => ['nullable', 'boolean'],
            'payment_money_account_id' => ['nullable', 'required_if_accepted:pay_now', 'integer', $scoped('money_accounts')],
            'payment_amount' => ['nullable', 'required_if_accepted:pay_now', 'numeric', 'gt:0'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'excess_to_advance' => ['nullable', 'boolean'],
            // ⭐ Initial plan B2 (R57): other accounts; "Paid from" pays the rest.
            ...PaymentSplit::rules((int) $businessId),
            // ⭐ Initial plan B1 (R56): earlier advances taken off the bill.
            'advances' => ['nullable', 'array'],
            'advances.*.key' => ['required', 'string', 'distinct', 'regex:/^(on_account|advance:\d+)$/'],
            'advances.*.amount' => ['required', 'numeric', 'gte:0'],
        ], [
            'purchased_on.before_or_equal' => 'The purchase date cannot be in the future.',
            'due_date.after_or_equal' => 'The due date cannot be before the purchase date.',
            'costs.*.money_account_id.required_if' => 'Pick the account it was paid from.',
        ], [
            'purchased_on' => 'purchase date',
            'bill_number' => "supplier's invoice number",
            'lines.*.product_id' => 'product',
            'lines.*.quantity' => 'quantity',
            'lines.*.unit_cost' => 'unit price',
            'lines.*.line_total' => 'line total',
            'lines.*.expiry_date' => 'expiry date',
            'lines.*.manufactured_date' => 'manufacture date',
            'costs.*.amount' => 'amount',
            'payment_money_account_id' => 'paid from',
            'payment_amount' => 'amount paid',
        ] + PaymentSplit::attributes());

        $lines = [];
        $problems = [];

        foreach ($data['lines'] as $index => $line) {
            $product = Product::findOrFail($line['product_id']);

            // ⭐ PR2 (18 Oct 2026): blank label data takes its defaults first
            // (automatic batch number, made on the purchase date, expiry from
            // the shelf life), as the receipt action does.
            if ($product->type->isStocked()) {
                $line = array_merge($line, BatchNumbers::label($product, $line['batch_number'] ?? null, $line['manufactured_date'] ?? null, $line['expiry_date'] ?? null, $data['purchased_on'] ?? null));
            }

            // The receipt's own label checks, under each line's boxes. The
            // receipt action checks them again.
            foreach (ReceivedBatchDates::problems($line['manufactured_date'] ?? null, $line['expiry_date'] ?? null) as $field => $problem) {
                $problems["lines.{$index}.{$field}"] = $problem;
            }

            foreach (ReceivedBatchDates::required((bool) $product->tracks_expiry, $line['batch_number'] ?? null, $line['expiry_date'] ?? null) as $field => $problem) {
                $problems["lines.{$index}.{$field}"] ??= $problem;
            }

            $lines[] = [
                'product' => $product,
                'quantity' => (string) $line['quantity'],
                'unit_cost' => $line['unit_cost'] ?? null,
                'line_total' => $line['line_total'] ?? null,
                'entry_mode' => $line['entry_mode'] ?? null,
                'batch_number' => $line['batch_number'] ?? null,
                'manufactured_date' => $line['manufactured_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
            ];
        }

        if ($data['purchased_on'] < now()->toDateString() && trim((string) ($data['backdate_reason'] ?? '')) === '') {
            $problems['backdate_reason'] = 'This purchase is dated earlier than today: say why it is being entered now.';
        }

        $discount = (float) ($data['discount_amount'] ?? 0) > 0 ? [
            'value' => (string) $data['discount_amount'],
            'reference' => trim((string) ($data['discount_reference'] ?? '')),
        ] : null;

        if ($discount !== null && $discount['reference'] === '') {
            $problems['discount_reference'] = "Give the supplier's document number the discount is printed on.";
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $payment = (bool) ($data['pay_now'] ?? false) ? [
            'money_account' => MoneyAccount::findOrFail($data['payment_money_account_id']),
            'amount' => (string) $data['payment_amount'],
            'reference' => $data['payment_reference'] ?? null,
            'excess_to_advance' => (bool) ($data['excess_to_advance'] ?? false),
            'split_lines' => PaymentSplit::fromRequest($data),
        ] : null;

        try {
            $quick = $action->handle(
                requestKey: $data['request_key'],
                supplier: Supplier::findOrFail($data['supplier_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                purchasedOn: $data['purchased_on'],
                lines: $lines,
                billNumber: trim($data['bill_number']),
                by: $request->user(),
                challanNumber: $data['challan_number'] ?? null,
                dueDate: $data['due_date'] ?? null,
                note: $data['note'] ?? null,
                backdateReason: $data['backdate_reason'] ?? null,
                extraCosts: array_values($data['costs'] ?? []),
                payment: $payment,
                tradeDiscount: $discount,
                dueDateReason: $data['due_date_reason'] ?? null,
                duplicateReason: $data['duplicate_reason'] ?? null,
                orderDetails: empty($data['purchasing_employee_id']) ? null : ['purchasing_employee_id' => (int) $data['purchasing_employee_id']],
                advances: array_values($data['advances'] ?? []),
            );
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['quick' => $e->getMessage()]);
        } catch (LikelyDuplicateBill $e) {
            // ⭐ Ruling P5: nothing was saved; the screen asks why and sends it again.
            throw ValidationException::withMessages(['duplicate' => $e->getMessage()]);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            // ⚠ Nothing was saved: the whole purchase rolled back.
            throw ValidationException::withMessages(['quick' => RefusalMessage::of($e).' Nothing was saved.']);
        }

        $message = match (true) {
            ! $quick->wasRecentlyCreated => 'This purchase was already saved; here it is.',
            $quick->isCompleted() => 'Purchase recorded: order, receipt, bill'.($quick->supplier_payment_id ? ' and payment' : '').' are saved.',
            default => 'Purchase saved, and part of it waits for approval.',
        };

        return redirect()->route('purchasing.buy-and-receive.show', $quick)->with('success', $message);
    }

    public function show(QuickPurchase $quickPurchase): Response
    {
        $quickPurchase->load([
            'supplier:id,name',
            'purchaseOrder:id,po_number,status',
            'goodsReceipt:id,purchase_order_id,received_at,supplier_challan_number',
            'landedCost:id,number,status,goods_receipt_id',
            'bill',
            'payment',
            'createdBy:id,name',
            'purchaseApproval',
        ]);

        $advance = $quickPurchase->supplier_payment_id === null ? null
            : SupplierAdvance::query()->where('supplier_payment_id', $quickPurchase->supplier_payment_id)->first(['id', 'number', 'amount']);

        $q = $quickPurchase;

        return Inertia::render('purchasing/buy-and-receive/show', [
            // PR5 (18 Oct 2026): what this came from, what came of it, and the journals.
            'related' => RelatedDocuments::for($quickPurchase, request()->user()),
            'purchase' => [
                'id' => $q->id,
                'status' => $q->status,
                'held_reason' => $q->held_reason,
                'purchased_on' => $q->purchased_on?->toDateString(),
                'supplier' => $q->supplier?->name,
                'supplier_id' => $q->supplier_id,
                'created_by' => $q->createdBy?->name,
                'goods' => Money::ofMinor($q->goods_paisa)->toTaka(),
                'extra_costs' => Money::ofMinor($q->extra_costs_paisa)->toTaka(),
                'paid' => Money::ofMinor($q->paid_paisa)->toTaka(),
            ],
            // Each document as its own screen knows it, with its own number.
            'documents' => array_values(array_filter([
                $q->purchaseOrder ? [
                    'kind' => 'order', 'label' => 'Purchase order', 'number' => $q->purchaseOrder->po_number,
                    'url' => route('purchasing.purchase-orders.show', $q->purchaseOrder),
                ] : null,
                $q->goodsReceipt ? [
                    'kind' => 'receipt', 'label' => 'Goods receipt', 'number' => "GRN #{$q->goodsReceipt->id}",
                    'url' => route('purchasing.purchase-orders.show', $q->goodsReceipt->purchase_order_id),
                ] : null,
                $q->landedCost ? [
                    'kind' => 'landed_cost', 'label' => 'Extra costs (landed cost)', 'number' => $q->landedCost->number,
                    'state' => $q->landedCost->status->label(),
                    'url' => route('purchasing.landed-costs.index', ['receipt' => $q->landedCost->goods_receipt_id]),
                ] : null,
                $q->bill ? [
                    'kind' => 'bill', 'label' => 'Supplier bill', 'number' => $q->bill->bill_number,
                    'state' => 'Outstanding '.$q->bill->outstanding()->format(),
                    'url' => route('purchasing.bills.show', $q->bill),
                ] : null,
                $q->payment ? [
                    'kind' => 'payment', 'label' => 'Payment', 'number' => "Payment #{$q->payment->id}",
                    'state' => Money::ofTaka((string) $q->payment->amount)->format(),
                    'url' => route('purchasing.payments.show', $q->payment),
                ] : null,
                // ⭐ Ruling P7: the PA- row the bill or payment waits as, with its
                // state now (approved, it links the document it posted).
                $q->purchaseApproval ? [
                    'kind' => 'approval', 'label' => 'Waiting approval', 'number' => $q->purchaseApproval->number(),
                    'state' => match ($q->purchaseApproval->status) {
                        'approved' => 'Approved and posted',
                        'rejected' => 'Rejected: nothing was posted',
                        default => $q->purchaseApproval->kindLabel().' of '.$q->purchaseApproval->money()->format().' waiting',
                    },
                    'url' => route('purchasing.approvals.show', $q->purchaseApproval),
                ] : null,
                $advance ? [
                    'kind' => 'advance', 'label' => 'Advance (paid beyond the bills)', 'number' => $advance->number,
                    'state' => Money::ofTaka((string) $advance->amount)->format(),
                    'url' => route('purchasing.advances.show', $advance),
                ] : null,
            ])),
        ]);
    }

    /**
     * The extra-cost types this screen offers: every type with a head of its
     * own. "Other" needs its expense head named, which the Landed costs
     * screen asks for, so it is entered there.
     *
     * @return list<LandedCostType>
     */
    private static function costTypes(): array
    {
        return array_values(array_filter(LandedCostType::cases(), fn (LandedCostType $type) => $type !== LandedCostType::Other));
    }
}
