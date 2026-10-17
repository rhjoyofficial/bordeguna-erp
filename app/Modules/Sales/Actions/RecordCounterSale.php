<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\CounterSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A walk-in sale: goods handed over, invoice raised, money taken.
 *
 * ⚠⚠ THIS IS NOT A FOURTH KIND OF SALE. It calls the same three actions a
 * distributor sale uses, in sequence, inside ONE transaction. That is the whole
 * architecture in one method: Goods Delivered Not Invoiced is debited and
 * credited within milliseconds and nets to zero, Accounts Receivable likewise,
 * and what remains on the books is exactly the simple four-line sale - revenue,
 * cost of goods sold, stock out, cash in.
 *
 * ⚠ There is no branch here for "retail" and no flag on any document saying so.
 * Change that and the Distributor module immediately becomes a second
 * implementation, which is the one thing Joy asked this design to prevent.
 *
 * ⚠ One transaction, so a counter sale cannot half-happen. Stock handed over
 * with no invoice, or an invoice with no money taken, are not states a till can
 * reach.
 */
class RecordCounterSale
{
    public function __construct(
        private readonly RecordDelivery $recordDelivery,
        private readonly RecordSalesInvoice $recordInvoice,
        private readonly RecordCustomerReceipt $recordReceipt,
        private readonly ResolveSellingPrice $resolvePrice,
    ) {}

    /**
     * @param  array<int, array{
     *     product: Product,
     *     quantity: string,
     *     unitPrice?: string|null,
     *     discount?: string|null,
     *     listPrice?: string|null,
     *     overrideReason?: string|null,
     *     overriddenBy?: User|null
     * }>  $lines  `listPrice`, `overrideReason` and `overriddenBy` are the
     *             counter's price trail, handed straight to the invoice line
     *             (see RecordSalesInvoice). This action decides nothing about
     *             who may change a price: the counter screen does.
     *
     * @throws ValidationException
     */
    public function handle(
        Customer $customer,
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        ?MoneyAccount $moneyAccount = null,
        mixed $soldAt = null,
        string $invoiceDiscount = '0',
        ?string $reference = null,
        ?string $note = null,
        ?string $discountReason = null,
        ?User $discountGivenBy = null,
    ): CounterSale {
        $lines = array_values(array_filter($lines, fn (array $entry) => (float) $entry['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A sale needs at least one line with a quantity greater than zero.',
            ]);
        }

        $date = $soldAt === null ? Carbon::now() : Carbon::parse($soldAt);

        return DB::transaction(function () use ($customer, $warehouse, $lines, $createdBy, $moneyAccount, $date, $invoiceDiscount, $reference, $note, $discountReason, $discountGivenBy) {
            // ⚠⚠ THE PRICE IS RESOLVED ONCE and copied onto the invoice line:
            // here when the caller gives none, or by the counter screen, which
            // resolves it first so it can tell a typed price from the list one
            // (21 Sep 2026). Resolving it again later would let an invoice
            // disagree with what the customer was told at the counter.
            $priced = [];

            foreach ($lines as $entry) {
                $priced[] = [
                    'product' => $entry['product'],
                    'quantity' => $entry['quantity'],
                    'unitPrice' => $entry['unitPrice']
                        ?? $this->resolvePrice->resolve(
                            $entry['product'],
                            $customer,
                            $entry['quantity'],
                            $date,
                        )->unitPrice,
                    'discount' => $entry['discount'] ?? '0',
                    'listPrice' => $entry['listPrice'] ?? null,
                    'overrideReason' => $entry['overrideReason'] ?? null,
                    'overriddenBy' => $entry['overriddenBy'] ?? null,
                    // ⭐ Phase 2 (17 Oct 2026): a staff purchase line is flagged
                    // on the invoice and takes its reserved batch first.
                    'isStaffSale' => (bool) ($entry['isStaffSale'] ?? false),
                    'reservedFor' => $entry['reservedFor'] ?? null,
                ];
            }

            $delivery = $this->recordDelivery->handle(
                customer: $customer,
                warehouse: $warehouse,
                lines: array_map(fn (array $e) => [
                    'product' => $e['product'],
                    'quantity' => $e['quantity'],
                    'reservedFor' => $e['reservedFor'],
                ], $priced),
                createdBy: $createdBy,
                deliveredAt: $date,
                note: $note,
            );

            // ⚠ Delivery lines come back in the order they were written, which
            // is the order they were passed in. Zipped rather than matched on
            // product, because the same product can legitimately appear twice on
            // one sale at two different prices.
            $deliveryLines = $delivery->lines->values();

            $invoiceLines = [];

            foreach ($priced as $index => $entry) {
                $invoiceLines[] = [
                    'deliveryLine' => $deliveryLines[$index],
                    'product' => $entry['product'],
                    'quantity' => $entry['quantity'],
                    'unitPrice' => $entry['unitPrice'],
                    'discount' => $entry['discount'],
                    'listPrice' => $entry['listPrice'],
                    'overrideReason' => $entry['overrideReason'],
                    'overriddenBy' => $entry['overriddenBy'],
                    'isStaffSale' => $entry['isStaffSale'],
                ];
            }

            $invoice = $this->recordInvoice->handle(
                customer: $customer,
                lines: $invoiceLines,
                createdBy: $createdBy,
                invoiceDate: $date,
                invoiceDiscount: $invoiceDiscount,
                note: $note,
                discountReason: $discountReason,
                discountGivenBy: $discountGivenBy,
            );

            $receipt = null;

            // ⚠ A counter sale is normally paid immediately, but not always -
            // a known customer can take goods away on account. No money account
            // means no receipt, the invoice stands open, and the SAME documents
            // describe both. That is the seam where retail meets credit.
            if ($moneyAccount !== null) {
                $receipt = $this->recordReceipt->handle(
                    customer: $customer,
                    moneyAccount: $moneyAccount,
                    receivedOn: $date,
                    allocations: [['invoice' => $invoice, 'amount' => (string) $invoice->total]],
                    createdBy: $createdBy,
                    reference: $reference,
                );
            }

            return new CounterSale(
                delivery: $delivery->fresh(['lines', 'customer']),
                invoice: $invoice->fresh(['lines', 'customer']),
                receipt: $receipt?->fresh(['allocations']),
            );
        });
    }
}
