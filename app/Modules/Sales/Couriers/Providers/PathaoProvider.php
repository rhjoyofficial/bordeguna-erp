<?php

namespace App\Modules\Sales\Couriers\Providers;

use App\Modules\Sales\Couriers\Drivers\PathaoDriver;

/**
 * Pathao Courier merchant API (aladdin), verified from merchant.pathao.com >
 * Developer API on 11 Oct 2026. OAuth 2.0 password grant: the merchant's own
 * login email and password travel with the client id and secret.
 */
class PathaoProvider extends CourierProviderDefinition
{
    public function code(): string
    {
        return 'pathao';
    }

    public function name(): string
    {
        return 'Pathao';
    }

    public function driverClass(): ?string
    {
        return PathaoDriver::class;
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false, 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
            ['key' => 'username', 'label' => 'Merchant login email', 'secret' => false, 'required' => true, 'hint' => 'The email used to sign in to merchant.pathao.com.'],
            ['key' => 'password', 'label' => 'Merchant login password', 'secret' => true, 'required' => true],
        ];
    }

    public function optionFields(): array
    {
        return [
            ['key' => 'delivery_type', 'label' => 'Delivery type', 'type' => 'select', 'default' => 48, 'choices' => ['48' => 'Normal delivery', '12' => 'On demand delivery']],
            ['key' => 'item_type', 'label' => 'Item type', 'type' => 'select', 'default' => 2, 'choices' => ['2' => 'Parcel', '1' => 'Document']],
            ['key' => 'cod_percent', 'label' => 'COD fee (%)', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 10, 'step' => 0.01],
            ['key' => 'min_weight_kg', 'label' => 'Lowest weight (kg)', 'type' => 'number', 'default' => 0.5, 'min' => 0.5, 'max' => 10, 'step' => 0.01],
            ['key' => 'max_weight_kg', 'label' => 'Highest weight (kg)', 'type' => 'number', 'default' => 10, 'min' => 0.5, 'max' => 10, 'step' => 0.01],
        ];
    }

    public function baseUrls(): array
    {
        return [
            self::SANDBOX => ['https://courier-api-sandbox.pathao.com' => 'Pathao sandbox (courier-api-sandbox.pathao.com)'],
            self::LIVE => ['https://api-hermes.pathao.com' => 'Pathao live (api-hermes.pathao.com)'],
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
            // merchant/price-plan
            'quote' => true,
            // Not on the documentation page (11 Oct 2026).
            'cancel' => null,
            'webhook' => true,
            'status_lookup' => true,
        ];
    }
}
