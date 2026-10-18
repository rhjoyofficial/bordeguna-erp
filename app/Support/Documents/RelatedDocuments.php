<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\SetOffLine;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierCreditNoteAllocation;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ⭐ WHAT A DOCUMENT CAME FROM AND WHAT CAME OF IT (PR5, 18 Oct 2026).
 *
 * Tally lets you walk from a purchase order to its receipt note, the
 * purchase, the payment, and the journal under each. Here every document
 * page gets the same panel: "came from" (the documents this one was made
 * against), "led to" (the documents made against this one) and the journal
 * entries behind all of them, each opening its own page.
 *
 * ⚠ Both directions are read from the SAME links the engines write
 * (purchase_order_id, allocations, landed_cost_id, supplier_payment_id...),
 * so the PO's panel and the bill's panel can never disagree about whether
 * they are connected.
 *
 * ⚠ Journals: the document's own, plus those of the documents it led to on
 * a PO or an order (which posts nothing itself), plus any reversal of them.
 */
final class RelatedDocuments
{
    /** @var Collection<string, Model> */
    private Collection $from;

    /** @var Collection<string, Model> */
    private Collection $to;

    private function __construct(private readonly Model $document)
    {
        $this->from = collect();
        $this->to = collect();
    }

    /**
     * @return array{from: list<array<string, mixed>>, to: list<array<string, mixed>>, journals: list<array<string, mixed>>}
     */
    public static function for(Model $document, ?User $viewer): array
    {
        $related = new self($document);
        $related->collect();

        $refs = fn (Collection $models) => $models
            ->filter(fn (Model $model) => DocumentLinks::visible($model, $viewer))
            ->sortBy(fn (Model $model) => [DocumentLinks::date($model) ?? '', $model->getKey()])
            ->map(fn (Model $model) => DocumentLinks::ref($model, $viewer))
            ->values()
            ->all();

        return [
            'from' => $refs($related->from),
            'to' => $refs($related->to),
            'journals' => $viewer === null || $viewer->can('accounting.view') ? $related->journals($viewer) : [],
        ];
    }

    private function from(?Model ...$models): void
    {
        foreach ($models as $model) {
            if ($model !== null && ! $this->same($model)) {
                $this->from->put($model::class.'#'.$model->getKey(), $model);
            }
        }
    }

    /** @param  iterable<int, Model|null>  $models */
    private function to(iterable $models): void
    {
        foreach ($models as $model) {
            if ($model !== null && ! $this->same($model)) {
                $this->to->put($model::class.'#'.$model->getKey(), $model);
            }
        }
    }

    private function same(Model $model): bool
    {
        return $model::class === $this->document::class && $model->getKey() === $this->document->getKey();
    }

    private function collect(): void
    {
        $doc = $this->document;

        match (true) {
            $doc instanceof PurchaseOrder => $this->purchaseOrder($doc),
            $doc instanceof GoodsReceipt => $this->goodsReceipt($doc),
            $doc instanceof SupplierBill => $this->bill($doc),
            $doc instanceof SupplierPayment => $this->payment($doc),
            $doc instanceof SupplierAdvance => $this->advance($doc),
            $doc instanceof LandedCost => $this->landedCost($doc),
            $doc instanceof QuickPurchase => $this->quickPurchase($doc),
            $doc instanceof SalesOrder => $this->salesOrder($doc),
            $doc instanceof SalesInvoice => $this->invoice($doc),
            default => null,
        };
    }

    private function purchaseOrder(PurchaseOrder $po): void
    {
        $this->from(...QuickPurchase::query()->where('purchase_order_id', $po->id)->get()->all());

        $receipts = GoodsReceipt::query()->where('purchase_order_id', $po->id)->get();
        $returns = PurchaseReturn::query()->where('purchase_order_id', $po->id)->get();
        $bills = SupplierBill::query()->where('purchase_order_id', $po->id)->get();

        $this->to($receipts);
        $this->to($returns);
        $this->to($bills);
        $this->to(SupplierAdvance::query()->where('purchase_order_id', $po->id)->get());
        $this->to(LandedCost::query()->whereIn('goods_receipt_id', $receipts->pluck('id'))->get());
        $this->to(SupplierCreditNote::query()->whereIn('purchase_return_id', $returns->pluck('id'))->get());
        $this->to($this->paymentsFor($bills->pluck('id')));
    }

