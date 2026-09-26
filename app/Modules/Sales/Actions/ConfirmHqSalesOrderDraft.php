<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Support\ShopOrderReference;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderDraft;
use App\Modules\Sales\Models\SalesOrderDraftLine;
use App\Modules\Sales\Models\SalesOrderHqConfirmation;
use App\Modules\Sales\Support\HqOrderPricing;
use App\Modules\Sales\Support\PricedHqOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Turns an HQ draft into a real sales order, which then waits in the ERP's approval inbox
 * (HQ docs/88 A point 3, docs/79 F).
 *
 * ⛔⛔ NEVER DIRECTLY, NEVER APPROVED HERE. The order is written by `CreateSalesOrder`, the one way an
 * order is written, and created by the SYSTEM ACTOR, which holds no `sales.orders.approve`: so the order
 * always arrives PENDING, the ERP's approvers are notified, and goods cannot leave until a person in the
 * ERP approves it (`SalesOrder::isDeliverable`). The HQ user that asked is recorded as the requester and
 * never as the creator, so even an HQ user who happened to hold the approval could not self-approve.
 * Two approvals stand, as docs/79 F says: HQ's says HQ may write; the ERP's says goods may leave.
 *
 * ⚠⚠ THE DRAFT IS WHAT WAS APPROVED, AND NOTHING ELSE BECOMES THE ORDER. The request must name the
 * draft's own proposal fingerprint (the approval HQ holds) and propose exactly the draft's totals. Then
 * the ERP prices the draft AGAIN, now (`HqOrderPricing`): a store no longer live, a product no longer
 * sellable, a price or a delivery charge that moved since the draft, each REFUSES the request with both
 * figures named. Nothing is rewritten: a new price is a new draft and a new approval on the HQ side.
 * After `CreateSalesOrder` has priced the lines itself, the order's totals must equal the draft's to the
 * paisa or the whole order is rolled back.
 *
 * ⭐ ONE DRAFT, ONE ORDER, ONCE. Idempotent on HQ's key (the same key and request answers the same order;
 * the same key and another request is refused), and a draft already turned into an order refuses a
 * second key. The link is its own immutable row (`SalesOrderHqConfirmation`); the draft stays a draft.
 *
 * ⚠ What it does, stated: takes the next order number, mints the shop reference, writes the order and
 * its address, opens the ERP approval request, notifies the ERP approvers, and, when no active customer
 * has this phone, creates one exactly as the website would (Retail, no price group, never the walk-in
 * row). What it does not do: approve, move stock, post a journal, take a payment, book a courier, or
 * spend a coupon (a draft carries none).
 */
