<?php

namespace App\Support\Attention;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Needs attention (plan C.4): one ranked list of what is waiting on somebody,
 * gathered from every source this person may see.
 *
 * ⚠⚠ FAILURE-ISOLATED, like BusinessHealthSignals: a source that throws is
 * reported and named in `failed`, and the rest of the list still arrives. The
 * page says which area could not be read rather than showing a silent zero
 * (dashboard ruling 9).
 *
 * ⚠ Nothing here is stored. Every item is computed from the module that owns
 * it, so it cannot disagree with that module's own screen.
 *
 * Ranking: severity first, then the oldest first, then the area.
 */
class NeedsAttention
{
    /** The sidebar badge may be this many seconds old. */
    public const BADGE_SECONDS = 60;

    /** @var list<AttentionSource> */
    private array $sources = [];

    /** @param iterable<AttentionSource> $sources */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->sources[] = $source;
        }
    }

    /** @return list<AttentionSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return list<AttentionSource> the sources this person may see */
    public function sourcesFor(User $user): array
    {
        return array_values(array_filter($this->sources, function (AttentionSource $source) use ($user) {
            try {
                return $source->authorize($user);
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        }));
    }

    /**
     * @return array{items: list<AttentionItem>, failed: list<array{key: string, area: string}>}
     */
    public function for(User $user, int $businessId): array
    {
        $items = [];
        $failed = [];

        foreach ($this->sourcesFor($user) as $source) {
            try {
                foreach ($source->items($user, $businessId) as $item) {
                    $items[] = $item;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = ['key' => $source->key(), 'area' => $source->area()];
            }
        }

        $this->rememberCount($user, $businessId, count($items));

        return ['items' => self::rank($items), 'failed' => $failed];
    }

    /**
     * @param  list<AttentionItem>  $items
     * @return list<AttentionItem>
     */
    public static function rank(array $items): array
    {
        usort($items, function (AttentionItem $a, AttentionItem $b) {
            $bySeverity = (AttentionItem::RANK[$b->severity] ?? 0) <=> (AttentionItem::RANK[$a->severity] ?? 0);

            if ($bySeverity !== 0) {
                return $bySeverity;
            }

            // Oldest first; an item that cannot say when it started goes last.
            $bySince = match (true) {
                $a->since === null && $b->since === null => 0,
                $a->since === null => 1,
                $b->since === null => -1,
                default => $a->since->getTimestamp() <=> $b->since->getTimestamp(),
            };

            return $bySince !== 0 ? $bySince : [$a->area, $a->key] <=> [$b->area, $b->key];
        });

        return $items;
    }

    /**
     * Whether the item is this person's to act on: addressed to them, or to a
     * role they hold in this business.
     *
     * @param  list<string>  $roles
     */
    public static function isMine(AttentionItem $item, User $user, array $roles): bool
    {
        if ($item->ownerUserId !== null) {
            return $item->ownerUserId === (int) $user->id;
        }

        return $item->ownerRole !== null && in_array($item->ownerRole, $roles, true);
    }

    /** Whether orders carry a brand yet (Joy, 11 Oct 2026: brands inside Bor de Guna). */
    public static function brandsKnown(): bool
    {
        try {
            return Schema::hasColumn('sales_orders', 'brand_id');
        } catch (Throwable) {
            return false;
        }
    }

    /** The sidebar badge: the count, at most BADGE_SECONDS old. */
    public function count(User $user, int $businessId): int
    {
        return (int) Cache::remember(self::cacheKey($user, $businessId), self::BADGE_SECONDS, fn () => count($this->for($user, $businessId)['items']));
    }

    public function forgetCount(User $user, int $businessId): void
    {
        Cache::forget(self::cacheKey($user, $businessId));
    }

    private function rememberCount(User $user, int $businessId, int $count): void
    {
        try {
            Cache::put(self::cacheKey($user, $businessId), $count, self::BADGE_SECONDS);
        } catch (Throwable) {
            // A badge that cannot be cached is computed again next time.
        }
    }

    private static function cacheKey(User $user, int $businessId): string
    {
        return "attention:count:b{$businessId}:u{$user->id}";
    }
}
