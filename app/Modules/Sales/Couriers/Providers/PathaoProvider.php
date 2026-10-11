<?php

namespace App\Modules\Sales\Couriers\Providers;

use App\Modules\Sales\Couriers\Drivers\PathaoDriver;
use App\Modules\Sales\Couriers\Enums\CourierStatus as S;
use App\Modules\Sales\Couriers\Tracking\StatusWord as W;

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

    /**
     * Pathao's `order_status_slug` words and its webhook events (11 Oct 2026).
     */
    public function statusWords(): array
    {
        return [
            'created' => S::Booked->value,
            'updated' => W::IGNORE,
            'pending' => S::Booked->value,
            'pickup_requested' => S::Booked->value,
            'assigned_for_pickup' => S::PickupAssigned->value,
            'picked' => S::PickedUp->value,
            'pickup_failed' => S::OnHold->value,
            'pickup_cancelled' => S::Cancelled->value,
            'cancelled' => S::Cancelled->value,
            'at_the_sorting_hub' => S::InTransit->value,
            'in_transit' => S::InTransit->value,
            'received_at_last_mile_hub' => S::AtDeliveryHub->value,
            'assigned_for_delivery' => S::OutForDelivery->value,
            'delivered' => S::Delivered->value,
            'partial_delivery' => S::PartiallyDelivered->value,
            'return' => S::Returning->value,
            'returned' => S::Returning->value,
            'return_in_transit' => S::Returning->value,
            'return_id_created' => W::IGNORE,
            'delivery_failed' => S::DeliveryFailed->value,
            'on_hold' => S::OnHold->value,
            'paid_return' => S::PaidReturn->value,
            'exchange' => S::Exchanged->value,
            'returned_to_merchant' => S::ReturnedToMerchant->value,
            'payment_invoice' => W::PAYMENT,
            'paid' => W::PAYMENT,
            'store_created' => W::IGNORE,
            'store_updated' => W::IGNORE,
        ];
    }

    public function addressMax(): int
    {
        return 220;
    }

    /** Pathao takes 0.5 to 10 kg. */
    public function maxWeightGrams(): int
    {
        return 10000;
    }

    public function webhookSecretHeader(): ?string
    {
        return 'X-PATHAO-Signature';
    }

    public function integrationEchoHeader(): ?string
    {
        return 'X-Pathao-Merchant-Webhook-Integration-Secret';
    }

    public function integrationEchoPage(): ?string
    {
        return 'Pathao Developer API > Webhook Integration';
    }

    public function integrationEchoHintKey(): ?string
    {
        return 'couriers.echo_hint.pathao';
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