final class ConfirmHqSalesOrderDraft
{
    public function __construct(
        private readonly HqOrderPricing $pricing,
        private readonly CreateSalesOrder $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request
     * @return array{0: SalesOrder, 1: bool} the order and whether it was replayed
     *
     * @throws SalesOrderDraftRefused
     */
    public function handle(int $businessId, array $input, User $requestedBy, ?int $tokenId = null, ?string $correlationId = null): array
    {
        $key = (string) $input['idempotency_key'];
        $digest = CreateSalesOrderDraft::digest($input);

        if (($replayed = $this->replay($businessId, $key, $digest)) !== null) {
            return [$replayed, true];
        }

        /** @var SalesOrderDraft|null $draft */
        $draft = SalesOrderDraft::query()->where('business_id', $businessId)->where('idempotency_key', (string) $input['draft_key'])->with(['lines', 'store'])->first();
        if ($draft === null) {
            throw SalesOrderDraftRefused::notFound('No draft with that key in this business.');
        }
        $this->refuseWhenConfirmed($draft);

        // The approval HQ holds is for exactly this draft.
        if (! hash_equals($draft->hq_proposal_fingerprint, (string) $input['hq_reference']['proposal_fingerprint'])) {
            throw SalesOrderDraftRefused::refused(['hq_reference.proposal_fingerprint' => ['This draft was written for a different approved proposal.']]);
        }

        // HQ proposes what the draft recorded, and nothing else.
        $mismatches = [];
        foreach (['goods_total', 'delivery_charge', 'agreed_total'] as $field) {
            $proposed = (string) $input['proposed'][$field];
            if (Money::ofTaka($proposed)->minor !== Money::ofTaka((string) $draft->{$field})->minor) {
                $mismatches["proposed.{$field}"][] = "Proposed {$proposed}, the draft agreed ".Money::ofTaka((string) $draft->{$field})->toTaka().'.';
            }
        }
        if ($mismatches !== []) {
            throw SalesOrderDraftRefused::quoteMismatch($mismatches);
        }

        $priced = $this->priceAgain($businessId, $draft);

        try {
            $order = DB::transaction(function () use ($businessId, $draft, $priced, $key, $digest, $input, $requestedBy, $tokenId, $correlationId) {
                // ⚠ One draft, one order, even when two requests arrive together: the draft row is
                // locked, the check repeated, and the unique index on the link is the last word.
                SalesOrderDraft::query()->whereKey($draft->id)->lockForUpdate()->first();
                $this->refuseWhenConfirmed($draft);

                $order = $this->orders->handle(
                    customer: $this->identify($businessId, $priced),
                    lines: array_map(fn (array $line) => ['product' => $line['product'], 'quantity' => (string) $line['quantity']], $priced->lines),
                    // ⛔ The system actor: it holds no approval, so the order waits for a person.
                    createdBy: SystemActor::user(),
                    warehouse: null,
                    expectedDate: null,
                    note: 'Placed through HQ from draft '.$draft->idempotency_key.'. Approved on the HQ side; waiting for approval here before any goods leave.',
                    origin: SalesOrderOrigin::website(
                        store: $priced->store,
                        shopReference: ShopOrderReference::mint($priced->store),
                        deliveryCharge: $priced->delivery->toTaka(),
                        paymentMethod: (string) $draft->payment_method,
                        destination: $priced->destination,
                    ),
                );

                $this->assertTheDraftWasWritten($draft, $order);

                SalesOrderHqConfirmation::create([
                    'business_id' => $businessId,
                    'sales_order_draft_id' => $draft->id,
                    'sales_order_id' => $order->id,
                    'idempotency_key' => $key,
                    'payload_digest' => $digest,
                    'hq_reference' => $input['hq_reference'],
                    'correlation_id' => $correlationId,
                    'hq_token_id' => $tokenId,
                    'requested_by' => $requestedBy->id,
                ]);

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            if (($replayed = $this->replay($businessId, $key, $digest)) !== null) {
                return [$replayed, true];
            }
            $this->refuseWhenConfirmed($draft);
            throw SalesOrderDraftRefused::keyReusedFor('order');
        }

        return [$order->fresh(['lines.product', 'address', 'approvalRequest', 'store', 'customer']), false];
    }

    /**
     * The ERP's answer for the draft, now, and every way it differs from what the draft agreed.
     *
     * @throws SalesOrderDraftRefused
     */
    private function priceAgain(int $businessId, SalesOrderDraft $draft): PricedHqOrder
    {
        $priced = $this->pricing->price(
            $businessId,
            (string) $draft->store?->key,
            [
                'name' => $draft->recipient_name,
                'phone' => $draft->recipient_phone,
                'district' => $draft->district,
                'thana' => $draft->thana,
                'area' => $draft->area,
                'address_line' => $draft->address_line,
                'landmark' => $draft->landmark,
            ],
            $draft->lines->map(fn (SalesOrderDraftLine $l) => ['sku' => $l->sku, 'quantity' => $l->quantity])->values()->all(),
            Carbon::now(),
        );

        $agreed = $draft->lines->values();
        $mismatches = [];
        foreach ($priced->lines as $line) {
            $then = (string) $agreed[$line['index']]->unit_price;
            if (! HqOrderPricing::sameDecimal($then, $line['unit_price'])) {
                $mismatches["lines.{$line['index']}.unit_price"][] = 'The draft agreed '.HqOrderPricing::plain($then).', the ERP\'s price is now '.HqOrderPricing::plain($line['unit_price']).'. Write a new draft.';
            }
        }
        foreach (['goods_total' => $priced->goods, 'delivery_charge' => $priced->delivery, 'agreed_total' => $priced->agreed()] as $field => $now) {
            $then = Money::ofTaka((string) $draft->{$field});
            if ($then->minor !== $now->minor) {
                $mismatches["draft.{$field}"][] = "The draft agreed {$then->toTaka()}, the ERP's figure is now {$now->toTaka()}. Write a new draft.";
            }
        }
        if ($mismatches !== []) {
            throw SalesOrderDraftRefused::quoteMismatch($mismatches);
        }

        return $priced;
    }

    /**
     * ⛔ The order written must be the draft, to the paisa, and it must be WAITING. Anything else rolls the
     * whole order back: a figure the engine resolved differently in the moment between the check and the
     * write, or an approval that somehow closed on creation, is not an order HQ may leave behind.
     */
    private function assertTheDraftWasWritten(SalesOrderDraft $draft, SalesOrder $order): void
    {
        $differs = Money::ofTaka($order->goodsTotal())->minor !== Money::ofTaka((string) $draft->goods_total)->minor
            || $order->deliveryCharge()->minor !== Money::ofTaka((string) $draft->delivery_charge)->minor
            || Money::ofTaka($order->agreedTotal())->minor !== Money::ofTaka((string) $draft->agreed_total)->minor;

        if ($differs) {
            throw SalesOrderDraftRefused::quoteMismatch(['draft' => ['A price changed while the order was being written. Nothing was written; write a new draft.']]);
        }

        if ($order->approvalRequest?->isPending() !== true) {
            throw new LogicException('An order made from an HQ draft must wait for approval in the ERP; this one did not.');
        }
    }

    /** @throws SalesOrderDraftRefused */
    private function refuseWhenConfirmed(SalesOrderDraft $draft): void
    {
        $existing = SalesOrderHqConfirmation::query()->where('sales_order_draft_id', $draft->id)->with('order')->first();
        if ($existing !== null) {
            throw SalesOrderDraftRefused::draftAlreadyConfirmed((string) $existing->order?->order_number);
        }
    }

    /**
     * The order this key already made, or null; refused when the key was used for a different request.
     *
     * @throws SalesOrderDraftRefused
     */
    private function replay(int $businessId, string $key, string $digest): ?SalesOrder
    {
        $existing = SalesOrderHqConfirmation::query()->where('business_id', $businessId)->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->payload_digest, $digest)) {
            throw SalesOrderDraftRefused::keyReusedFor('order');
        }

        return SalesOrder::query()->whereKey($existing->sales_order_id)->with(['lines.product', 'address', 'approvalRequest', 'store', 'customer'])->firstOrFail();
    }

    /**
     * Who the order is for, as the website decides it (`PlaceWebsiteOrder::identify`): the first active,
     * non-system customer of the business with this phone; otherwise a new Retail customer with no price
     * group. ⛔ Never the walk-in row, and an existing customer is never rewritten.
     */
    private function identify(int $businessId, PricedHqOrder $priced): Customer
    {
        $existing = Customer::query()
            ->where('business_id', $businessId)
            ->where('phone_normalized', $priced->phone)
            ->where('is_active', true)
            ->where('is_system', false)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $destination = $priced->destination;

        return Customer::create([
            'business_id' => $businessId,
            'name' => $destination->recipientName,
            'type' => CustomerType::Retail,
            'phone' => $destination->recipientPhone,
            'address' => $destination->oneLineAddress(),
            'price_group_id' => null,
            'is_active' => true,
        ]);
    }
}
