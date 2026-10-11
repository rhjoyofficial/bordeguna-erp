<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Support\ParcelWeight;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A challan handed to a courier.
 *
 * ⚠⚠ ONLY A CHALLAN NOTHING HAS BEEN BILLED OR RETURNED AGAINST. The whole point
 * is that its invoice waits for the courier (Joy, 13 September); a challan that
 * already carries an invoice has decided that question the other way.
 */
class BookConsignment
{
    /**
     * @param  array<string, mixed>  $courierDetails  courier hub slice 4: what an API booking
     *                                                learned (account, ids, fees, status). Empty
     *                                                for a number typed by hand.
     *
     * @throws ValidationException
     */
    public function handle(
        Delivery $delivery,
        MoneyAccount $courier,
        string $consignmentNumber,
        string $codAmount,
        User $createdBy,
        ?int $declaredWeightGrams = null,
        array $courierDetails = [],
    ): Consignment {
        $consignmentNumber = trim($consignmentNumber);

        if ($consignmentNumber === '') {
            throw ValidationException::withMessages([
                'consignment_number' => "Enter the courier's consignment number.",
            ]);
        }

        if (! $courier->isCourier() || ! $courier->is_active || $courier->business_id !== $delivery->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'Choose an active courier account of this business.',
            ]);
        }

        // ⚠ A courier account with no ledger account would let the parcel go out
        // and refuse its receipt on the day it is delivered. Said now instead.
        if ($courier->account_id === null) {
            throw ValidationException::withMessages([
                'money_account_id' => "{$courier->name} has no ledger account yet. Set it to 1145 Receivable from Couriers under Bank & cash.",
            ]);
        }

        $cod = Money::ofTaka($codAmount);

        if ($cod->minor < 0) {
            throw ValidationException::withMessages([
                'cod_amount' => 'The amount to collect cannot be negative. Enter 0 for a parcel already paid for.',
            ]);
        }

        if ($declaredWeightGrams !== null && ($declaredWeightGrams < 1 || $declaredWeightGrams > ProductWeight::MAX_GRAMS)) {
            throw ValidationException::withMessages([
                'declared_weight_grams' => 'The parcel weight must be whole grams between 1 and '.ProductWeight::MAX_GRAMS.'.',
            ]);
        }

        return DB::transaction(function () use ($delivery, $courier, $consignmentNumber, $cod, $createdBy, $declaredWeightGrams, $courierDetails) {
            // ⚠ Read under a lock, so a clerk invoicing the challan by hand at
            // the same moment cannot slip a bill in between the check and the
            // booking.
            $lines = DeliveryLine::query()->where('delivery_id', $delivery->id)->lockForUpdate()->get();

            if (Consignment::query()->where('delivery_id', $delivery->id)->exists()) {
                throw ValidationException::withMessages([
                    'consignment_number' => "Challan {$delivery->delivery_number} has already been handed to a courier.",
                ]);
            }

            if ($lines->contains(fn (DeliveryLine $line) => (float) $line->quantity_invoiced > 0 || (float) $line->quantity_returned > 0)) {
                throw ValidationException::withMessages([
                    'consignment_number' => "Challan {$delivery->delivery_number} has already been invoiced or partly returned, so its invoice cannot wait for a courier.",
                ]);
            }

            if (Consignment::query()->where('money_account_id', $courier->id)->where('consignment_number', $consignmentNumber)->exists()) {
                throw ValidationException::withMessages([
                    'consignment_number' => "{$courier->name} consignment {$consignmentNumber} is already recorded against another challan.",
                ]);
            }

            $accountId = $courierDetails['courier_account_id'] ?? null;

            if ($accountId !== null && Consignment::query()->withoutGlobalScopes()->where('courier_account_id', $accountId)->where('consignment_number', $consignmentNumber)->exists()) {
                throw ValidationException::withMessages([
                    'consignment_number' => "Consignment {$consignmentNumber} is already recorded for this courier account.",
                ]);
            }

            // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): the weight the courier
            // is told, typed freely with no reason, and beside it what the
            // challan's lines weigh - worked out HERE, never taken from the form.
            // A blank box declares what the form offered.
            $auto = ParcelWeight::forDelivery($delivery)->autoGrams();

            return Consignment::create([
                'business_id' => $delivery->business_id,
                'delivery_id' => $delivery->id,
                'money_account_id' => $courier->id,
                'consignment_number' => $consignmentNumber,
                'cod_amount' => $cod->toTaka(),
                'status' => ConsignmentStatus::Booked,
                'created_by' => $createdBy->id,
                'declared_weight_auto_grams' => $auto,
                'declared_weight_grams' => $declaredWeightGrams ?? ParcelWeight::suggestedForDelivery($delivery),
            ] + $courierDetails);
        });
    }
}
