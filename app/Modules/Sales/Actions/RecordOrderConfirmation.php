<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ B18 (Joy's initial plan R92, 25 Oct 2026): "confirm it through call or
 * message by sales person". One try at reaching the customer: they confirmed,
 * did not answer, or cancelled. Every try counts, with when it was made.
 *
 * ⚠ Separate from the approval, and it moves nothing: no stock, no money, no
 * approval. A customer who cancels on the phone is marked here; cancelling
 * the ORDER stays the Cancel button's job (it releases the stock and settles
 * money), which the order page then offers.
 *
 * ⚠ Stale-form guard: the form sends the number of tries it saw. A second
 * click, or a colleague who marked the same call a moment earlier, is refused
 * instead of counted twice.
 */
class RecordOrderConfirmation
{
    public function handle(SalesOrder $order, OrderConfirmation $outcome, User $by, int $triesSeen, ?string $note = null): SalesOrder
    {
        $note = trim((string) $note);

        if ($outcome === OrderConfirmation::Waiting) {
            throw ValidationException::withMessages(['outcome' => 'Say how the call went.']);
        }

        if ($outcome === OrderConfirmation::CustomerCancelled && mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'Say why the customer cancelled (at least 5 letters).']);
        }

        return DB::transaction(function () use ($order, $outcome, $by, $triesSeen, $note) {
            $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === SalesOrderStatus::Cancelled || $order->status === SalesOrderStatus::Closed
                || $order->approvalRequest?->status === ApprovalStatus::Rejected) {
                throw ValidationException::withMessages(['outcome' => "Order {$order->order_number} is finished; there is nobody to call about it."]);
            }

            if ((int) $order->confirmation_attempts !== $triesSeen) {
                throw ValidationException::withMessages(['outcome' => 'Somebody marked a call on this order a moment ago. Look at the page again before marking another.']);
            }

            $order->forceFill([
                'confirmation_status' => $outcome,
                'confirmation_attempts' => (int) $order->confirmation_attempts + 1,
                'confirmation_last_try_at' => now(),
                'confirmation_note' => $note === '' ? null : mb_substr($note, 0, 255),
                'confirmed_by' => $outcome === OrderConfirmation::Confirmed ? $by->id : null,
                'confirmed_at' => $outcome === OrderConfirmation::Confirmed ? now() : null,
            ])->save();

            activity()->performedOn($order)->causedBy($by)
                ->withProperties(['outcome' => $outcome->value, 'try' => $order->confirmation_attempts, 'note' => $note])
                ->log('Confirmation call: '.$outcome->label());

            return $order;
        });
    }
}
