<?php

namespace App\Modules\Inventory\Support;

/**
 * What one run of `catalogue:migrate` has decided, before anything is written.
 *
 * Renames and retires are the migration's own work; creates and updates are
 * listed for the operator but executed by the existing catalogue importer,
 * which runs after the renames have made the file's SKUs match the live ones.
 */
class CatalogueMigrationPlan
{
    /** @var list<array{line:int, sku:string, product_id:int, name:string}> */
    public array $keeps = [];

    /** @var list<array{line:int, old_sku:string, new_sku:string, product_id:int, name:string, how:string}> */
    public array $renames = [];

    /** @var list<array{line:int, sku:string, name:string}> */
    public array $creates = [];

    /** @var list<array{line:int, sku:string, name:string, reason:string}> */
    public array $skips = [];

    /** @var list<array{sku:string, product_id:int, name:string, was_active:bool}> */
    public array $retires = [];

    /** @var list<array{line:int, sku:string, message:string}> */
    public array $errors = [];

    /** @var list<array{line:int, sku:string, message:string}> */
    public array $warnings = [];

    /**
     * The file rows the import planner will receive after the renames: every
     * row except the skips.
     *
     * @var list<array{line:int, values:array<string,string>}>
     */
    public array $importRows = [];

    public function addError(int $line, string $sku, string $message): void
    {
        $this->errors[] = compact('line', 'sku', 'message');
    }

    public function addWarning(int $line, string $sku, string $message): void
    {
        $this->warnings[] = compact('line', 'sku', 'message');
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function wouldChangeAnything(): bool
    {
        return $this->renames !== []
            || $this->retiresNeedingWrite() !== []
            || $this->creates !== [];
    }

    /**
     * Retires that actually flip a flag. A product that is already inactive is
     * still listed as retired - the operator should see the whole disposition -
     * but it is not a write.
     *
     * @return list<array{sku:string, product_id:int, name:string, was_active:bool}>
     */
    public function retiresNeedingWrite(): array
    {
        return array_values(array_filter($this->retires, fn (array $r) => $r['was_active']));
    }

    /**
     * The audit payload stored on --apply: enough to explain, and mechanically
     * reverse, every identity change this run made.
     *
     * @return array<string, mixed>
     */
    public function auditPayload(): array
    {
        return [
            'renames' => array_map(fn (array $r) => [
                'product_id' => $r['product_id'],
                'old_sku' => $r['old_sku'],
                'new_sku' => $r['new_sku'],
                'how' => $r['how'],
            ], $this->renames),
            'retires' => array_map(fn (array $r) => [
                'product_id' => $r['product_id'],
                'sku' => $r['sku'],
                'was_active' => $r['was_active'],
            ], $this->retires),
            'keeps' => array_map(fn (array $k) => $k['sku'], $this->keeps),
            'creates' => array_map(fn (array $c) => $c['sku'], $this->creates),
            'skips' => array_map(fn (array $s) => $s['sku'], $this->skips),
        ];
    }
}
