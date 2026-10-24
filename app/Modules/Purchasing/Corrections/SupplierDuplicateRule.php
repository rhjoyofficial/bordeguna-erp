<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\ListDuplicateRule;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 5): "A supplier with the phone ... already
 * exists". Same phone or the same name ignoring case, spaces and a legal
 * suffix ("Ltd", "Traders" stays). A warning with Save anyway, never a block:
 * the supplier `code` stays the only hard rule.
 */
class SupplierDuplicateRule extends ListDuplicateRule
{
    public function key(): string
    {
        return 'purchasing.supplier';
    }

    protected function keysOf(array $payload): array
    {
        return ['phone' => DuplicateKeys::phone($payload['phone'] ?? null), 'name' => DuplicateKeys::name($payload['name'] ?? null)];
    }

    protected function rows(int $businessId): Builder
    {
        return Supplier::query()->where('business_id', $businessId)
            ->select(['id', 'business_id', 'code', 'name', 'phone', 'is_active', 'created_at']);
    }

    protected function rowKeys(Model $row): array
    {
        /** @var Supplier $row */
        return ['phone' => DuplicateKeys::phone($row->phone), 'name' => DuplicateKeys::name($row->name)];
    }

    protected function describe(Model $row, string $matched): string
    {
        /** @var Supplier $row */
        return $matched === 'phone'
            ? "A supplier with the phone {$row->phone} already exists: {$row->name}"
            : "A supplier with the same name already exists: {$row->name}";
    }

    protected function numberOf(Model $row): string
    {
        return (string) ($row->code ?: $row->name);
    }

    protected function urlOf(Model $row): ?string
    {
        return route('purchasing.suppliers.show', $row->getKey());
    }

    protected function visibleTo(User $viewer): bool
    {
        return $viewer->can('purchasing.reports.view') || $viewer->can('purchasing.suppliers.manage');
    }
}
