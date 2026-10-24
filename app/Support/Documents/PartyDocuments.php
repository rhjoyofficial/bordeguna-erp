<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Models\VoucherLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * ⭐ EVERY DOCUMENT A PARTY HAS, NEWEST FIRST (PR5 connected-360, 18 Oct 2026).
 *
 * The 360 pages listed 15 rows of five document types. Joy's ask: from one
 * supplier or customer, fetch out every related transaction, the way a Tally
 * ledger drills into each voucher. This reads every document table that
 * names the party, one query per type, and hands back one row shape:
 * type, label, number, date, amount, status, a short note and the link
 * (DocumentLinks, so a row the viewer cannot open shows as plain text).
 *
 * ⚠ It OWNS NO FIGURE. Balances stay where they are (the ageing, the
 * statement, the supplier ledger); this is the list of documents behind them.
 * A reversed document stays in the list, marked, because "it happened and
 * was undone" is part of the story.
 *
 * ⚠ A kind of document the viewer may not read at all (landed costs without
 * the landed-cost permission, vouchers without accounting) is left out, not
 * shown with a dead number.
 */
final class PartyDocuments
{
    public const PER_PAGE = 25;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function forSupplier(Supplier $supplier, ?User $viewer): Collection
    {
        $rows = collect();
        $add = function (string $class, callable $fetch) use (&$rows, $viewer) {
            if (DocumentLinks::visible($class, $viewer)) {
                $rows = $rows->concat($fetch());
            }
        };

        $orders = PurchaseOrder::query()->where('supplier_id', $supplier->id)->with('lines')->get();
        $orderIds = $orders->pluck('id');

        $add(PurchaseOrder::class, fn () => $orders->map(fn (PurchaseOrder $po) => self::row($po, $viewer,
            amount: Money::sum($po->lines->map(fn (PurchaseOrderLine $line) => $line->lineTotal())),
            status: $po->status->value,
        )));

        $receipts = GoodsReceipt::query()->whereIn('purchase_order_id', $orderIds)->with('purchaseOrder:id,po_number')->get();
        $add(GoodsReceipt::class, fn () => $receipts->map(fn (GoodsReceipt $receipt) => self::row($receipt, $viewer,
            note: self::against('PO', $receipt->purchaseOrder?->po_number, $receipt->supplier_challan_number ? 'challan '.$receipt->supplier_challan_number : null),
        )));

        $add(PurchaseReturn::class, fn () => PurchaseReturn::query()->whereIn('purchase_order_id', $orderIds)
            ->with('purchaseOrder:id,po_number')->get()
            ->map(fn (PurchaseReturn $return) => self::row($return, $viewer, note: self::against('PO', $return->purchaseOrder?->po_number))));

        $bills = SupplierBill::query()->where('supplier_id', $supplier->id)->with('purchaseOrder:id,po_number')->get();
        $add(SupplierBill::class, fn () => $bills->map(fn (SupplierBill $bill) => self::row($bill, $viewer,
            amount: Money::ofTaka((string) $bill->total),
            status: $bill->isReversed() ? 'reversed' : null,
            note: self::against('PO', $bill->purchaseOrder?->po_number),
        )));

        $add(SupplierPayment::class, fn () => SupplierPayment::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (SupplierPayment $payment) => self::row($payment, $viewer,
                amount: Money::ofTaka((string) $payment->amount)->negated(),
                status: $payment->status(),
            )));

        $add(SupplierAdvance::class, fn () => SupplierAdvance::query()->where('supplier_id', $supplier->id)
            ->with('purchaseOrder:id,po_number')->get()
            ->map(fn (SupplierAdvance $advance) => self::row($advance, $viewer,
                amount: Money::ofTaka((string) $advance->amount)->negated(),
                status: $advance->status(),
                note: self::against('PO', $advance->purchaseOrder?->po_number),
            )));

        $add(SupplierAdvanceApplication::class, fn () => SupplierAdvanceApplication::query()->where('supplier_id', $supplier->id)
            ->with(['bill:id,bill_number', 'advance:id,number'])->get()
            ->map(fn (SupplierAdvanceApplication $application) => self::row($application, $viewer,
                amount: Money::ofTaka((string) $application->amount),
                status: $application->status(),
                note: self::against('bill', $application->bill?->bill_number, $application->advance?->number ? 'from '.$application->advance->number : null),
            )));

