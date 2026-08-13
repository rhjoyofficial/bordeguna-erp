<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the goods sold in a period actually cost.
 *
 * ⚠⚠ DERIVED THE WAY THE RECONCILIATION ALREADY DERIVES IT (C-IVL-5):
 *
 *     COGS = Σ invoice line `cost_released` − Σ return line `cogs_relief`
 *
 * character for character what `SubledgerReconciliation::reconcileCostOfGoodsSold()`
 * compares against the COGS control account. Inventing a second expression -
 * say, valuing outward stock movements - would produce a report that disagrees
 * with the ledger for reasons nobody could explain, and this project has
 * already opened one seam by implementing "what is this worth" twice.
 *
 * ⚠ So why derive at all, rather than just read the account balance? Because
 * the ACCOUNT gives one number and no breakdown. Only the documents can answer
 * "which products, which brand, what margin". The account balance is then shown
 * beside the derived total as the tie-out: one number with detail, checked
 * against one number without.
 *
 * ⚠⚠ FOR A PERIOD, NOT AS AT A DATE. The mirror-image of the ageing rule. "What
 * did the goods cost" only has meaning across a range, and a cost of goods sold
 * "as at" a day is a category error.
 *
 * ⚠ A return line carries NO product of its own - it reaches one through its
 * delivery line. Attributing relief without that hop would silently drop every
 * return out of the per-product breakdown while leaving it in the total, and
 * the two would disagree by exactly the returns.
 *
 * ⚠ Revenue and margin are shown only where they exist. Until `product_prices`
 * is populated nothing has been sold, so this reports cost alone and gains the
 * margin column for free the day prices are loaded.
 */
