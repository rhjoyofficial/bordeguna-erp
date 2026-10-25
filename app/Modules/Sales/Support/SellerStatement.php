<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SellerSale;
use App\Modules\Sales\Models\SellerTransfer;
use App\Modules\Sales\Models\SellerTransferLine;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B21 (R109, "must have track who sells how much"): one retail
 * seller's statement for a period.
 *
 *   taken       live hand-overs dated in the period: quantity, Trade Price
 *               value (what the paper says) and cost
 *   sold        his reported sales whose invoice still stands: quantity, the
 *               sale value and the cost of the goods (the batches the challan
 *               took)
 *   returned    live returns of unsold goods in the period
 *   holding     what his seller stock holds NOW, at cost and at today's Trade
 *               Price, and how long each batch has been with him (ageing)
 *   money       collected (sales he took the money for), handed in (money
 *               transfers out of his Employee cash accounts), the cash he
 *               holds now, what he owes on his own account (sales he answers
 *               for, still unpaid) and what named customers still owe
 *
 * ⚠ Read only, built from the documents themselves: a reversed sheet and a
 * voided sale simply drop out, exactly as the ledger and the stock did.
 */
final class SellerStatement
{
    /** @return array<string, mixed> */
    public function for(Employee $seller, Carbon $from, Carbon $to, bool $detail = true): array
    {
        $stock = RetailSellers::stockOf($seller);
        $products = [];
        $row = function (int $id, string $name) use (&$products) {
            $products[$id] ??= ['product_id' => $id, 'name' => $name, 'taken' => 0.0, 'sold' => 0.0, 'returned' => 0.0, 'holding' => 0.0];
        };

        $sheets = SellerTransfer::query()->where('employee_id', $seller->id)
            ->whereBetween('moved_on', [$from->toDateString(), $to->toDateString()])
            ->with(['lines.product:id,name', 'otherWarehouse:id,name', 'reversedBy:id,name'])
            ->orderBy('moved_on')->orderBy('id')->get();

        $taken = ['quantity' => 0.0, 'trade' => Money::zero(), 'cost' => Money::zero()];
        $returned = ['quantity' => 0.0, 'trade' => Money::zero(), 'cost' => Money::zero()];

        foreach ($sheets->filter(fn (SellerTransfer $s) => ! $s->isReversed()) as $sheet) {
            $bucket = $sheet->isHandover() ? 'taken' : 'returned';

            foreach ($sheet->lines as $line) {
                /** @var SellerTransferLine $line */
                $row($line->product_id, (string) $line->product?->name);
                $products[$line->product_id][$bucket] += (float) $line->quantity;
                ${$bucket}['quantity'] += (float) $line->quantity;
                ${$bucket}['trade'] = ${$bucket}['trade']->plus(Money::ofProduct((string) $line->quantity, (string) $line->trade_price));
                ${$bucket}['cost'] = ${$bucket}['cost']->plus(Money::ofTaka(number_format((float) $line->cost_value, 2, '.', '')));
            }
        }

        $sales = SellerSale::query()->where('employee_id', $seller->id)
            ->whereBetween('sold_on', [$from->toDateString(), $to->toDateString()])
            ->with(['invoice', 'customer:id,name', 'moneyAccount:id,name'])
            ->orderBy('sold_on')->orderBy('id')->get();

        $sold = ['quantity' => 0.0, 'value' => Money::zero(), 'cost' => Money::zero()];
        $collected = Money::zero();
        $standing = $sales->filter(fn (SellerSale $s) => $s->invoice !== null && ! $s->invoice->isReversed());

        foreach ($standing as $sale) {
            $sold['value'] = $sold['value']->plus(Money::ofTaka((string) $sale->invoice->total));

            if ($sale->payment === SellerSale::PAY_COLLECTED) {
                $collected = $collected->plus(Money::ofTaka((string) $sale->invoice->total));
            }

            foreach ($sale->invoice->lines()->with(['product:id,name', 'deliveryLine.movements'])->get() as $line) {
                /** @var SalesInvoiceLine $line */
                $row($line->product_id, (string) $line->product?->name);
                $products[$line->product_id]['sold'] += (float) $line->quantity;
                $sold['quantity'] += (float) $line->quantity;

                if ($line->deliveryLine !== null) {
                    $sold['cost'] = $sold['cost']->plus($line->deliveryLine->costValue());
                }
            }
        }

        // Holding NOW, batch by batch, with how long each has been with him.
        $holding = ['quantity' => 0.0, 'cost' => Money::zero(), 'trade' => Money::zero()];
        $ageing = [];

        if ($stock !== null) {
            $batches = StockBatch::query()->where('warehouse_id', $stock->id)->where('current_quantity', '>', 0)
                ->with('product')->orderBy('received_at')->get();

            foreach ($batches as $batch) {
                $quantity = (float) $batch->current_quantity;
                $row($batch->product_id, (string) $batch->product?->name);
                $products[$batch->product_id]['holding'] += $quantity;
                $tp = $batch->product === null ? null : RetailSellers::tradePrice($batch->product, (string) $batch->current_quantity);
                $cost = $batch->value();
                $trade = $tp === null ? Money::zero() : Money::ofProduct((string) $batch->current_quantity, $tp);
                $holding['quantity'] += $quantity;
                $holding['cost'] = $holding['cost']->plus($cost);
                $holding['trade'] = $holding['trade']->plus($trade);
                $since = $batch->received_at === null ? null : Carbon::parse($batch->received_at);
                $ageing[] = [
                    'product' => (string) $batch->product?->name,
                    'batch' => (string) $batch->batch_number,
                    'quantity' => $this->qty($quantity),
                    'since' => $since?->toDateString(),
                    'days' => $since === null ? null : (int) $since->copy()->startOfDay()->diffInDays(Carbon::today()),
                    'expiry' => $batch->expiry_date === null ? null : Carbon::parse($batch->expiry_date)->toDateString(),
                    'cost' => $cost->format(),
                    'trade' => $tp === null ? null : $trade->format(),
                ];
            }
        }

        // Money: what he handed in (transfers out of his Employee cash), what
        // he holds now, what he owes on his own account, what customers owe.
        $cashAccounts = RetailSellers::cashAccountsOf($seller->id)->get();
        $handedIn = Money::ofTaka((string) (MoneyTransfer::query()->live()
            ->whereIn('from_money_account_id', $cashAccounts->pluck('id'))
            ->whereBetween('transferred_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount') ?: '0'));
        $cashHeld = Money::ofMinor($cashAccounts->sum(fn ($account) => MoneyAvailability::balanceMinor($account)));
        $sellerOwes = Money::zero();
        $customersOwe = Money::zero();

        foreach (SellerSale::query()->where('employee_id', $seller->id)->whereIn('payment', [SellerSale::PAY_SELLER_OWES, SellerSale::PAY_ON_ACCOUNT])->with('invoice')->get() as $open) {
            if ($open->invoice === null) {
                continue;
            }

            $outstanding = $open->invoice->outstanding();

            if ($open->payment === SellerSale::PAY_SELLER_OWES) {
                $sellerOwes = $sellerOwes->plus($outstanding);
            } else {
                $customersOwe = $customersOwe->plus($outstanding);
            }
        }

        $summary = [
            'employee_id' => $seller->id,
            'name' => $seller->fullName(),
            'code' => $seller->employee_code,
            'stock' => $stock === null ? null : ['id' => $stock->id, 'name' => $stock->name, 'is_active' => (bool) $stock->is_active],
            'taken' => ['quantity' => $this->qty($taken['quantity']), 'trade' => $taken['trade']->format(), 'cost' => $taken['cost']->format()],
            'sold' => ['quantity' => $this->qty($sold['quantity']), 'value' => $sold['value']->format(), 'cost' => $sold['cost']->format(), 'margin' => Money::ofMinor($sold['value']->minor - $sold['cost']->minor)->format()],
            'returned' => ['quantity' => $this->qty($returned['quantity']), 'trade' => $returned['trade']->format(), 'cost' => $returned['cost']->format()],
            'holding' => ['quantity' => $this->qty($holding['quantity']), 'cost' => $holding['cost']->format(), 'trade' => $holding['trade']->format()],
            'money' => [
                'collected' => $collected->format(),
                'handed_in' => $handedIn->format(),
                'cash_held' => $cashHeld->format(),
                'cash_held_minor' => $cashHeld->minor,
                'seller_owes' => $sellerOwes->format(),
                'customers_owe' => $customersOwe->format(),
            ],
            'oldest_days' => $ageing === [] ? null : max(array_map(fn ($a) => $a['days'] ?? 0, $ageing)),
        ];

        if (! $detail) {
            return $summary;
        }

        return $summary + [
            'products' => array_values(array_map(fn (array $p) => [
                'product_id' => $p['product_id'],
                'name' => $p['name'],
                'taken' => $this->qty($p['taken']),
                'sold' => $this->qty($p['sold']),
                'returned' => $this->qty($p['returned']),
                'holding' => $this->qty($p['holding']),
            ], $products)),
            'ageing' => $ageing,
            'sheets' => $sheets->map(fn (SellerTransfer $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'kind' => $s->kind,
                'date' => $s->moved_on?->toDateString(),
                'warehouse' => $s->otherWarehouse?->name,
                'items' => $s->lines->map(fn (SellerTransferLine $l) => $l->product?->name.' x '.$this->qty((float) $l->quantity))->implode(', '),
                'trade' => Money::ofTaka(number_format((float) $s->trade_value, 2, '.', ''))->format(),
                'cost' => Money::ofTaka(number_format((float) $s->cost_value, 2, '.', ''))->format(),
                'reason' => $s->reason,
                'reversed' => $s->reversalSummary(),
            ])->values()->all(),
            'sales' => $sales->map(fn (SellerSale $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'date' => $s->sold_on?->toDateString(),
                'sold_to' => $s->sold_to,
                'customer' => $s->customer?->name,
                'payment' => $s->payment,
                'account' => $s->moneyAccount?->name,
                'total' => Money::ofTaka((string) $s->total)->format(),
                'invoice_id' => $s->sales_invoice_id,
                'invoice_number' => $s->invoice?->invoice_number,
                'voided' => $s->invoice?->isReversed() ?? false,
                'outstanding' => $s->invoice === null || $s->payment === SellerSale::PAY_COLLECTED ? null : $s->invoice->outstanding()->format(),
            ])->values()->all(),
        ];
    }

    /** Every seller (anyone with a seller stock) with his period totals. */
    public function all(Carbon $from, Carbon $to): array
    {
        $ids = Warehouse::query()->whereNotNull('seller_employee_id')->pluck('seller_employee_id');

        return Employee::query()->whereIn('id', $ids)->orderBy('first_name')->get()
            ->map(fn (Employee $e) => $this->for($e, $from, $to, detail: false))
            ->values()->all();
    }

    private function qty(float $quantity): string
    {
        $text = number_format($quantity, 4, '.', '');

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}
