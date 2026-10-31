<?php

namespace App\Modules\Sales\Couriers\Tracking;

use App\Modules\Sales\Couriers\Drivers\CourierApi;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one courier word onto a parcel's timeline and, when it is newer and
 * allowed, moves the parcel's COURIER status.
 *
 * ⚠⚠ NEVER MONEY HERE. This touches the courier_* columns and nothing else:
 * not `status`, not an invoice, not a receipt, not a journal. Since 31 Oct
 * 2026 (Joy's decision 2) a "Delivered" whose collected amount is exactly the
 * amount due is handed AFTER this transaction to AutoDeliverFromCourier, which
 * records it through the person's own outcome action; any other "Delivered"
 * still asks a person to confirm (ruling 1, relaxed only for exact matches).
 *
 * ⚠ Idempotent: the same (event, occurred_at) twice is one row, and the second
 * changes nothing. A late word (older than the last one applied, or anything
 * after a final state) is recorded and moves nothing. An unknown word is
 * logged and kept raw on its row, and moves nothing.
 */
class RecordShipmentEvent
{
    /**
     * @param  array<string, mixed>  $details  non-PII only: amounts, attempt, reason, invoice id, weight
     * @return CourierShipmentEvent|null null when this exact event was already recorded
     */
    public function handle(
        Consignment $consignment,
        string $source,
        string $event,
        ?string $rawStatus,
        mixed $occurredAt = null,
        array $details = [],
        ?string $payloadHash = null,
    ): ?CourierShipmentEvent {
        $account = $consignment->courierAccount;
        $word = $account->definition()->readStatus($rawStatus ?? $event);
        $at = self::time($occurredAt);
        $details = self::whitelist($details);

        // ⚠ Bug hunt 16 Oct 2026: with no time from the courier, `$at` is now(),
        // so a webhook redelivered after our timeout (or the 15-minute poll
        // asking about an unknown word) was a new row every time, up to
        // thousands a parcel. Such an event is known by its body instead.
        $untimed = $occurredAt === null || (is_string($occurredAt) && trim($occurredAt) === '');

        try {
            $recorded = DB::transaction(function () use ($consignment, $source, $event, $rawStatus, $word, $at, $details, $payloadHash, $untimed) {
                /** @var Consignment $parcel */
                $parcel = Consignment::query()->withoutGlobalScopes()->whereKey($consignment->id)->lockForUpdate()->firstOrFail();
                $eventName = Str::limit($event, 80, '');

                if (CourierShipmentEvent::query()->withoutGlobalScopes()
                    ->where('consignment_id', $parcel->id)->where('event', $eventName)->where('occurred_at', $at)->exists()) {
                    return null;
                }

                if ($untimed && $payloadHash !== null && CourierShipmentEvent::query()->withoutGlobalScopes()
                    ->where('consignment_id', $parcel->id)->where('payload_hash', $payloadHash)->exists()) {
                    return null;
                }

                $applied = $word->kind === StatusWord::STATUS && $this->moves($parcel, $word->status, $at);
                $changes = ['status_synced_at' => now()];

                if ($applied) {
                    $changes += [
                        'courier_status' => $word->status,
                        'courier_status_raw' => Str::limit($rawStatus ?? $event, 80, ''),
                        'courier_status_at' => $at,
                    ];
                }

                if ($word->kind === StatusWord::PAYMENT && filled($details['invoice_id'] ?? null)) {
                    $changes['courier_invoice_ref'] = Str::limit((string) $details['invoice_id'], 60, '');
                }

                // What the courier SAYS it collected: shown to the person who
                // confirms, never posted from here.
                if (is_numeric($details['collected_amount'] ?? null)) {
                    $changes['courier_collected_amount'] = (string) $details['collected_amount'];
                }

                if ($parcel->provider_fee_quoted === null && is_numeric($details['delivery_fee'] ?? null)) {
                    $changes['provider_fee_quoted'] = (string) $details['delivery_fee'];
                }

                if ($parcel->provider_cod_fee_quoted === null && is_numeric($details['cod_fee'] ?? null)) {
                    $changes['provider_cod_fee_quoted'] = (string) $details['cod_fee'];
                }

                if (is_numeric($details['hub_weight_grams'] ?? null) && (int) $details['hub_weight_grams'] > 0) {
                    $changes['hub_weight_grams'] = (int) $details['hub_weight_grams'];
                }

                $parcel->forceFill($changes)->save();

                $row = CourierShipmentEvent::create([
                    'business_id' => $parcel->business_id,
                    'consignment_id' => $parcel->id,
                    'source' => $source,
                    'event' => $eventName,
                    'raw_status' => $rawStatus === null ? null : Str::limit($rawStatus, 80, ''),
                    'canonical_status' => $word->status,
                    'occurred_at' => $at,
                    'payload_hash' => $payloadHash,
                    'applied' => $applied,
                    'details' => $details === [] ? null : $details,
                ]);

                return [$row, $applied, $parcel];
            });
        } catch (UniqueConstraintViolationException) {
            // The same webhook, twice, at the same moment: the other one won.
            return null;
        }

        if ($recorded === null) {
            return null;
        }

        [$row, $applied, $parcel] = $recorded;

        if (! $word->known()) {
            // ⚠ Ids and the word only: never the payload.
            Log::warning('Courier status word not understood', [
                'provider' => $account->provider->code,
                'courier_account_id' => $account->id,
                'consignment_id' => $parcel->id,
                'word' => Str::limit($rawStatus ?? $event, 80, ''),
            ]);
        }

        if ($applied) {
            CourierParcelChanged::dispatch(CourierParcelChanged::STATUS, (int) $parcel->business_id, (int) $parcel->delivery_id, (int) $parcel->id, $word->status?->value);
        }

        // ⭐ Joy's decision 2 (31 Oct 2026): delivered with exactly the amount
        // due is recorded by itself, through the person's own action; every
        // other case still waits for a person (AutoDeliverFromCourier).
        if ($applied && $word->status === CourierStatus::Delivered) {
            AutoDeliverFromCourier::attempt($parcel);
        }

        return $row;
    }

    /**
     * A newer word, and one the current state still accepts.
     */
    private function moves(Consignment $parcel, ?CourierStatus $next, Carbon $at): bool
    {
        if ($next === null) {
            return false;
        }

        $current = $parcel->courier_status;

        if ($current !== null && ! $current->accepts($next)) {
            return false;
        }

        return ! ($parcel->courier_status_at !== null && $at->lt($parcel->courier_status_at));
    }

    public static function time(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->setMicrosecond(0);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->setTimezone(config('app.timezone'))->setMicrosecond(0);
            } catch (Throwable) {
                // A time we cannot read is "now", not a failed event.
            }
        }

        if (is_numeric($value) && (int) $value > 0) {
            // Seconds, or milliseconds from a JavaScript clock.
            $seconds = (int) $value > 9_999_999_999 ? intdiv((int) $value, 1000) : (int) $value;

            return Carbon::createFromTimestamp($seconds, config('app.timezone'));
        }

        return now()->setMicrosecond(0);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function whitelist(array $details): array
    {
        $keep = ['collected_amount', 'cod_fee', 'delivery_fee', 'attempt', 'reason', 'invoice_id', 'hub_weight_grams', 'position'];
        $clean = [];

        foreach ($keep as $key) {
            $value = $details[$key] ?? null;

            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            // ⚠ A rider's reason can quote the customer's number.
            $clean[$key] = is_string($value) ? Str::limit(CourierApi::withoutPhones($value), 120, '') : $value;
        }

        return $clean;
    }
}
