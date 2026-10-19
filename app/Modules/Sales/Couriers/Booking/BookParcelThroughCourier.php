<?php

namespace App\Modules\Sales\Couriers\Booking;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Actions\BookConsignment;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Couriers\Drivers\BooksParcels;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Couriers\Fees\CourierFeeEstimator;
use App\Modules\Sales\Couriers\Fees\FeeEstimate;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\Couriers\Tracking\RecordShipmentEvent;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\ParcelWeight;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Books a challan with a courier through its API (courier hub slice 4).
 *
 * ⚠⚠ THE INTENT ROW IS WRITTEN BEFORE THE REQUEST LEAVES (ruling 13). If the
 * connection dies after the courier took the parcel, the ERP still knows it
 * MAY have booked it, and refuses a second booking until that is settled:
 * CarryBee by looking our reference up, Pathao by a person (SettleBookingRequest).
 *
 * ⚠⚠ ONE BOOKING PER CHALLAN, HOWEVER MANY TIMES THE BUTTON IS PRESSED. A cache
 * lock per challan covers the HTTP call; inside it the challan's lines are read
 * under a row lock and any open intent refuses the second press.
 *
 * ⚠ The merchant_order_id is the CHALLAN number: stable, unique, ours. A retry
 * after a clear refusal sends the same reference again.
 *
 * ⚠ No money moves. The consignment is written through BookConsignment with
 * all its guards; the invoice still waits for the courier's answer, confirmed
 * by a person (ruling 1).
 */
class BookParcelThroughCourier
{
    public function __construct(
        private readonly BookConsignment $book,
        private readonly ResolveSellingPrice $resolver,
        private readonly RecordShipmentEvent $events,
        private readonly CourierFeeEstimator $estimator,
    ) {}

