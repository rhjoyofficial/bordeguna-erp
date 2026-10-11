<?php

namespace App\Modules\Sales\Couriers\Drivers;

/**
 * The answer to "do these credentials work". A failure carries the reason in a
 * sentence a person can act on, never a secret.
 */
final class ConnectionResult
{
    /**
     * @param  list<array{id:string,name:string,active:bool}>  $stores
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $stores = [],
        public readonly ?int $cityCount = null,
    ) {}

    /**
     * @param  list<array{id:string,name:string,active:bool}>  $stores
     */
    public static function success(array $stores, ?int $cityCount): self
    {
        $active = count(array_filter($stores, fn ($s) => $s['active']));

        return new self(true, sprintf('Connected. %d pickup store(s), %d active; %s cities.', count($stores), $active, $cityCount ?? 'unknown number of'), $stores, $cityCount);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
