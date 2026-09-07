<?php

namespace App\Modules\Commerce\Enums;

/**
 * Where a store is in its life.
 *
 * ⚠ `Draft` is the default and it is load-bearing. A store with no theme and no
 * pages must not be reachable on a real hostname, and "not live yet" is a state
 * rather than the absence of one.
 *
 * ⚠ `Archived` is not deletion. A store that stops trading keeps its orders,
 * its customers and its journal lines forever, so the row stays and stops
 * serving.
 */
enum StoreStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Live => 'Live',
            self::Archived => 'Archived',
        };
    }

    /**
     * May the storefront serve this store to the public?
     */
    public function isServable(): bool
    {
        return $this === self::Live;
    }
}