        $add(SupplierAdvanceRefund::class, fn () => SupplierAdvanceRefund::query()->where('supplier_id', $supplier->id)
            ->with('advance:id,number')->get()
            ->map(fn (SupplierAdvanceRefund $refund) => self::row($refund, $viewer,
                amount: Money::ofTaka((string) $refund->amount),
                status: $refund->status(),
                note: self::against('advance', $refund->advance?->number),
            )));

        $add(SupplierRefund::class, fn () => SupplierRefund::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (SupplierRefund $refund) => self::row($refund, $viewer,
                amount: Money::ofTaka((string) $refund->amount),
                status: $refund->status(),
            )));

        $add(SupplierCreditNote::class, fn () => SupplierCreditNote::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (SupplierCreditNote $credit) => self::row($credit, $viewer,
                amount: Money::ofTaka((string) $credit->total)->negated(),
                status: $credit->status(),
            )));

        $add(SupplierDiscount::class, fn () => SupplierDiscount::query()->where('supplier_id', $supplier->id)
            ->with('bill:id,bill_number')->get()
            ->map(fn (SupplierDiscount $discount) => self::row($discount, $viewer,
                amount: Money::ofTaka((string) $discount->amount)->negated(),
                status: $discount->status(),
                note: self::against('bill', $discount->bill?->bill_number),
            )));

        // A landed cost belongs here when it sits on THIS supplier's receipt
        // or was typed on THIS supplier's bill.
        $add(LandedCost::class, fn () => LandedCost::query()
            ->where(fn ($q) => $q->whereIn('goods_receipt_id', $receipts->pluck('id'))->orWhereIn('supplier_bill_id', $bills->pluck('id')))
            ->get()
            ->map(fn (LandedCost $cost) => self::row($cost, $viewer,
                amount: Money::ofMinor((int) $cost->total_paisa),
                status: $cost->status?->value,
            )));

        $add(ExpenseVoucher::class, fn () => ExpenseVoucher::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (ExpenseVoucher $voucher) => self::row($voucher, $viewer,
                amount: Money::ofTaka((string) $voucher->total),
                status: $voucher->status(),
            )));

        $add(QuickPurchase::class, fn () => QuickPurchase::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (QuickPurchase $purchase) => self::row($purchase, $viewer,
                amount: Money::ofMinor((int) $purchase->goods_paisa + (int) $purchase->extra_costs_paisa),
                status: $purchase->status,
            )));

        $add(PartyAdjustment::class, fn () => PartyAdjustment::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (PartyAdjustment $adjustment) => self::row($adjustment, $viewer,
                amount: Money::ofTaka((string) $adjustment->amount),
                note: $adjustment->label(),
            )));

        $add(SetOff::class, fn () => SetOff::query()->where('supplier_id', $supplier->id)->get()
            ->map(fn (SetOff $setOff) => self::row($setOff, $viewer,
                amount: Money::ofTaka((string) $setOff->amount),
                status: (string) $setOff->getAttribute('status'),
            )));

        $add(Voucher::class, fn () => self::vouchersNaming($supplier, $viewer));

        return self::ordered($rows);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function forCustomer(Customer $customer, ?User $viewer): Collection
    {
        $rows = collect();
        $add = function (string $class, callable $fetch) use (&$rows, $viewer) {
            if (DocumentLinks::visible($class, $viewer)) {
                $rows = $rows->concat($fetch());
            }
        };

        $add(SalesOrder::class, fn () => SalesOrder::query()->where('customer_id', $customer->id)->with('lines')->get()
            ->map(fn (SalesOrder $order) => self::row($order, $viewer,
                amount: Money::ofTaka($order->agreedTotal()),
                status: $order->status?->value,
            )));

        $add(Delivery::class, fn () => Delivery::query()->where('customer_id', $customer->id)->with('consignment')->get()
            ->map(fn (Delivery $delivery) => self::row($delivery, $viewer,
                note: $delivery->consignment?->consignment_number ? 'parcel '.$delivery->consignment->consignment_number : null,
            )));

        $add(SalesInvoice::class, fn () => SalesInvoice::query()->where('customer_id', $customer->id)->get()
            ->map(fn (SalesInvoice $invoice) => self::row($invoice, $viewer, amount: $invoice->totalMoney())));

        $add(CustomerReceipt::class, fn () => CustomerReceipt::query()->where('customer_id', $customer->id)
            ->with('salesOrder:id,order_number')->get()
            ->map(fn (CustomerReceipt $receipt) => self::row($receipt, $viewer,
                amount: Money::ofTaka((string) $receipt->amount)->negated(),
                note: $receipt->isPayrollOffset() ? 'Against salary' : self::against('order', $receipt->salesOrder?->order_number),
            )));

        $add(SalesReturn::class, fn () => SalesReturn::query()->where('customer_id', $customer->id)
            ->with('delivery:id,delivery_number')->get()
            ->map(fn (SalesReturn $return) => self::row($return, $viewer,
                amount: Money::ofTaka((string) $return->credit_total)->negated(),
                note: self::against('challan', $return->delivery?->delivery_number),
            )));

        $add(CustomerRefund::class, fn () => CustomerRefund::query()->where('customer_id', $customer->id)
            ->with(['salesReturn:id,return_number', 'salesOrder:id,order_number'])->get()
            ->map(fn (CustomerRefund $refund) => self::row($refund, $viewer,
                amount: Money::ofTaka((string) $refund->amount),
                note: $refund->salesReturn ? self::against('return', $refund->salesReturn->return_number) : self::against('order', $refund->salesOrder?->order_number),
            )));

        // ⭐ B11 (24 Oct 2026): the customer's advances, their uses and refunds.
        $add(CustomerAdvance::class, fn () => CustomerAdvance::query()->where('customer_id', $customer->id)
            ->with('salesOrder:id,order_number')->get()
            ->map(fn (CustomerAdvance $advance) => self::row($advance, $viewer,
                amount: $advance->money()->negated(),
                note: $advance->isFromOrder() ? self::against('order', $advance->salesOrder?->order_number) : $advance->kindLabel(),
            )));

        $add(CustomerAdvanceApplication::class, fn () => CustomerAdvanceApplication::query()->where('customer_id', $customer->id)
            ->with('invoice:id,invoice_number')->get()
            ->map(fn (CustomerAdvanceApplication $use) => self::row($use, $viewer,
                amount: $use->money(),
                note: self::against('invoice', $use->invoice?->invoice_number),
            )));

        $add(CustomerAdvanceRefund::class, fn () => CustomerAdvanceRefund::query()->where('customer_id', $customer->id)->get()
            ->map(fn (CustomerAdvanceRefund $refund) => self::row($refund, $viewer, amount: $refund->money())));

        $add(PartyAdjustment::class, fn () => PartyAdjustment::query()->where('customer_id', $customer->id)->get()
            ->map(fn (PartyAdjustment $adjustment) => self::row($adjustment, $viewer,
                amount: Money::ofTaka((string) $adjustment->amount)->negated(),
                note: $adjustment->label(),
            )));

        $add(SetOff::class, fn () => SetOff::query()->where('customer_id', $customer->id)->get()
            ->map(fn (SetOff $setOff) => self::row($setOff, $viewer,
                amount: Money::ofTaka((string) $setOff->amount)->negated(),
                status: (string) $setOff->getAttribute('status'),
            )));

        $add(IncomeVoucher::class, fn () => IncomeVoucher::query()->where('customer_id', $customer->id)->get()
            ->map(fn (IncomeVoucher $voucher) => self::row($voucher, $viewer,
                amount: Money::ofTaka((string) $voucher->total),
                status: $voucher->status(),
            )));

        // Quotations live in a plain table (no model): a quoted price is a
        // promise, not a posting.
        if ($viewer === null || $viewer->can('sales.orders.record')) {
            $rows = $rows->concat(self::quotations($customer));
        }

        $add(Voucher::class, fn () => self::vouchersNaming($customer, $viewer));

        return self::ordered($rows);
    }

    /**
     * The type filter and page. `$type` empty means every type.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows: LengthAwarePaginator, types: list<array{type: string, label: string, count: int}>, type: string}
     */
    public static function page(Collection $rows, string $type, int $page, string $path): array
    {
        $types = $rows->groupBy('type')
            ->map(fn (Collection $group, string $key) => ['type' => $key, 'label' => DocumentLinks::labelFor($key), 'count' => $group->count()])
            ->sortBy(fn (array $entry) => array_search($entry['type'], [...DocumentLinks::types(), 'quotation'], true))
            ->values()
            ->all();

        $filtered = $type === '' ? $rows : $rows->where('type', $type)->values();
        $page = max(1, $page);

        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => $path, 'pageName' => 'docs_page'],
        );

        return [
            'rows' => $paginator->appends(array_filter(['doc_type' => $type])),
            'types' => $types,
            'type' => $type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Model $document, ?User $viewer, ?Money $amount = null, ?string $status = null, ?string $note = null): array
    {
        $ref = DocumentLinks::ref($document, $viewer);

        return [
            ...$ref,
            'key' => $ref['type'].'-'.$document->getKey(),
            'id' => $document->getKey(),
            'amount' => $amount?->format(),
            'status' => $status === 'posted' ? null : ($status ?: null),
            'note' => $note,
        ];
    }

    private static function against(string $what, ?string $number, ?string $extra = null): ?string
    {
        $parts = array_filter([$number === null || $number === '' ? null : "{$what} {$number}", $extra]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Payment, Receipt and Journal vouchers whose lines name this party (the
     * party ledger heads, PR4), one row per voucher.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function vouchersNaming(Model $party, ?User $viewer): Collection
    {
        $voucherIds = VoucherLine::query()
            ->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey())
            ->distinct()
            ->pluck('voucher_id');

        if ($voucherIds->isEmpty()) {
            return collect();
        }

        $partyMinor = VoucherLine::query()
            ->whereIn('voucher_id', $voucherIds)
            ->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey())
            ->get(['voucher_id', 'side', 'amount_minor'])
            ->groupBy('voucher_id')
            ->map(fn (Collection $lines) => $lines->sum(fn (VoucherLine $line) => abs((int) $line->amount_minor)));

        return Voucher::query()->whereIn('id', $voucherIds)->get()
            ->map(fn (Voucher $voucher) => self::row($voucher, $viewer,
                amount: Money::ofMinor((int) ($partyMinor[$voucher->id] ?? $voucher->total_minor)),
                status: is_object($voucher->status) ? $voucher->status->value : (string) $voucher->status,
                note: $voucher->narration ? Str::limit((string) $voucher->narration, 60) : null,
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function quotations(Customer $customer): Collection
    {
        $quotations = DB::table('quotations')
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->get();

        if ($quotations->isEmpty()) {
            return collect();
        }

        $totals = DB::table('quotation_lines')
            ->whereIn('quotation_id', $quotations->pluck('id'))
            ->get()
            ->groupBy('quotation_id')
            ->map(fn (Collection $lines) => Money::sum($lines->map(fn ($line) => Money::ofProduct((string) $line->quantity, (string) $line->unit_price))));

        $mayPrint = Route::has('sales.quotations.print');

        return $quotations->map(fn ($quotation) => [
            'type' => 'quotation',
            'label' => 'Quotation',
            'number' => (string) $quotation->quotation_number,
            'date' => $quotation->created_at === null ? null : substr((string) $quotation->created_at, 0, 10),
            'url' => $mayPrint ? route('sales.quotations.print', $quotation->id, false) : null,
            'key' => 'quotation-'.$quotation->id,
            'id' => $quotation->id,
            'amount' => ($totals[$quotation->id] ?? null)?->format(),
            'status' => $quotation->status,
            'note' => null,
        ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private static function ordered(Collection $rows): Collection
    {
        return $rows
            ->sortBy([
                fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']),
                fn (array $a, array $b) => $b['id'] <=> $a['id'],
            ])
            ->values();
    }
}
