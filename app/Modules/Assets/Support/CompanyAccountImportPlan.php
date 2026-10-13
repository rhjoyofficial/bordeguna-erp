<?php

namespace App\Modules\Assets\Support;

/**
 * What an accounts sheet WOULD do, worked out completely before anything is
 * written (slice 4). ⚠ One error and nothing is applied.
 */
class CompanyAccountImportPlan
{
    /** @var list<array{line:int, name:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, name:string, message:string}> */
    public array $warnings = [];

    /**
     * Rows that create an account.
     *
     * @var list<array{line:int, name:string, kind:string, data:array<string,mixed>, users:list<int>}>
     */
    public array $creates = [];

    /**
     * Rows that update one, with what changes and who gains access.
     *
     * @var list<array{line:int, id:int, name:string, kind:string, data:array<string,mixed>, changes:array<string,array{0:mixed,1:mixed}>, users:list<int>, new_users:list<string>}>
     */
    public array $updates = [];

    /** Rows that match an account and change nothing. */
    public int $unchanged = 0;

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
}
