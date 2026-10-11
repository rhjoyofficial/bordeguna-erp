<?php

namespace App\Modules\Sales\Couriers\Providers;

use InvalidArgumentException;

/**
 * Every courier the ERP knows about, in the order the screen lists them.
 *
 * ⚠ Adding a courier is a class here (its fields, its official URLs, its driver),
 * reviewed in code, never a URL typed on a screen.
 */
class CourierProviderRegistry
{
    /**
     * @return array<string, CourierProviderDefinition>
     */
    public function all(): array
    {
        $providers = [
            new PathaoProvider,
            new CarryBeeProvider,
            new NotYetConnectedProvider('steadfast', 'Steadfast'),
            new NotYetConnectedProvider('redx', 'RedX'),
            new NotYetConnectedProvider('paperfly', 'Paperfly'),
        ];

        $keyed = [];

        foreach ($providers as $provider) {
            $keyed[$provider->code()] = $provider;
        }

        return $keyed;
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->all());
    }

    public function get(string $code): CourierProviderDefinition
    {
        return $this->all()[$code] ?? throw new InvalidArgumentException("Unknown courier provider [{$code}].");
    }

    /**
     * @return list<string>
     */
    public function connectedCodes(): array
    {
        return array_values(array_map(fn (CourierProviderDefinition $p) => $p->code(), array_filter($this->all(), fn (CourierProviderDefinition $p) => $p->connected())));
    }
}
