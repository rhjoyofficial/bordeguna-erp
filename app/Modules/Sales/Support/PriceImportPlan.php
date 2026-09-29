<?php

namespace App\Modules\Sales\Support;

/**
 * What a price import WOULD do, worked out completely before anything is
 * written.
 *
 * ⚠⚠ The plan is the review. Joy's standing rule is that a preview is shown
 * before any financially meaningful write, so this object is the importer's
 * primary product and applying it is a second, explicit act.
 *
 * ⚠ A plan carrying even one error is never applied. It is not a list of rows
 * to skip; it is a statement that the file is not yet fit to import. Half a
 * price list is worse than none: the products that imported would sell, the
 * ones that did not would fail at the counter, and nobody would know which was
 * which until a customer was standing there.
 */
class PriceImportPlan
{
    /** @var list<array{line:int, sku:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, sku:string, message:string}> */
    public array $warnings = [];

    /** @var list<array{line:int, sku:string, product:string, group:string, price:string, minQuantity:string, productId:int, priceGroupId:int}> */
    public array $creates = [];

    /**
     * Rules currently in force that a new row supersedes: they are CLOSED the
     * day before the new one starts, never edited and never deleted.
     *
     * @var list<array{id:int, sku:string, group:string, from:string, was:string, becomes:string, closesOn:string}>
     */
    public array $supersedes = [];

    /** @var list<array{sku:string, group:string, price:string}> */
    public array $unchanged = [];

    /**
     * Products whose MRP the file sets or changes (29 Sep 2026). The MRP is a
     * product field, not an effective-dated rule, so it is overwritten.
     *
     * @var list<array{line:int, sku:string, product:string, productId:int, was:?string, becomes:string}>
     */
    public array $mrpChanges = [];

    /** Columns in the file this importer does not read. @var list<string> */
    public array $ignoredColumns = [];

    /**
     * Products in this business the file prices nothing for.
     *
     * ⚠ Reported so the number is never a surprise. A product with no price
     * cannot be sold at all - `ResolveSellingPrice` returns null and the sale
     * throws - so this list is the answer to "what still cannot be sold after
     * this import".
     *
     * @var list<string>
     */
    public array $stillUnpriced = [];

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

    public function wouldChangeAnything(): bool
    {
        return $this->creates !== [] || $this->mrpChanges !== [];
    }
}
