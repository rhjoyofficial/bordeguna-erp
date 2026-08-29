<?php

namespace App\Modules\Crm\Support;

/**
 * What a phone book import WOULD do, worked out completely before anything is
 * written.
 *
 * ⚠⚠ The plan IS the review. Joy's standing rule is that a file is examined
 * before anything is generated, so the importer's primary product is this
 * object; applying it is a second, explicit act.
 *
 * ⚠ A plan carrying even one error is never applied. It is not a list of rows to
 * skip: it is a statement that the file is not yet fit to import.
 */
class ContactImportPlan
{
    /** @var list<array{line:int, name:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, name:string, message:string}> */
    public array $warnings = [];

    /** @var list<array{line:int, name:string, phone:string, attributes:array<string,mixed>}> */
    public array $creates = [];

    /** @var list<array{line:int, name:string, phone:string, id:int, attributes:array<string,mixed>, changes:array<string,array{0:mixed,1:mixed}>}> */
    public array $updates = [];

    /** @var list<array{line:int, name:string, phone:string}> */
    public array $unchanged = [];

    /**
     * ⚠⚠ Contacts already in the phone book that this file says nothing about.
     * Reported so the number is never a surprise, and otherwise left completely
     * alone: not deactivated, not deleted. A file is a statement about the
     * people it contains, not a claim to be the whole book.
     */
    public int $absentFromFile = 0;

    public function addError(int $line, string $name, string $message): void
    {
        $this->errors[] = ['line' => $line, 'name' => $name, 'message' => $message];
    }

    public function addWarning(int $line, string $name, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'name' => $name, 'message' => $message];
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
