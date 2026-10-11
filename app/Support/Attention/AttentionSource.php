<?php

namespace App\Support\Attention;

use App\Models\User;

/**
 * One feed into Needs attention. Each source answers on the server who may see
 * it (its own permission, never borrowed from the page) and what is waiting.
 *
 * ⚠ A source may throw; NeedsAttention reports it and carries on without it.
 */
interface AttentionSource
{
    /** Stable, used in the page's "could not be read" note and in tests. */
    public function key(): string;

    /** couriers, money, stock, work or system. */
    public function area(): string;

    public function authorize(User $user): bool;

    /** @return list<AttentionItem> */
    public function items(User $user, int $businessId): array;
}
