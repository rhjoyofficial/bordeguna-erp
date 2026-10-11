<?php

namespace App\Modules\Sales\Couriers\Providers;

/**
 * A courier the business may use one day (Steadfast, RedX, Paperfly) whose API
 * has not been read and verified yet. Listed so the screen names it as "not yet
 * connected"; no account can be created for it, because there is no verified
 * URL to send credentials to and nothing that knows its fields.
 */
class NotYetConnectedProvider extends CourierProviderDefinition
{
    public function __construct(private readonly string $code, private readonly string $name) {}

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }
}
