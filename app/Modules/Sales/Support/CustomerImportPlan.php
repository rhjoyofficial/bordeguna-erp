<?php

namespace App\Modules\Sales\Support;

/**
 * What a customer import WOULD do, worked out completely before anything is
 * written.
 *
 * ⚠⚠ The plan IS the review. Joy's rule for the whole onboarding exercise is
 * that a file is examined before anything is generated, so the importer's
 * primary product is this object; applying it is a second, explicit act.
 *
 * ⚠ A plan carrying even one error is never applied. It is not a list of rows
 * to skip: it is a statement that the file is not yet fit to import.
 */
class CustomerImportPlan
{
    /** @var list<array{line:int, code:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, code:string, message:string}> */
    public array $warnings = [];

    /** @var list<array{line:int, code:string, name:string, attributes:array<string,mixed>}> */
    public array $creates = [];

    /** @var list<array{line:int, code:string, name:string, id:int, attributes:array<string,mixed>, changes:array<string,array{0:mixed,1:mixed}>}> */
    public array $updates = [];

    /** @var list<array{line:int, code:string, name:string}> */
    public array $unchanged = [];

    /**
     * Customers already in this business that the file says nothing about.
     *
     * ⚠⚠ Reported so the number is never a surprise, and otherwise left
     * COMPLETELY alone: not deactivated, not deleted. The file is a statement
     * about the customers it contains, not a claim to be the whole list. A
     * customer with invoices behind them must never disappear because somebody
     * imported a shorter spreadsheet.
     *
     * @var list<string>
     */
    public array $absentFromFile = [];

    public function addError(int $line, string $code, string $message): void
    {
        $this->errors[] = ['line' => $line, 'code' => $code, 'message' => $message];
    }

    public function addWarning(int $line, string $code, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'code' => $code, 'message' => $message];
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