    /**
     * @param  array{cod_amount?: mixed, declared_weight_grams?: mixed, delivery_type?: mixed, item_type?: mixed, description?: mixed, special_instruction?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function handle(Delivery $delivery, CourierAccount $account, array $input, User $by): CourierBookingRequest
    {
        // ⭐ Joy, 11 Oct 2026: through the link that serves the ORDER's brand,
        // never an account kept for the other brand.
        $brandId = OrderBrand::forDeliveries([$delivery->id])[$delivery->id] ?? null;
        [$link, $money] = self::linkFor($account, (int) $delivery->business_id, $brandId);
        $definition = $account->definition();
        $driver = app((string) $definition->driverClass());

        if (! $driver instanceof BooksParcels) {
            throw ValidationException::withMessages(['courier_account_id' => "{$definition->name()} cannot book through the ERP yet."]);
        }

        $draft = ParcelDraft::for($delivery, $this->resolver);
        $problems = $draft->problems($definition->addressMax());

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $destination = $draft->destinationFor($definition->code());

        if ($destination instanceof Unresolved) {
            throw ValidationException::withMessages(['destination' => $destination->message]);
        }

        $cod = $this->cod($input['cod_amount'] ?? null, $draft);
        $grams = $this->grams($input['declared_weight_grams'] ?? null, $draft, $account);
        $options = $account->options ?? [];
        $deliveryType = $this->choice($definition->optionChoices('delivery_type'), $input['delivery_type'] ?? null, $options['delivery_type'] ?? null, 'delivery_type');
        $itemKey = $definition->itemTypeOptionKey();
        $itemType = $this->choice($definition->optionChoices($itemKey), $input['item_type'] ?? null, $options[$itemKey] ?? null, 'item_type');
        $description = trim((string) ($input['description'] ?? '')) ?: $draft->description;
        // ⭐ A caller that says nothing about the rider's note gets the order's
        // special instructions; one that sends a blank meant a blank.
        $instruction = trim((string) (array_key_exists('special_instruction', $input) ? $input['special_instruction'] : $draft->specialInstruction));

        $parcel = new ParcelBooking(
            merchantOrderId: (string) $delivery->delivery_number,
            storeId: (string) $link->provider_store_id,
            recipientName: $draft->recipientName(),
            recipientPhone: (string) $draft->recipientPhone(),
            recipientAddress: $draft->addressText($definition->addressMax()),
            cityId: $destination->cityId,
            zoneId: $destination->zoneId,
            areaId: $destination->areaId,
            weightGrams: $grams,
            quantity: $draft->quantity,
            codTaka: $cod,
            deliveryType: $deliveryType,
            itemType: $itemType,
            description: mb_strimwidth($description, 0, ParcelDraft::DESCRIPTION_MAX, '...'),
            specialInstruction: $instruction === '' ? null : mb_strimwidth($instruction, 0, 250, '...'),
        );

        $lock = Cache::lock('couriers:book:delivery:'.$delivery->id, 90);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'courier_account_id' => "A booking for challan {$delivery->delivery_number} is already on its way. Wait a moment and reload the page.",
            ]);
        }

        try {
            $intent = DB::transaction(function () use ($delivery, $account, $parcel, $money, $by) {
                self::assertBookable($delivery);

                return CourierBookingRequest::create([
                    'business_id' => $delivery->business_id,
                    'delivery_id' => $delivery->id,
                    'courier_account_id' => $account->id,
                    'merchant_order_id' => $parcel->merchantOrderId,
                    'request_hash' => hash('sha256', (string) json_encode(get_object_vars($parcel))),
                    'status' => CourierBookingRequest::REQUESTED,
                    // ⚠ What a later recovery needs to record the parcel, and
                    // nothing about the person it goes to.
                    'response_summary' => ['sent' => [
                        'money_account_id' => $money->id,
                        'cod' => $parcel->codTaka,
                        'weight_grams' => $parcel->weightGrams,
                        'city_id' => $parcel->cityId,
                        'zone_id' => $parcel->zoneId,
                        'area_id' => $parcel->areaId,
                        'delivery_type' => $parcel->deliveryType,
                        'item_type' => $parcel->itemType,
                        'store_id' => $parcel->storeId,
                    ]],
                    'created_by' => $by->id,
                ]);
            });

            // ⭐ Slice 5: what the ERP expected this parcel to cost, worked out
            // before the request leaves (a cached quote only, never a new call)
            // and kept once the courier has taken it.
            $estimate = $this->estimateFor($delivery, $account, $draft, $grams, $input['declared_weight_grams'] ?? null, $cod, $deliveryType);

            $result = $driver->book($account, $parcel);
            $intent = $this->settle($intent, $result, $by);

            if ($estimate !== null && $intent->status === CourierBookingRequest::SUCCEEDED && $intent->consignment_id !== null) {
                try {
                    $estimate->record((int) $delivery->business_id, (int) $delivery->id, (int) $intent->consignment_id, $by->id);
                } catch (Throwable $e) {
                    // ⚠ The parcel is booked; a lost estimate must not undo that.
                    report($e);
                }
            }

            return $intent;
        } finally {
            $lock->release();
        }
    }

    /**
     * Turns the courier's answer into the intent's state and, on success, the
     * consignment. Also used when a lost answer is found later.
     */
    public function settle(CourierBookingRequest $intent, BookingResult $result, User $by, ?string $resolution = null): CourierBookingRequest
    {
        $summary = array_merge($intent->response_summary ?? [], ['result' => $result->summary()]);
        $message = $result->message === null ? null : Str::limit($result->message, 490, '');

        if ($result->outcome === BookingResult::FAILED) {
            $intent->forceFill(['status' => CourierBookingRequest::FAILED, 'error' => $message, 'response_summary' => $summary])->save();
            CourierParcelChanged::dispatch(CourierParcelChanged::BOOKING_FAILED, (int) $intent->business_id, (int) $intent->delivery_id);

            return $intent;
        }

        if ($result->outcome === BookingResult::UNKNOWN) {
            $intent->forceFill(['status' => CourierBookingRequest::UNKNOWN, 'error' => $message, 'response_summary' => $summary])->save();
            CourierParcelChanged::dispatch(CourierParcelChanged::BOOKING_UNKNOWN, (int) $intent->business_id, (int) $intent->delivery_id);

            return $intent;
        }

        try {
            $consignment = $this->record($intent, $result, $by);
        } catch (ValidationException $e) {
            // ⚠ Booked at the courier, refused by the ERP (somebody invoiced the
            // challan by hand while the request was out). Kept open with the
            // courier's number, so a person cancels it there or settles it.
            $intent->forceFill([
                'status' => CourierBookingRequest::UNKNOWN,
                'provider_consignment_id' => $result->consignmentId,
                'error' => Str::limit("Booked at the courier as {$result->consignmentId}, but the ERP could not record it: ".collect($e->errors())->flatten()->first(), 490, ''),
                'response_summary' => $summary,
            ])->save();

            return $intent;
        }

        $intent->forceFill([
            'status' => CourierBookingRequest::SUCCEEDED,
            'provider_consignment_id' => $result->consignmentId,
            'consignment_id' => $consignment->id,
            'error' => null,
            'response_summary' => $summary,
            'resolution' => $resolution,
            'resolved_by' => $resolution === null ? null : $by->id,
            'resolved_at' => $resolution === null ? null : now(),
        ])->save();

        CourierParcelChanged::dispatch(CourierParcelChanged::BOOKED, (int) $intent->business_id, (int) $intent->delivery_id, (int) $consignment->id, CourierStatus::Booked->value);

        return $intent;
    }

