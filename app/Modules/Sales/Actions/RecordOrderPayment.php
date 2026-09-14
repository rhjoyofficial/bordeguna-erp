<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money a customer paid for an order (SSLCommerz slice P1, Joy 13 September).
 *
 * ⭐⭐ AN ORDER'S MONEY SETTLES THAT ORDER'S INVOICES FIRST. Whatever an open
 * invoice of the order still owes is allocated to it at once; the rest is held
 * on the customer's account for the order, and its next invoice takes it. The
 * same rule whether the money came before the parcel left or after the courier
 * delivered it.
 *
 * ⚠ One receipt, through RecordCustomerReceipt, so the money is the same
 * document with the same guards and the same journal as any other.
 */
class RecordOrderPayment
{
    public function __construct(private readonly RecordCustomerReceipt $receipts) {}

    /**
     * @param  bool  $moneyAlreadyTaken  true only where the money has already left
     *                                   the customer and cannot be declined - a
     *                                   payment a gateway proves after the order
     *                                   was cancelled, or more than the order.
     *                                   Refusing it then would not return the
     *                                   money, it would only lose the record.
     * @param  bool  $provenByGateway  true only from BookGatewayPayment: the one
     *                                 way money reaches a gateway's account.
     *
     * @throws ValidationException
     */
    public function handle(
        SalesOrder $order,
        MoneyAccount $moneyAccount,
        mixed $receivedOn,
        string $amount,
        User $createdBy,
        ?string $reference = null,
        ?string $note = null,
        bool $moneyAlreadyTaken = false,
        bool $provenByGateway = false,
    ): CustomerReceipt {
        $paying = Money::ofTaka($amount);

        if ($paying->minor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A payment must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($order, $moneyAccount, $receivedOn, $paying, $createdBy, $reference, $note, $moneyAlreadyTaken, $provenByGateway) {
            // ⚠⚠ RE-READ UNDER A LOCK. Two people recording the same bKash
            // transfer at once is how an order reads as paid twice, and the
            // re-read is what makes the cap below hold on MySQL.
            /** @var SalesOrder $order */
            $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->with(['customer', 'lines.deliveryLines'])->firstOrFail();

            if (! $moneyAlreadyTaken) {
                if ($order->status === SalesOrderStatus::Cancelled || $order->approvalRequest?->status === ApprovalStatus::Rejected) {
                    throw ValidationException::withMessages([
                        'amount' => "Order {$order->order_number} will not be delivered, so no payment is taken for it.",
                    ]);
                }

                $stillToPay = $order->stillToPay();

                // ⚠ More than the order is not a payment for it. Refused here,
                // where a person typed it and can correct it.
                if ($paying->minor > $stillToPay->minor) {
                    throw ValidationException::withMessages([
                        'amount' => $stillToPay->isZero()
                            ? "Order {$order->order_number} is already paid in full."
                            : "Order {$order->order_number} has only {$stillToPay->format()} left to pay.",
                    ]);
                }
            }

            $allocations = [];
            $remaining = $paying->minor;

            $openInvoices = $order->invoicesQuery()
                ->where('customer_id', $order->customer_id)
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($openInvoices as $invoice) {
                /** @var SalesInvoice $invoice */
                $part = min($invoice->outstanding()->minor, $remaining);

                if ($part <= 0) {
                    continue;
                }

                $allocations[] = ['invoice' => $invoice, 'amount' => Money::ofMinor($part)->toTaka()];
                $remaining -= $part;
            }

            return $this->receipts->handle(
                customer: $order->customer,
                moneyAccount: $moneyAccount,
                receivedOn: $receivedOn,
                allocations: $allocations,
                createdBy: $createdBy,
                reference: $reference,
                note: $note ?? "Paid for order {$order->order_number}",
                forOrder: $order,
                heldForOrder: Money::ofMinor($remaining)->toTaka(),
                provenByGateway: $provenByGateway,
            );
        });
    }
}
