<?php

namespace App\Modules\Inventory\Support;

/**
 * What a weights sheet WOULD do, worked out completely before anything is
 * written. ⚠ One error and nothing is applied: a sheet half-applied leaves
 * nobody sure which weights are new.
 */
class ProductWeightImportPlan
{
    /** @var list<array{line:int, sku:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, id:int, sku:string, name:string, from:int|null, to:int}> */
    public array $changes = [];

    /** Rows whose weight is already what the sheet says. */
    public int $unchanged = 0;

    /**
     * Rows left blank: the weight stays as it is.
     *
     * @var list<array{line:int, sku:string}>
     */
    public array $blank = [];

    public function addError(int $line, string $sku, string $message): void
    {
        $this->errors[] = ['line' => $line, 'sku' => $sku, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