final class CostOfGoodsSold
{
    /**
     * @param  Collection<int, CostOfGoodsSoldRow>  $rows
     */
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $rows,
        public readonly ?Money $controlBalance,
        public readonly ?string $controlAccountLabel,
    ) {}

    public static function forPeriod(Carbon $from, Carbon $to): self
    {
        $start = $from->toDateString();
        $end = $to->toDateString();

        $sold = SalesInvoiceLine::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->whereDate('sales_invoices.invoice_date', '>=', $start)
            ->whereDate('sales_invoices.invoice_date', '<=', $end)
            ->get([
                'sales_invoice_lines.product_id',
                'sales_invoice_lines.quantity',
                'sales_invoice_lines.cost_released',
                'sales_invoice_lines.line_net',
            ]);

        // ⚠ Through the DELIVERY line, which is where a return's product lives.
        $returned = SalesReturnLine::query()
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->join('delivery_lines', 'delivery_lines.id', '=', 'sales_return_lines.delivery_line_id')
            ->whereDate('sales_returns.returned_at', '>=', $start)
            ->whereDate('sales_returns.returned_at', '<=', $end)
            ->get([
                'delivery_lines.product_id',
                'sales_return_lines.quantity',
                'sales_return_lines.cogs_relief',
                'sales_return_lines.credit_net',
            ]);

        $products = Product::query()->with('brand')->get()->keyBy('id');

        $byProduct = [];

        foreach ($sold as $line) {
            $id = (int) $line->product_id;
            $byProduct[$id] ??= self::blank();
            $byProduct[$id]['quantity'] += (float) $line->quantity;
            $byProduct[$id]['cost'] = $byProduct[$id]['cost']->plus(Money::ofTaka((string) $line->cost_released));
            $byProduct[$id]['revenue'] = $byProduct[$id]['revenue']->plus(Money::ofTaka((string) $line->line_net));
        }

        foreach ($returned as $line) {
            $id = (int) $line->product_id;
            $byProduct[$id] ??= self::blank();
            $byProduct[$id]['quantity'] -= (float) $line->quantity;
            $byProduct[$id]['cost'] = $byProduct[$id]['cost']->plus(Money::ofTaka((string) $line->cogs_relief)->negated());
            $byProduct[$id]['revenue'] = $byProduct[$id]['revenue']->plus(Money::ofTaka((string) $line->credit_net)->negated());
        }

        $rows = collect($byProduct)
            ->map(function (array $totals, int $productId) use ($products) {
                $product = $products[$productId] ?? null;

                return new CostOfGoodsSoldRow(
                    productId: $productId,
                    productSku: $product?->sku ?? "id {$productId}",
                    productName: $product?->name ?? "id {$productId}",
                    brand: $product?->brand?->name,
                    quantity: number_format($totals['quantity'], 4, '.', ''),
                    cost: $totals['cost'],
                    revenue: $totals['revenue'],
                );
            })
            // ⚠ A product whose sales and returns cancel exactly is dropped, but
            // one that nets to zero cost while still moving quantity is NOT:
            // that is a real finding about cost, not an empty row.
            ->reject(fn (CostOfGoodsSoldRow $row) => $row->isEmpty())
            ->sortByDesc(fn (CostOfGoodsSoldRow $row) => $row->cost->minor)
            ->values();

        $account = AccountMapping::forKeyOn('sales.cost_of_goods_sold', $to)?->account;

        return new self(
            from: $from,
            to: $to,
            rows: $rows,
            controlBalance: $account === null ? null : self::controlMovement($account->id, $from, $to),
            controlAccountLabel: $account?->label(),
        );
    }

    public function totalCost(): Money
    {
        return Money::sum($this->rows->map(fn (CostOfGoodsSoldRow $row) => $row->cost));
    }

    public function totalRevenue(): Money
    {
        return Money::sum($this->rows->map(fn (CostOfGoodsSoldRow $row) => $row->revenue));
    }

    public function grossProfit(): Money
    {
        return Money::ofMinor($this->totalRevenue()->minor - $this->totalCost()->minor);
    }

    public function tiesOut(): bool
    {
        return $this->controlBalance === null
            || $this->controlBalance->minor === $this->totalCost()->minor;
    }

    public function difference(): Money
    {
        return $this->controlBalance === null
            ? Money::zero()
            : Money::ofMinor($this->controlBalance->minor - $this->totalCost()->minor);
    }

    public function hasControlAccount(): bool
    {
        return $this->controlBalance !== null;
    }

    /**
     * C-IVL-7. Joy's A6: revenue, COGS and gross profit BY BRAND, at report
     * level, with no schema or ledger change.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byBrand(): Collection
    {
        return $this->rows
            ->groupBy(fn (CostOfGoodsSoldRow $row) => $row->brand ?? 'Unbranded')
            ->map(function (Collection $group, string $brand) {
                $cost = Money::sum($group->map(fn (CostOfGoodsSoldRow $row) => $row->cost));
                $revenue = Money::sum($group->map(fn (CostOfGoodsSoldRow $row) => $row->revenue));

                return [
                    'brand' => $brand,
                    'cost' => $cost,
                    'revenue' => $revenue,
                    'gross_profit' => Money::ofMinor($revenue->minor - $cost->minor),
                ];
            })
            ->sortByDesc(fn (array $entry) => $entry['cost']->minor)
            ->values();
    }

    /**
     * @return array{quantity:float, cost:Money, revenue:Money}
     */
    private static function blank(): array
    {
        return ['quantity' => 0.0, 'cost' => Money::zero(), 'revenue' => Money::zero()];
    }

    /**
     * ⚠ The MOVEMENT in the period, not the balance at the end of it. COGS is an
     * expense account: its running balance carries every prior period, and
     * comparing that with one period's documents would disagree by all of
     * history.
     */
    private static function controlMovement(int $accountId, Carbon $from, Carbon $to): Money
    {
        return Money::ofMinor((int) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereDate('journals.journal_date', '>=', $from->toDateString())
            ->whereDate('journals.journal_date', '<=', $to->toDateString())
            ->sum('journal_lines.amount_minor'));
    }
}