    private function record(CourierBookingRequest $intent, BookingResult $result, User $by): Consignment
    {
        $sent = $intent->response_summary['sent'] ?? [];
        $delivery = Delivery::query()->findOrFail($intent->delivery_id);
        $money = MoneyAccount::query()->findOrFail($sent['money_account_id'] ?? 0);

        $consignment = $this->book->handle(
            delivery: $delivery,
            courier: $money,
            consignmentNumber: (string) $result->consignmentId,
            codAmount: (string) ($sent['cod'] ?? 0),
            createdBy: $by,
            declaredWeightGrams: isset($sent['weight_grams']) ? (int) $sent['weight_grams'] : null,
            courierDetails: [
                'courier_account_id' => $intent->courier_account_id,
                'provider_consignment_id' => $result->consignmentId,
                'merchant_order_ref' => $intent->merchant_order_id,
                'booked_via' => Consignment::BOOKED_VIA_API,
                'provider_fee_quoted' => $result->deliveryFee,
                'provider_cod_fee_quoted' => $result->codFee,
                'courier_city_id' => $sent['city_id'] ?? null,
                'courier_zone_id' => $sent['zone_id'] ?? null,
                'courier_area_id' => $sent['area_id'] ?? null,
            ],
        );

        $this->events->handle($consignment, CourierShipmentEvent::BOOKING, 'booked', $result->rawStatus ?? 'created', now());

        return $consignment->fresh();
    }

