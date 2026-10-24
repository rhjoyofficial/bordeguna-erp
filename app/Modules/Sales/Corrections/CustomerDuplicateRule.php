<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\ListDuplicateRule;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 5): "A customer with the phone 01712-345678
 * already exists: Rahim Traders". Same phone (Bangla digits, +88 and the lost
 * leading zero all read the same) or the same name ignoring case and spaces.
 *
 * ⚠ Joy asked for "same name + area"; a customer row has no area field, only
 * a free-text address, so the name alone warns and the sentence shows the
 * address of the one found, which is how the person tells two shops apart.
 * The system rows (walk-in, staff, owner) never count.
 */
class CustomerDuplicateRule extends ListDuplicateRule
{
    public function key(): string
    {
        return 'sales.customer';
    }

    protected function keysOf(array $payload): array
    {
        return ['phone' => DuplicateKeys::phone($payload['phone'] ?? null), 'name' => DuplicateKeys::name($payload['name'] ?? null)];
    }

    protected function rows(int $businessId): Builder
    {
        return Customer::query()->where('business_id', $businessId)->where('is_system', false)
            ->select(['id', 'business_id', 'name', 'phone', 'address', 'is_active', 'created_at']);
    }

    protected function rowKeys(Model $row): array
    {
        /** @var Customer $row */
        return ['phone' => DuplicateKeys::phone($row->phone), 'name' => DuplicateKeys::name($row->name)];
    }

    protected function describe(Model $row, string $matched): string
    {
        /** @var Customer $row */
        $where = $row->address ? " ({$row->address})" : '';

        return $matched === 'phone'
            ? "A customer with the phone {$row->phone} already exists: {$row->name}{$where}"
            : "A customer with the same name already exists: {$row->name}{$where}";
    }

    protected function numberOf(Model $row): string
    {
        return (string) $row->name;
    }

    protected function urlOf(Model $row): ?string
    {
        return route('sales.customers.show', $row->getKey());
    }

    protected function visibleTo(User $viewer): bool
    {
        return $viewer->can('sales.reports.view') || $viewer->can('sales.customers.manage');
    }
}
