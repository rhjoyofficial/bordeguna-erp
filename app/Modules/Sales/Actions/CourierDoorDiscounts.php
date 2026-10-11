<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\DoorResolution;
use App\Modules\Sales\Exceptions\DoorDiscountNeedsApproval;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierDoorDiscountRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A door discount above the confirming person's cap: asked for, then decided
 * by the Owner (courier hub slice 8, ruling 3).
 *
 * ⚠⚠ THE OWNER'S YES AND THE POSTING ARE ONE TRANSACTION, the credit-terms
 * rule. If the courier's answer can no longer be recorded as it was entered
 * (the parcel was answered another way, the month closed and reopened), the
 * approval is rolled back with it and the Owner is told why.
 */
class CourierDoorDiscounts
{
    public function __construct(private readonly RecordConsignmentOutcome $outcomes) {}

    /**
     * @param  array<string, mixed>  $payload  the outcome as entered: outcome, outcome_on, collected_amount, lines, note, reason
     */
    public function request(Consignment $consignment, array $payload, DoorDiscountNeedsApproval $refusal, User $requestedBy): CourierDoorDiscountRequest
    {
        return DB::transaction(function () use ($consignment, $payload, $refusal, $requestedBy) {
            $consignment = Consignment::query()->whereKey($consignment->id)->lockForUpdate()->firstOrFail();

            if (! $consignment->isBooked()) {
                throw ValidationException::withMessages(['outcome' => "Consignment {$consignment->consignment_number} is already recorded."]);
            }

            if (self::waitingFor($consignment) !== null) {
                throw ValidationException::withMessages(['outcome' => 'A discount on this parcel is already waiting for the Owner.']);
            }

            CourierDoorDiscountRequest::ensureWorkflow($consignment->business_id);

            $request = CourierDoorDiscountRequest::create([
                'business_id' => $consignment->business_id,
                'consignment_id' => $consignment->id,
                'payload' => $payload,
                'amount_due' => $refusal->due->toTaka(),
                'collected_amount' => $refusal->collected->toTaka(),
                'discount_amount' => $refusal->discount->toTaka(),
                'reason' => (string) ($payload['reason'] ?? ''),
                'requested_by' => $requestedBy->id,
            ]);

            $request->submitForApproval($requestedBy);

            activity('courier.door')
                ->performedOn($consignment)
                ->causedBy($requestedBy)
                ->withProperties(['discount' => $refusal->discount->toTaka(), 'why' => $refusal->why])
                ->log("Asked the Owner to approve a {$refusal->discount->format()} discount at the door");

            return $request->fresh('approvalRequest');
        });
    }

    public function approve(CourierDoorDiscountRequest $request, User $owner, ?string $note = null): Consignment
    {
        return DB::transaction(function () use ($request, $owner, $note) {
            $request = CourierDoorDiscountRequest::query()->whereKey($request->id)->lockForUpdate()->with('consignment', 'requestedBy')->firstOrFail();
            $approval = $request->approvalRequest;

            if ($approval === null || ! $approval->isPending() || $request->applied_at !== null) {
                throw ValidationException::withMessages(['approval' => 'This discount has already been decided.']);
            }

            if (! $approval->canBeActionedBy($owner)) {
                throw ValidationException::withMessages(['approval' => 'Only the Owner approves a discount above the cap.']);
            }

            $approval->approve($owner, $note);

            $payload = $request->payload;
            $correction = ($payload['resolution'] ?? null) === DoorResolution::PriceCorrection->value;

            $consignment = $this->outcomes->handle(
                consignment: $request->consignment,
                outcome: ConsignmentStatus::from((string) $payload['outcome']),
                on: $payload['outcome_on'] ?? null,
                collectedAmount: (string) $payload['collected_amount'],
                lines: $payload['lines'] ?? [],
                recordedBy: $request->requestedBy,
                note: $payload['note'] ?? null,
                resolution: $correction ? DoorResolution::PriceCorrection : DoorResolution::Discount,
                reason: (string) $payload['reason'],
                prices: $correction ? (array) ($payload['prices'] ?? []) : [],
                discountApprovedBy: $owner,
            );

            // ⚠ The Owner approved THIS figure. A parcel whose invoice now
            // comes to something else is a different decision. (A price
            // correction replays the exact prices the Owner saw, so its
            // invoice matches the collection and there is no door difference.)
            if (! $correction && Money::ofTaka((string) $consignment->door_difference)->minor !== $request->discount()->minor) {
                throw ValidationException::withMessages([
                    'approval' => "The discount asked for was {$request->discount()->format()}, but the parcel now needs ".Money::ofTaka((string) $consignment->door_difference)->format().'. Reject this and record the answer again.',
                ]);
            }

            $request->forceFill(['applied_at' => now(), 'applied_by' => $owner->id])->save();

            return $consignment;
        });
    }

    public function reject(CourierDoorDiscountRequest $request, User $owner, ?string $note = null): void
    {
        DB::transaction(function () use ($request, $owner, $note) {
            $approval = $request->approvalRequest;

            if ($approval === null || ! $approval->isPending()) {
                throw ValidationException::withMessages(['approval' => 'This discount has already been decided.']);
            }

            if (! $approval->canBeActionedBy($owner)) {
                throw ValidationException::withMessages(['approval' => 'Only the Owner decides a discount above the cap.']);
            }

            $approval->reject($owner, $note);
        });
    }

    public static function waitingFor(Consignment $consignment): ?CourierDoorDiscountRequest
    {
        return CourierDoorDiscountRequest::query()
            ->where('consignment_id', $consignment->id)
            ->whereNull('applied_at')
            ->latest('id')
            ->get()
            ->first(fn (CourierDoorDiscountRequest $request) => $request->approvalRequest?->isPending() ?? false);
    }
}