    private function goodsReceipt(GoodsReceipt $receipt): void
    {
        $this->from($receipt->purchaseOrder, ...QuickPurchase::query()->where('goods_receipt_id', $receipt->id)->get()->all());
        $this->to(LandedCost::query()->where('goods_receipt_id', $receipt->id)->get());
        $this->to(SupplierBill::query()->where('purchase_order_id', $receipt->purchase_order_id)->get());
    }

    private function bill(SupplierBill $bill): void
    {
        $this->from(
            $bill->purchaseOrder,
            $bill->landedCost,
            ...QuickPurchase::query()->where('supplier_bill_id', $bill->id)->get()->all(),
        );

        if ($bill->purchase_order_id !== null) {
            $this->from(...GoodsReceipt::query()->where('purchase_order_id', $bill->purchase_order_id)->get()->all());
        }

        $this->to($this->paymentsFor(collect([$bill->id])));
        $this->to(SupplierAdvanceApplication::query()->where('supplier_bill_id', $bill->id)->get());
        $this->to(SupplierCreditNote::query()->whereIn('id', SupplierCreditNoteAllocation::query()->where('supplier_bill_id', $bill->id)->select('supplier_credit_note_id'))->get());
        $this->to(SupplierDiscount::query()->where('supplier_bill_id', $bill->id)->get());
        $this->to(LandedCost::query()->where('supplier_bill_id', $bill->id)->get());
        $this->to(SetOff::query()->whereIn('id', SetOffLine::query()->where('supplier_bill_id', $bill->id)->select('set_off_id'))->get());
        $this->to(PartyAdjustment::query()->whereIn('id', PartyAdjustmentAllocation::query()->where('supplier_bill_id', $bill->id)->select('party_adjustment_id'))->get());
    }

    private function payment(SupplierPayment $payment): void
    {
        $this->from(
            $payment->voucher_id === null ? null : Voucher::find($payment->voucher_id),
            ...QuickPurchase::query()->where('supplier_payment_id', $payment->id)->get()->all(),
        );
        // The bills it paid: what the payment was made against.
        $this->from(...SupplierBill::query()->whereIn('id', SupplierPaymentAllocation::query()->where('supplier_payment_id', $payment->id)->select('supplier_bill_id'))->get()->all());

        $this->to(SupplierAdvance::query()->where('supplier_payment_id', $payment->id)->get());
        $this->to(SupplierDiscount::query()->where('supplier_payment_id', $payment->id)->get());
    }

    private function advance(SupplierAdvance $advance): void
    {
        $this->from($advance->purchaseOrder, $advance->payment);

        $applications = SupplierAdvanceApplication::query()->where('supplier_advance_id', $advance->id)->with('bill')->get();
        $this->to($applications);
        $this->to($applications->pluck('bill'));
        $this->to(SupplierAdvanceRefund::query()->where('supplier_advance_id', $advance->id)->get());
    }

    private function landedCost(LandedCost $cost): void
    {
        $receipt = $cost->goodsReceipt;
        $this->from($receipt, $receipt?->purchaseOrder, $cost->sourceBill);
        $this->to($cost->bills()->get());
    }

    private function quickPurchase(QuickPurchase $purchase): void
    {
        $this->to([$purchase->purchaseOrder, $purchase->goodsReceipt, $purchase->landedCost, $purchase->bill, $purchase->payment]);
    }

