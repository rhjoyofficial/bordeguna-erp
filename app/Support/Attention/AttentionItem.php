<?php

namespace App\Support\Attention;

use Illuminate\Support\Carbon;

/**
 * One thing somebody should act on (plan C.4): how bad it is, whose it is, how
 * long it has waited, one line saying why, and the screen where the fix lives.
 *
 * The reason travels twice: as English, and as a dictionary key with its
 * parameters, so the browser can say it in Bangla (digits, dates and money
 * stay as the server wrote them). The age is never stored: `since` is a fact
 * and the age is computed from it on every read.
 */
final class AttentionItem
{
    public const CRITICAL = 'critical';

    public const HIGH = 'high';

    public const NORMAL = 'normal';

    /** Higher outranks lower. */
    public const RANK = [self::CRITICAL => 3, self::HIGH => 2, self::NORMAL => 1];

    public const AREAS = ['couriers', 'money', 'stock', 'work', 'system'];

    /**
     * @param  array<string, string|int|float>  $params  the {placeholders} of the reason
     * @param  list<array{label: string, label_key: string, url: string, method: string, confirm?: string}>  $actions  extra buttons beyond the link
     */
    public function __construct(
        public readonly string $key,
        public readonly string $source,
        public readonly string $area,
        public readonly string $severity,
        public readonly string $reason,
        public readonly string $reasonKey,
        public readonly array $params,
        public readonly string $url,
        public readonly ?Carbon $since = null,
        public readonly ?string $ownerRole = null,
        public readonly ?int $ownerUserId = null,
        public readonly ?int $count = null,
        public readonly ?int $brandId = null,
        public readonly array $actions = [],
        public readonly bool $brandAware = false,
    ) {}

    /**
     * Whether a brand filter keeps this item. A per-parcel item belongs to its
     * order's brand (or to "No brand"); an item about the whole business (a
     * courier account, the bank, the books) stays under every brand, as the
     * ledger widgets do on the dashboards.
     */
    public function matchesBrand(int|string|null $filter): bool
    {
        if ($filter === null || ! $this->brandAware) {
            return true;
        }

        return $filter === 'none' ? $this->brandId === null : $this->brandId === (int) $filter;
    }

    /** Whole days since it started waiting, or null when the source cannot say. */
    public function ageDays(?Carbon $now = null): ?int
    {
        return $this->since === null ? null : (int) floor($this->since->diffInDays($now ?? Carbon::now(), true));
    }

    /** Fill the English reason's {placeholders}. */
    public static function sentence(string $template, array $params): string
    {
        foreach ($params as $name => $value) {
            $template = str_replace('{'.$name.'}', (string) $value, $template);
        }

        return $template;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'source' => $this->source,
            'area' => $this->area,
            'severity' => $this->severity,
            'reason' => $this->reason,
            'reason_key' => $this->reasonKey,
            'params' => (object) $this->params,
            'url' => $this->url,
            'since' => $this->since?->toIso8601String(),
            'age_days' => $this->ageDays(),
            'owner_role' => $this->ownerRole,
            'owner_user_id' => $this->ownerUserId,
            'count' => $this->count,
            'brand_id' => $this->brandId,
            'brand_aware' => $this->brandAware,
            'actions' => $this->actions,
        ];
    }
}
