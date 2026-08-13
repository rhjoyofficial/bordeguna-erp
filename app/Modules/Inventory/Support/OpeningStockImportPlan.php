<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Support\Money;

/**
 * What an opening stock import WOULD do, worked out completely before anything
 * is written.
 *
 * ⚠⚠ THE PLAN IS THE REVIEW, and here it matters more than anywhere else in the
 * system. Opening stock is written ONCE PER WAREHOUSE for the life of the
 * business, its unit costs decide every margin reported until that stock is
 * sold through, and correcting it afterwards means an inventory adjustment
 * against the real company's books rather than a re-import.
 *
 * ⚠ A plan carrying one error is never applied. Not "skip the bad rows" - a
 * warehouse whose count is 90% imported is a warehouse nobody can ever
 * reconcile, and the document cannot be written again to fix it.
 */
class OpeningStockImportPlan
{
    /** @var list<array{line:int, sku:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, sku:string, message:string}> */
    public array $warnings = [];

    /**
     * One entry per warehouse, each carrying the lines that will become its
     * single opening stock document.
     *
     * @var array<int, array{
     *     warehouseId:int,
     *     warehouseName:string,
     *     warehouseCode:string,
     *     lines: list<array{
     *         line:int, sku:string, productId:int, productName:string,
     *         quantity:string, unitCost:string, value:Money,
     *         batchNumber:?string, expiryDate:?string
     *     }>
     * }>
     */
    public array $warehouses = [];

    public function addError(int $line, string $sku, string $message): void
    {
        $this->errors[] = ['line' => $line, 'sku' => $sku, 'message' => $message];
    }

    public function addWarning(int $line, string $sku, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'sku' => $sku, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function lineCount(): int
    {
        return array_sum(array_map(fn (array $w) => count($w['lines']), $this->warehouses));
    }

    /**
     * ⚠⚠ THE NUMBER THE ACCOUNTANT MUST AGREE WITH. This total is debited to
     * Stock on Hand and credited to Opening Balance Equity, so it has to equal
     * the stock figure on the opening trial balance EXACTLY. If the two differ,
     * Opening Balance Equity will not go to zero when every opening figure is
     * in - and that account reaching zero is the only check that catches an
     * opening position which balances while being wrong.
     */
    public function totalValue(): Money
    {
        $values = [];

        foreach ($this->warehouses as $warehouse) {
            foreach ($warehouse['lines'] as $line) {
                $values[] = $line['value'];
            }
        }

        return Money::sum($values);
    }

    public function valueFor(int $warehouseId): Money
    {
        return Money::sum(array_map(
            fn (array $line) => $line['value'],
            $this->warehouses[$warehouseId]['lines'] ?? [],
        ));
    }

    public function wouldChangeAnything(): bool
    {
        return $this->warehouses !== [];
    }
}
