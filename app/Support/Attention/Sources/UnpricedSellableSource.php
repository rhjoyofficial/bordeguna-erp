<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Support\Attention\AttentionItem;
use App\Support\Catalogue\UnpricedSellable;

/**
 * Sellable products with no selling price in force (round 3, 16 Oct 2026). It
 * was the classic dashboard's banner; the role dashboards read Needs
 * attention, so it moves here as one row. A product without a price refuses
 * its first sale, hence high.
 */
class UnpricedSellableSource extends Source
{
    public function key(): string
    {
        return 'unpriced_sellable';
    }

    public function area(): string
    {
        return 'stock';
    }

    /** The classic banner's gate, unchanged. */
    protected function permissions(): array
    {
        return ['sales.reports.view', 'sales.prices.manage'];
    }

    public function items(User $user, int $businessId): array
    {
        ['unpriced' => $unpriced, 'sellable' => $sellable] = UnpricedSellable::count();

        if ($unpriced === 0) {
            return [];
        }

        return [
            $this->item('products', AttentionItem::HIGH, 'unpriced_sellable', '{count} of {sellable} sellable products have no selling price in force, so each refuses its first sale.',
                ['count' => $unpriced, 'sellable' => $sellable], '/sales/prices', ['ownerRole' => 'Manager', 'count' => $unpriced]),
        ];
    }
}
