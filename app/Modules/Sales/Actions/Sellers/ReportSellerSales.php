<?php

namespace App\Modules\Sales\Actions\Sellers;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\SellerStockGate;
use App\Modules\Sales\Actions\CheckCustomerCredit;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SellerSale;
use App\Modules\Sales\Support\RetailSellers;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B21 (R109; Joy's Q3 = A, 24 Oct 2026): a retail seller
 * reports what he sold. "If can sell then they sold."
 *
 * ⚠⚠ THE SALE IS AN ORDINARY COUNTER SALE FROM HIS SELLER STOCK
 * (RecordCounterSale: challan, invoice and, when he took the money, a receipt,
 * in one transaction). Revenue, cost of goods sold at the batch cost, the
 * customer ledger and the money account post exactly as at the shop counter;
 * nothing here writes a journal of its own. Its correction is therefore the
 * invoice's own Void sale through the engine: the goods go back into his
 * seller stock and the money comes off the account.
 *
 *   price      the Trade Price (default), or a named customer's own price
 *              group when the user chooses it
 *   money      collected: into his Employee cash account (Joy's Q1) or
 *              another account the user picks
 *              seller_owes: nothing paid in yet and HE answers for it: the
 *              invoice stays open on his own staff customer account, a
 *              receivable from the employee
 *              on_account: a named credit customer owes it, under the same
 *              credit rules as the counter (CheckCustomerCredit)
 */
class ReportSellerSales
{
    public function __construct(
        private readonly RecordCounterSale $counterSale,
        private readonly ResolveSellingPrice $prices,
        private readonly CheckCustomerCredit $credit,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: string}>  $lines
     */
    public function handle(
        Employee $seller,
        mixed $soldOn,
        array $lines,
        string $payment,
        User $by,
        ?Customer $customer = null,
        string $priceBasis = SellerSale::BASIS_TRADE,
        ?MoneyAccount $moneyAccount = null,
        ?string $soldTo = null,
        ?string $note = null,
    ): SellerSale {
        $stock = RetailSellers::stockOf($seller);

        if ($stock === null) {
            throw ValidationException::withMessages(['employee_id' => "{$seller->fullName()} is not a retail seller yet. Make him one first."]);
        }

        $lines = array_values(array_filter($lines, fn (array $line) => (float) $line['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item he sold, with a quantity above zero.']);
        }

        if ($customer !== null && ($customer->isWalkIn() || $customer->isStaff())) {
            $customer = null;
        }

        $refusals = match ($payment) {
            SellerSale::PAY_COLLECTED => $moneyAccount === null ? ['money_account_id' => 'Choose where the money he collected is kept (his Employee cash account).'] : [],
            SellerSale::PAY_SELLER_OWES => [],
            SellerSale::PAY_ON_ACCOUNT => $customer === null ? ['customer_id' => 'Only a named customer can owe the money. Choose the customer, or say he collected it or owes it himself.'] : [],
            default => ['payment' => 'Choose what happened to the money.'],
        };

        if ($priceBasis === SellerSale::BASIS_CUSTOMER && $customer === null) {
            $refusals['price_basis'] = "The customer's own price needs a named customer.";
        }

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        $at = EffectiveDate::resolveConsumingDay($soldOn, 'sold_on');
        $group = $priceBasis === SellerSale::BASIS_CUSTOMER ? null : RetailSellers::tradePriceGroup();

        if ($priceBasis !== SellerSale::BASIS_CUSTOMER && $group === null) {
            throw ValidationException::withMessages(['lines' => 'There is no Trade Price group to price the sale. Add it under Sales, Price groups.']);
        }

        // Who the invoice is made out to: the named customer, his own staff
        // account when he owes it, or the walk-in row.
        $invoiceCustomer = match (true) {
            $payment === SellerSale::PAY_SELLER_OWES => Customer::forEmployee($seller),
            $customer !== null => $customer,
            default => Customer::walkIn(),
        };

        if ($invoiceCustomer === null) {
            throw ValidationException::withMessages(['customer_id' => 'The walk-in customer is missing. Ask the administrator to run the walk-in seeder.']);
        }

        // ⚠ Priced once, before anything moves; every refusal at once.
        $priced = [];
        $refusals = [];

        foreach ($lines as $index => $line) {
            $price = $group === null
                ? $this->prices->find($line['product'], $customer, (string) $line['quantity'], $at, null, $stock->id)?->unitPrice
                : RetailSellers::tradePrice($line['product'], (string) $line['quantity'], $at);

            if ($price === null) {
                $refusals["lines.{$index}.product_id"] = $group === null
                    ? "{$line['product']->name} has no price for {$customer->name}. Sell it at the Trade Price, or set the price first."
                    : "{$line['product']->name} has no Trade Price. Set it under Sales, Selling prices first.";

                continue;
            }

            $priced[] = ['product' => $line['product'], 'quantity' => (string) $line['quantity'], 'unitPrice' => (string) $price];
        }

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        $soldTo = $soldTo === null || trim($soldTo) === '' ? $customer?->name : trim($soldTo);

        return SellerStockGate::through(fn () => DB::transaction(function () use ($seller, $stock, $priced, $payment, $by, $priceBasis, $moneyAccount, $soldTo, $note, $at, $invoiceCustomer) {
            Employee::query()->whereKey($seller->id)->lockForUpdate()->first();

            $label = 'Retail seller '.$seller->fullName().($soldTo === null ? '' : ', sold to '.$soldTo);
            $sale = $this->counterSale->handle(
                customer: $invoiceCustomer,
                warehouse: $stock,
                lines: $priced,
                createdBy: $by,
                moneyAccount: $payment === SellerSale::PAY_COLLECTED ? $moneyAccount : null,
                soldAt: $at,
                note: $note === null || trim($note) === '' ? $label : $label.'. '.trim($note),
            );

            $total = Money::ofTaka((string) $sale->invoice->total);

            if ($payment === SellerSale::PAY_ON_ACCOUNT && ($refusal = $this->credit->refusalForAmount($invoiceCustomer, $total, alreadyOwed: true)) !== null) {
                throw ValidationException::withMessages(['payment' => $refusal->getMessage()]);
            }

            return DocumentNumber::claim(
                'number',
                fn () => RetailSellers::nextNumber(SellerSale::query(), 'SS', $at, (int) app(BusinessContext::class)->id()),
                fn (string $number) => SellerSale::create([
                    'business_id' => app(BusinessContext::class)->id(),
                    'number' => $number,
                    'employee_id' => $seller->id,
                    'seller_warehouse_id' => $stock->id,
                    'sold_on' => $at->toDateString(),
                    'customer_id' => $invoiceCustomer->id,
                    'sold_to' => $soldTo,
                    'price_basis' => $priceBasis === SellerSale::BASIS_CUSTOMER ? SellerSale::BASIS_CUSTOMER : SellerSale::BASIS_TRADE,
                    'payment' => $payment,
                    'money_account_id' => $payment === SellerSale::PAY_COLLECTED ? $moneyAccount?->id : null,
                    'sales_invoice_id' => $sale->invoice->id,
                    'total' => (string) $sale->invoice->total,
                    'note' => $note,
                    'created_by' => $by->id,
                ]),
            );
        }));
    }
}
