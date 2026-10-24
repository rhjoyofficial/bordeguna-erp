<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\ListDuplicateRule;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 5 "Product: same name"): a new product named
 * like one already in the catalogue, or whose SKU differs only by case, spaces
 * or dashes ("HONEY-500" and "honey 500"), asks "Possible duplicate" first.
 * The exact same SKU stays a hard refusal (the unique rule), as before.
 */
class ProductDuplicateRule extends ListDuplicateRule
{
    public function key(): string
    {
        return 'inventory.product';
    }

    protected function keysOf(array $payload): array
    {
        return ['name' => DuplicateKeys::name($payload['name'] ?? null), 'sku' => DuplicateKeys::code($payload['sku'] ?? null)];
    }

    protected function rows(int $businessId): Builder
    {
        return Product::query()->where('business_id', $businessId)
            ->select(['id', 'business_id', 'sku', 'name', 'is_active', 'created_at']);
    }

    protected function rowKeys(Model $row): array
    {
        /** @var Product $row */
        return ['name' => DuplicateKeys::name($row->name), 'sku' => DuplicateKeys::code($row->sku)];
    }

    protected function describe(Model $row, string $matched): string
    {
        /** @var Product $row */
        return $matched === 'sku'
            ? "A product with almost the same SKU already exists: {$row->name} ({$row->sku})"
            : "A product with the same name already exists: {$row->name} ({$row->sku})";
    }

    protected function numberOf(Model $row): string
    {
        return (string) $row->sku;
    }

    protected function urlOf(Model $row): ?string
    {
        return route('inventory.products.show', $row->getKey());
    }

    protected function visibleTo(User $viewer): bool
    {
        return $viewer->can('inventory.reports.view') || $viewer->can('inventory.catalog.manage');
    }
}
