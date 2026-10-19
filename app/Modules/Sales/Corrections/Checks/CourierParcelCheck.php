<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ The outside world: a courier holds the parcel (corrections wave 1, 20 Oct
 * 2026; PHASE-0-CONTRACT v2 C12, an External effect needs its own check).
 *
 *   challan   refused while a parcel of it is LIVE with a courier (booked and
 *             not cancelled at the courier), once a parcel's outcome is
 *             recorded (the outcome's invoice or return is what to undo), and
 *             while a courier booking for it is still waiting for an answer
 *   invoice   refused when a parcel's outcome raised it: the outcome posted the
 *             cash the courier holds against it, and only reversing that
 *             outcome keeps the courier's account right
 *
 * Nothing here talks to a courier: it reads what the ERP already knows.
 */
class CourierParcelCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::External;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection()) {
            return null;
        }

        if ($document instanceof SalesInvoice) {
            $parcel = Consignment::query()->where('sales_invoice_id', $document->id)->first(['id', 'consignment_number']);

            return $parcel === null ? null : new Blocker(
                'raised_by_parcel',
                "Invoice {$document->invoice_number} was raised by the outcome of parcel {$parcel->consignment_number}, so the parcel outcome is what has to be put right.",
                resolvableBy: 'manager',
            );
        }

        if (! $document instanceof Delivery) {
            return null;
        }

        $parcel = Consignment::query()->where('delivery_id', $document->id)->with('courier:id,name')->latest('id')->first();

        if ($parcel !== null && $parcel->isBooked() && $parcel->courier_status !== CourierStatus::Cancelled) {
            return new Blocker(
                'parcel_live',
                "Challan {$document->delivery_number} is out with ".($parcel->courier?->name ?? 'a courier')." as parcel {$parcel->consignment_number}; cancel the parcel first.",
                resolvableBy: 'manager',
            );
        }

        if ($parcel !== null && ! $parcel->isBooked()) {
            return new Blocker(
                'parcel_outcome',
                "Challan {$document->delivery_number}'s parcel {$parcel->consignment_number} already has its outcome ({$parcel->status->label()}), so the challan cannot be reversed.",
                resolvableBy: 'manager',
            );
        }

        $waiting = CourierBookingRequest::query()->where('delivery_id', $document->id)
            ->whereIn('status', [CourierBookingRequest::REQUESTED, CourierBookingRequest::UNKNOWN])
            ->exists();

        return $waiting ? new Blocker(
            'booking_waiting',
            "A courier booking for challan {$document->delivery_number} is still waiting for its answer; settle it first.",
            resolvableBy: 'manager',
        ) : null;
    }
}
