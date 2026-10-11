<?php

namespace App\Support\Realtime;

/**
 * What this request (or queue job) already announced, so one (kind, id) goes out
 * once however many times the row is saved. Bound as a SCOPED singleton: Laravel
 * resets it per request and per queue job, so a long-running worker never
 * remembers yesterday's sale.
 *
 * ⚠ Stock is the reason it exists: a 30-line sale moves 30 batches of four
 * products and must ring four `stock` events, not thirty.
 */
final class RecordChangeBuffer
{
    /** @var array<string, true> */
    private array $sent = [];

    /** True the first time a key is seen in this scope, false after. */
    public function first(string $key): bool
    {
        if (isset($this->sent[$key])) {
            return false;
        }

        $this->sent[$key] = true;

        return true;
    }
}