    /**
     * The estimate for what is about to be sent. Never throws: the booking
     * matters more than its estimate.
     */
    private function estimateFor(Delivery $delivery, CourierAccount $account, ParcelDraft $draft, int $grams, mixed $typed, int $cod, int $deliveryType): ?FeeEstimate
    {
        try {
            $weight = ParcelWeight::forDelivery($delivery);
            $wasTyped = $typed !== null && $typed !== '' && (int) $typed !== $draft->weightSuggested;

            return $this->estimator->estimate(
                account: $account,
                businessId: (int) $delivery->business_id,
                destination: ['district' => $draft->address?->district, 'thana' => $draft->address?->thana, 'area' => $draft->address?->area],
                weightGrams: $grams,
                codAmount: $cod,
                service: CourierFeeEstimator::serviceFor($account->definition()->code(), $deliveryType),
                weightInfo: $wasTyped ? ['typed' => true] : ['items_weighed' => $weight->lines - count($weight->missing), 'items_total' => $weight->lines],
                quote: CourierFeeEstimator::QUOTE_CACHED,
                brandId: OrderBrand::forDeliveries([$delivery->id])[$delivery->id] ?? null,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The account's link that serves this business, and this order's brand:
     * active, a pickup store, and the business's own courier money account
     * (1145) with a ledger head.
     *
     * ⚠ An account linked only to another brand is refused here, on the server,
     * whatever the form offered (Joy, 11 Oct 2026).
     *
     * @return array{0: CourierAccountBusiness, 1: MoneyAccount}
     *
     * @throws ValidationException
     */
    public static function linkFor(CourierAccount $account, int $businessId, ?int $brandId = null): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['courier_account_id' => $message]);

        if (! $account->is_active) {
            $fail("{$account->label} is switched off. An Owner can test and activate it in Settings > Couriers.");
        }

        $links = $account->linksFor($businessId)->filter(fn (CourierAccountBusiness $link) => ! blank($link->provider_store_id))->values();

        if ($links->isEmpty()) {
            $fail("{$account->label} does not serve this business yet. Link it with a pickup store in Settings > Couriers.");
        }

        $link = CourierAccountBusiness::serving($links, $brandId);

        if ($link === null) {
            $fail(self::brandRefusal($account, $links, $brandId));
        }

        $money = self::moneyFor($link, $businessId);

        if ($money === null) {
            $fail("{$account->label} has no active courier money account (1145) for this business. Set it in Settings > Couriers.");
        }

        return [$link, $money];
    }

    /**
     * The link's courier money account when it is usable: this business's own,
     * a courier one, switched on and with a ledger head. Null otherwise.
     */
    public static function moneyFor(CourierAccountBusiness $link, int $businessId): ?MoneyAccount
    {
        $money = $link->money_account_id === null ? null : MoneyAccount::query()->withoutGlobalScopes()->find($link->money_account_id);

        if ($money === null || (int) $money->business_id !== $businessId || ! $money->isCourier() || ! $money->is_active || $money->account_id === null) {
            return null;
        }

        return $money;
    }

    /**
     * Why an account with links here does not serve this order's brand.
     *
     * @param  Collection<int, CourierAccountBusiness>  $links
     */
    public static function brandRefusal(CourierAccount $account, Collection $links, ?int $brandId): string
    {
        $names = Brand::query()->withoutGlobalScopes()
            ->whereIn('id', $links->pluck('brand_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->orderBy('name')->pluck('name')->implode(' and ');

        if ($brandId === null) {
            return "{$account->label} carries {$names} parcels only, and this order has no brand. Choose another account.";
        }

        $brand = Brand::query()->withoutGlobalScopes()->whereKey($brandId)->value('name') ?? 'this brand';

        return "{$account->label} carries {$names} parcels only, not {$brand}.";
    }

    /**
     * Under the challan's row lock: not consigned, not billed, no booking whose
     * answer is still open.
     *
     * @throws ValidationException
     */
    public static function assertBookable(Delivery $delivery): void
    {
        $lines = DeliveryLine::query()->where('delivery_id', $delivery->id)->lockForUpdate()->get();

        if (Consignment::query()->where('delivery_id', $delivery->id)->exists()) {
            throw ValidationException::withMessages(['courier_account_id' => "Challan {$delivery->delivery_number} has already been handed to a courier."]);
        }

        if ($lines->contains(fn (DeliveryLine $line) => (float) $line->quantity_invoiced > 0 || (float) $line->quantity_returned > 0)) {
            throw ValidationException::withMessages(['courier_account_id' => "Challan {$delivery->delivery_number} has already been invoiced or partly returned."]);
        }

        // ⭐ Corrections wave 1: a reversed challan's goods are back on the shelf.
        if ($delivery->fresh()->isReversed()) {
            throw ValidationException::withMessages(['courier_account_id' => "Challan {$delivery->delivery_number} was reversed; its goods are back in stock."]);
        }

        $open = CourierBookingRequest::query()->where('delivery_id', $delivery->id)
            ->whereIn('status', [CourierBookingRequest::REQUESTED, CourierBookingRequest::UNKNOWN])->first();

        if ($open !== null) {
            throw ValidationException::withMessages([
                'courier_account_id' => "An earlier booking of challan {$delivery->delivery_number} has no clear answer from the courier yet. Settle it first, so the parcel is not booked twice.",
            ]);
        }
    }

    /**
     * Whole taka, never more than the challan would bill (rounded up), never
     * rounded down quietly.
     *
     * @throws ValidationException
     */
    private function cod(mixed $given, ParcelDraft $draft): int
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['cod_amount' => $message]);

        if ($given === null || trim((string) $given) === '') {
            $fail('Enter the amount to collect. Enter 0 for a parcel already paid for.');
        }

        try {
            $money = Money::ofTaka((string) $given);
        } catch (InvalidArgumentException) {
            $fail('The amount to collect must be a number.');
        }

        if ($money->minor < 0) {
            $fail('The amount to collect cannot be negative.');
        }

        if ($money->minor % 100 !== 0) {
            $fail('Couriers collect whole taka. Enter '.(int) ceil($money->minor / 100).' (rounded up) or another whole amount.');
        }

        $taka = intdiv($money->minor, 100);
        $ceiling = $draft->codWholeTaka();

        if ($ceiling !== null && $taka > $ceiling) {
            $fail("That is more than this challan would bill ({$ceiling}).");
        }

        if ($taka > 100000) {
            $fail('A courier collects at most 100,000 taka on one parcel.');
        }

        return $taka;
    }

    /**
     * @throws ValidationException
     */
    private function grams(mixed $given, ParcelDraft $draft, CourierAccount $account): int
    {
        $grams = $given === null || $given === '' ? $draft->weightSuggested : (int) $given;
        $definition = $account->definition();
        $max = $definition->maxWeightGrams();
        $optionMax = $account->options['max_weight_kg'] ?? null;

        if (is_numeric($optionMax) && (float) $optionMax > 0) {
            $max = min($max, (int) floor((float) $optionMax * 1000));
        }

        if ($grams === null) {
            throw ValidationException::withMessages(['declared_weight_grams' => 'Type the parcel weight in grams: some items on this challan have no weight.']);
        }

        if ($grams < 1 || $grams > $max) {
            throw ValidationException::withMessages(['declared_weight_grams' => "{$definition->name()} takes 1 to {$max} grams on this account."]);
        }

        return $grams;
    }

    /**
     * @param  array<string, string>  $choices
     *
     * @throws ValidationException
     */
    private function choice(array $choices, mixed $given, mixed $default, string $field): int
    {
        $value = $given === null || $given === '' ? $default : $given;

        if ($value === null || ! array_key_exists((string) $value, $choices)) {
            throw ValidationException::withMessages([$field => 'Choose one of the listed values.']);
        }

        return (int) $value;
    }
}
