<?php

namespace App\Modules\Sales\Couriers\Providers;

use App\Modules\Sales\Couriers\Drivers\CarryBeeDriver;

/**
 * CarryBee Developers' API v2.0, verified from merchant.carrybee.com > API
 * Documentation on 11 Oct 2026. Three headers on every request, no token.
 * Credentials are per business AND per environment.
 */
class CarryBeeProvider extends CourierProviderDefinition
{
    public function code(): string
    {
        return 'carrybee';
    }

    public function name(): string
    {
        return 'CarryBee';
    }

    public function driverClass(): ?string
    {
        return CarryBeeDriver::class;
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false, 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
            ['key' => 'client_context', 'label' => 'Client context', 'secret' => true, 'required' => true],
        ];
    }

    public function optionFields(): array
    {
        return [
            ['key' => 'delivery_type', 'label' => 'Delivery type', 'type' => 'select', 'default' => 1, 'choices' => ['1' => 'Normal', '2' => 'Express']],
            ['key' => 'product_type', 'label' => 'Product type', 'type' => 'select', 'default' => 1, 'choices' => ['1' => 'Parcel', '2' => 'Book', '3' => 'Document']],
            ['key' => 'cod_percent', 'label' => 'COD fee (%)', 'type' => 'number', 'default' => 0.5, 'min' => 0, 'max' => 10, 'step' => 0.01],
            ['key' => 'max_weight_kg', 'label' => 'Highest weight (kg)', 'type' => 'number', 'default' => 25, 'min' => 0.001, 'max' => 25, 'step' => 0.001],
        ];
    }

    public function baseUrls(): array
    {
        return [
            self::SANDBOX => ['https://sandbox.carrybee.com' => 'CarryBee sandbox (sandbox.carrybee.com)'],
            self::LIVE => ['https://developers.carrybee.com' => 'CarryBee live (developers.carrybee.com)'],
        ];
    }

    public function capabilities(): array
    {
        return [
            'test_connection' => true,
            'stores' => true,
            'locations' => true,
            'book' => true,
            'bulk_book' => true,
            // No rate endpoint in the public API; the fee comes back on booking.
            'quote' => false,
            'cancel' => true,
            'webhook' => true,
            'status_lookup' => true,
        ];
    }
}
