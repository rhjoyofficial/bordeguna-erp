<?php

namespace App\Modules\Sales\Couriers\Tracking;

use App\Modules\Sales\Couriers\Enums\CourierStatus;

/**
 * One raw word from a courier, read: our status for it, or that it is about
 * payment, or that it changes nothing, or that we do not know it.
 */
final class StatusWord
{
    public const STATUS = 'status';

    public const PAYMENT = 'payment';

    public const IGNORE = 'ignore';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $raw,
        public readonly string $slug,
        public readonly string $kind,
        public readonly ?CourierStatus $status = null,
    ) {}

    /**
     * "order.at-the-sorting-hub", "At_the_Sorting_HUB" and "At the sorting hub"
     * are the same word.
     */
    public static function slug(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/^order[._]/', '', $slug) ?? $slug;

        return trim(preg_replace('/[^a-z0-9]+/', '_', $slug) ?? $slug, '_');
    }

    /**
     * @param  array<string, string>  $map  slug => a CourierStatus value, PAYMENT or IGNORE
     */
    public static function read(string $raw, array $map): self
    {
        $slug = self::slug($raw);
        $target = $map[$slug] ?? null;

        return match (true) {
            $target === null => new self($raw, $slug, self::UNKNOWN),
            $target === self::PAYMENT => new self($raw, $slug, self::PAYMENT),
            $target === self::IGNORE => new self($raw, $slug, self::IGNORE),
            default => new self($raw, $slug, self::STATUS, CourierStatus::from($target)),
        };
    }

    public function known(): bool
    {
        return $this->kind !== self::UNKNOWN;
    }
}
