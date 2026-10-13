<?php

namespace App\Modules\Assets\Support;

/**
 * What a things sheet WOULD do, worked out completely before anything is
 * written. ⚠ One error and nothing is applied: a sheet half-applied leaves
 * nobody sure which things are on the register.
 */
class AssetImportPlan
{
    /** @var list<array{line:int, tag:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, tag:string, message:string}> */
    public array $warnings = [];

    /**
     * Rows that register something: `copies` tagged things (1 for a counted
     * group, whose pieces are in `attributes.quantity`), then a hand-over to
     * `holder` or `party` when the row names one.
     *
     * @var list<array{line:int, name:string, category_id:int, category:string, tracking:string, copies:int, pieces:int, attributes:array<string,mixed>, holder:array{employee_id:int, label:string}|null, party:array{party_id:int, label:string}|null, moved_on:string}>
     */
    public array $creates = [];

    /**
     * Rows that correct a thing already on the register.
     *
     * @var list<array{line:int, item_id:int, tag:string, name:string, attributes:array<string,mixed>, changes:array<string, array{0:string, 1:string}>}>
     */
    public array $updates = [];

    public function addError(int $line, string $tag, string $message): void
    {
        $this->errors[] = ['line' => $line, 'tag' => $tag, 'message' => $message];
    }

    public function addWarning(int $line, string $tag, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'tag' => $tag, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** How many tags the creates would print. */
    public function tagsToCreate(): int
    {
        return array_sum(array_column($this->creates, 'copies'));
    }
}