    private function salesOrder(SalesOrder $order): void
    {
        $deliveryLines = DeliveryLine::query()
            ->whereHas('orderLine', fn ($q) => $q->where('sales_order_id', $order->id))
            ->get(['id', 'delivery_id']);

        $this->to(Delivery::query()->whereIn('id', $deliveryLines->pluck('delivery_id')->unique())->get());
        $this->to(SalesInvoice::query()->whereIn('id', SalesInvoiceLine::query()->whereIn('delivery_line_id', $deliveryLines->pluck('id'))->select('sales_invoice_id'))->get());
        $this->to(CustomerReceipt::query()->where('sales_order_id', $order->id)->get());
        $this->to(CustomerRefund::query()->where('sales_order_id', $order->id)->get());
    }

    private function invoice(SalesInvoice $invoice): void
    {
        $deliveryIds = DeliveryLine::query()
            ->whereIn('id', SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->whereNotNull('delivery_line_id')->select('delivery_line_id'))
            ->with('orderLine:id,sales_order_id')
            ->get();

        $this->from(...Delivery::query()->whereIn('id', $deliveryIds->pluck('delivery_id')->unique())->get()->all());
        $this->from(...SalesOrder::query()->whereIn('id', $deliveryIds->pluck('orderLine.sales_order_id')->filter()->unique())->get()->all());

        $this->to(CustomerReceipt::query()->whereIn('id', CustomerReceiptAllocation::query()->where('sales_invoice_id', $invoice->id)->select('customer_receipt_id'))->get());
        $this->to(SalesReturn::query()->whereIn('id', SalesReturnAllocation::query()->where('sales_invoice_id', $invoice->id)->select('sales_return_id'))->get());
        $this->to(SetOff::query()->whereIn('id', SetOffLine::query()->where('sales_invoice_id', $invoice->id)->select('set_off_id'))->get());
        $this->to(PartyAdjustment::query()->whereIn('id', PartyAdjustmentAllocation::query()->where('sales_invoice_id', $invoice->id)->select('party_adjustment_id'))->get());
    }

    /** @return Collection<int, SupplierPayment> */
    private function paymentsFor(Collection $billIds): Collection
    {
        return SupplierPayment::query()
            ->whereIn('id', SupplierPaymentAllocation::query()->whereIn('supplier_bill_id', $billIds)->select('supplier_payment_id'))
            ->get();
    }

    /**
     * The journals of this document and of everything linked to it, oldest
     * first, each with its reversal.
     *
     * @return list<array<string, mixed>>
     */
    private function journals(?User $viewer): array
    {
        $documents = collect([$this->document])->concat($this->from)->concat($this->to);

        // Only documents that belong to THIS one's money story: on a bill,
        // its own journal and those of what it led to; on a PO or an order,
        // which post nothing, those of everything under them.
        $postsNothing = $this->document instanceof PurchaseOrder || $this->document instanceof SalesOrder || $this->document instanceof QuickPurchase;
        $scope = $postsNothing ? $documents : collect([$this->document])->concat($this->to);

        $byType = $scope->groupBy(fn (Model $model) => $model->getMorphClass());

        if ($byType->isEmpty()) {
            return [];
        }

        $journals = Journal::query()
            ->where(function ($query) use ($byType) {
                foreach ($byType as $type => $models) {
                    $query->orWhere(fn ($q) => $q->where('source_type', $type)->whereIn('source_id', $models->map->getKey()));
                }
            })
            ->get();

        // Documents that keep their journal on their own row.
        $direct = $scope->map(fn (Model $model) => $model->getAttribute('journal_id'))->filter()->unique();
        $reversals = $scope->map(fn (Model $model) => $model->getAttribute('reversal_journal_id'))->filter()->unique();

        $journals = $journals
            ->concat(Journal::query()->whereIn('id', $direct->merge($reversals))->get())
            ->unique('id');

        $journals = $journals->concat(Journal::query()->whereIn('reverses_journal_id', $journals->pluck('id'))->get())->unique('id');

        return $journals
            ->sortBy(fn (Journal $journal) => [$journal->journal_date?->toDateString(), $journal->id])
            ->map(fn (Journal $journal) => [
                ...DocumentLinks::ref($journal, $viewer),
                'is_reversal' => $journal->reverses_journal_id !== null,
                'source' => $journal->source_type === null ? null : class_basename($journal->source_type),
            ])
            ->values()
            ->all();
    }
}
