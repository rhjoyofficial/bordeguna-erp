<?php

namespace App\Modules\Inventory\Support;

/**
 * What an import WOULD do, worked out completely before anything is written.
 *
 * ⚠⚠ The plan is the review. Joy's rule for the whole onboarding exercise is
 * that the file is examined before anything is generated, so the importer's
 * primary product is this object - applying it is a second, explicit act.
 *
 * ⚠ A plan carrying even one error is never applied. It is not a list of rows
 * to skip; it is a statement that the file is not yet fit to import.
 */
class CatalogueImportPlan
{
    /** @var list<array{line:int, sku:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, sku:string, message:string}> */
    public array $warnings = [];

    /** @var list<array{line:int, sku:string, name:string, attributes:array<string,mixed>, categoryKey:?string, unitKey:string}> */
    public array $creates = [];

    /** @var list<array{line:int, sku:string, name:string, id:int, attributes:array<string,mixed>, categoryKey:?string, unitKey:string, changes:array<string,array{0:mixed,1:mixed}>}> */
    public array $updates = [];

    /** @var list<array{line:int, sku:string, name:string}> */
    public array $unchanged = [];

    /** @var array<string, string> slug => name */
    public array $newCategories = [];

    /** @var array<string, array{name:string, abbreviation:string}> */
    public array $newUnits = [];

    /**
     * Products already in this business that the file says nothing about.
     * ⚠ C-CAT-1: reported so the number is never a surprise, and otherwise left
     * completely alone. The file is a statement about the products it contains,
     * not a claim to be the whole catalogue.
     *
     * @var list<string>
     */
    public array $absentFromFile = [];

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
        return $this->creates !== [] || $this->updates !== [];
    }
}
