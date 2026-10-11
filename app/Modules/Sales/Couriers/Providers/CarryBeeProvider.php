<?php

namespace App\Modules\Sales\Couriers\Providers;

use App\Modules\Sales\Couriers\Drivers\CarryBeeDriver;
use App\Modules\Sales\Couriers\Enums\CourierStatus as S;
use App\Modules\Sales\Couriers\Tracking\StatusWord as W;

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

    /**
     * The 25 webhook events of the API v2 documentation (11 Oct 2026), plus the
     * plainer words an order's `transfer_status` uses on GET details.
     */
    public function statusWords(): array
    {
        return [
            'created' => S::Booked->value,
            'create_failed' => W::IGNORE,
            'updated' => W::IGNORE,
            'pending' => S::Booked->value,
            'pickup_requested' => S::Booked->value,
            'assigned_for_pickup' => S::PickupAssigned->value,
            'picked' => S::PickedUp->value,
            'picked_up' => S::PickedUp->value,
            'pickup_failed' => S::OnHold->value,
            'pickup_cancelled' => S::Cancelled->value,
            'cancelled' => S::Cancelled->value,
            'at_the_sorting_hub' => S::InTransit->value,
            'on_the_way_to_central_warehouse' => S::InTransit->value,
            'at_central_warehouse' => S::InTransit->value,
            'in_transit' => S::InTransit->value,
            'received_at_last_mile_hub' => S::AtDeliveryHub->value,
            'assigned_for_delivery' => S::OutForDelivery->value,
            'delivery_on_hold' => S::OnHold->value,
            'on_hold' => S::OnHold->value,
            'delivered' => S::Delivered->value,
            'partial_delivery' => S::PartiallyDelivered->value,
            'partially_delivered' => S::PartiallyDelivered->value,
            'delivery_failed' => S::DeliveryFailed->value,
            'returned' => S::Returning->value,
            'returned_at_sorting' => S::Returning->value,
            'returned_in_transit' => S::Returning->value,
            'returned_to_merchant' => S::ReturnedToMerchant->value,
            'paid_return' => S::PaidReturn->value,
            'exchange' => S::Exchanged->value,
            'paid' => W::PAYMENT,
        ];
    }

    public function itemTypeOptionKey(): string
    {
        return 'product_type';
    }

    public function webhookSecretHeader(): ?string
    {
        return 'X-CB-Webhook-Integration-Header';
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
