<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderHqCancellation;
use App\Modules\Sales\Support\HqPlacedOrders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HQ cancels an order it placed, under the ERP's own rule (HQ docs/88 A point 3, docs/79 C.15).
 *
 * ⚠ THE RULE IS `CancelSalesOrder`, NOT A NEW ONE: only an open order with nothing delivered, and a
 * refusal with the ERP's own sentence once anything has left (that is a short-close or a return, a
 * person's act in the ERP, because money and goods may move). This action adds only what HQ needs
 * around it: it reaches ONLY an order HQ placed (`HqPlacedOrders`), it is idempotent on HQ's key, and it
 * records the key, the request and the reason (`SalesOrderHqCancellation`) so HQ can read back that
 * the cancellation that happened is its own.
 *
 * ⛔ No stock, no journal, no refund, no courier: a cancelled open order never touched any of them.
 */
final class CancelHqSalesOrder
{
    public function __construct(private readonly CancelSalesOrder $cancel) {}

    /**
     * @param  array<string, mixed>  $input  the validated request: idempotency_key, reason, hq_reference
     * @return array{0: SalesOrder, 1: bool} the order and whether the request was replayed
     *
     * @throws SalesOrderDraftRefused
     */
    public function handle(int $businessId, string $reference, array $input, User $requestedBy, ?int $tokenId = null, ?string $correlationId = null): array
    {
        $key = (string) $input['idempotency_key'];
        $digest = CreateSalesOrderDraft::digest(['reference' => $reference] + $input);

        if (($replayed = $this->replay($businessId, $key, $digest)) !== null) {
            return [$replayed, true];
        }

        $order = HqPlacedOrders::find($businessId, $reference);
        if ($order === null) {
            throw SalesOrderDraftRefused::notFound('No order HQ placed with that reference in this business.');
        }
        $this->refuseUnlessCancellable($order);

        try {
            $order = DB::transaction(function () use ($businessId, $order, $key, $digest, $input, $requestedBy, $tokenId, $correlationId) {
                /** @var SalesOrder $locked */
                $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->refuseUnlessCancellable($locked);

                try {
                    $this->cancel->handle($locked);
                } catch (RuntimeException $e) {
                    throw SalesOrderDraftRefused::hasDeliveries($e->getMessage());
                }

                SalesOrderHqCancellation::create([
                    'business_id' => $businessId,
                    'sales_order_id' => $locked->id,
                    'idempotency_key' => $key,
                    'payload_digest' => $digest,
                    'reason' => (string) $input['reason'],
                    'hq_reference' => $input['hq_reference'] ?? null,
                    'correlation_id' => $correlationId,
                    'hq_token_id' => $tokenId,
                    'requested_by' => $requestedBy->id,
                ]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            if (($replayed = $this->replay($businessId, $key, $digest)) !== null) {
                return [$replayed, true];
            }
            throw SalesOrderDraftRefused::keyReusedFor('cancellation');
        }

        return [HqPlacedOrders::find($businessId, (string) $order->order_number) ?? $order, false];
    }

    /** @throws SalesOrderDraftRefused */
    private function refuseUnlessCancellable(SalesOrder $order): void
    {
        if ($order->status === SalesOrderStatus::Cancelled) {
            throw SalesOrderDraftRefused::alreadyCancelled((string) $order->order_number);
        }

        $order->loadMissing('lines');
        if ($order->status !== SalesOrderStatus::Open || $order->lines->contains(fn ($line) => (float) $line->quantity_delivered > 0)) {
            throw SalesOrderDraftRefused::hasDeliveries("Sales order {$order->order_number} has deliveries against it. It cannot be cancelled; a person in the ERP closes it short or records a return.");
        }
    }

    /**
     * The order this key already cancelled, or null; refused when the key was used for a different request.
     *
     * @throws SalesOrderDraftRefused
     */
    private function replay(int $businessId, string $key, string $digest): ?SalesOrder
    {
        $existing = SalesOrderHqCancellation::query()->where('business_id', $businessId)->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->payload_digest, $digest)) {
            throw SalesOrderDraftRefused::keyReusedFor('cancellation');
        }

        return SalesOrder::query()->whereKey($existing->sales_order_id)->with(['lines.product', 'address', 'approvalRequest', 'store', 'customer'])->firstOrFail();
    }
}
