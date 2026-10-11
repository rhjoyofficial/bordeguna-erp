<?php

namespace App\Support\Dashboards;

use App\Events\Dashboards\DashboardEventType;
use App\Events\Dashboards\DashboardSignal;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use App\Modules\Sales\Models\CourierStatement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Courier happenings become live dashboard signals, with the sound each
 * deserves (realtime everywhere, 11 Oct 2026):
 *
 *   success    booked, delivered, part delivered, statement imported or posted
 *   attention  delivery failed, on hold, coming back, returned, cancelled, booking unknown
 *   error      lost, damaged, booking failed, a courier API call failing
 *   quiet      anything else (picked up, in transit, at the hub): widgets refresh, nothing plays
 *
 * ⚠ Ids only (DashboardBroadcast). ⚠ Never breaks the write: every send is
 * wrapped and reported.
 */
final class CourierSignals
{
    /** One "the courier is failing" alert per courier account per this many minutes. */
    public const SYNC_ERROR_EVERY_MINUTES = 30;

    public static function register(): void
    {
        Event::listen(CourierParcelChanged::class, fn (CourierParcelChanged $event) => self::send(fn () => self::forParcel($event)));

        CourierStatement::created(fn (CourierStatement $statement) => self::send(fn () => DashboardSignal::business(
            DashboardEventType::STATEMENT_IMPORTED, (int) $statement->business_id, ['statement' => $statement->id], self::actor(),
        )));

        CourierStatement::updated(function (CourierStatement $statement) {
            if ($statement->wasChanged('status') && $statement->status === CourierStatement::POSTED) {
                self::send(fn () => DashboardSignal::business(
                    DashboardEventType::STATEMENT_POSTED, (int) $statement->business_id, ['statement' => $statement->id], self::actor(),
                ));
            }
        });

        // A courier account can serve two businesses: tell each one using it.
        CourierApiCall::created(function (CourierApiCall $call) {
            if ($call->ok) {
                return;
            }

            self::send(function () use ($call) {
                if (! Cache::add('dash:courier-sync-error:'.$call->courier_account_id, 1, now()->addMinutes(self::SYNC_ERROR_EVERY_MINUTES))) {
                    return [];
                }

                return CourierAccountBusiness::query()
                    ->where('courier_account_id', $call->courier_account_id)
                    ->pluck('business_id')
                    ->map(fn ($businessId) => DashboardSignal::business(
                        DashboardEventType::SYNC_ERROR, (int) $businessId, ['courier_account' => (int) $call->courier_account_id], null, 'error',
                    ))
                    ->all();
            });
        });
    }

    public static function forParcel(CourierParcelChanged $event): DashboardSignal
    {
        $ids = array_filter(['delivery' => $event->deliveryId, 'parcel' => $event->consignmentId], fn ($id) => $id !== null);

        return match ($event->type) {
            CourierParcelChanged::BOOKING_FAILED => DashboardSignal::business(DashboardEventType::BOOKING_FAILED, $event->businessId, $ids, self::actor(), 'error'),
            CourierParcelChanged::BOOKING_UNKNOWN => DashboardSignal::business(DashboardEventType::BOOKING_UNKNOWN, $event->businessId, $ids, self::actor(), 'attention'),
            CourierParcelChanged::CANCELLED => DashboardSignal::business(DashboardEventType::PARCEL_STATUS, $event->businessId, $ids, self::actor(), 'attention'),
            default => DashboardSignal::business(DashboardEventType::PARCEL_STATUS, $event->businessId, $ids, self::actor(), self::soundForStatus($event->courierStatus)),
        };
    }

    public static function soundForStatus(?string $status): string
    {
        return match (CourierStatus::tryFrom((string) $status)) {
            CourierStatus::Booked, CourierStatus::Delivered, CourierStatus::PartiallyDelivered, CourierStatus::Exchanged => 'success',
            CourierStatus::DeliveryFailed, CourierStatus::OnHold, CourierStatus::Returning, CourierStatus::ReturnedToMerchant,
            CourierStatus::PaidReturn, CourierStatus::Cancelled => 'attention',
            CourierStatus::Lost, CourierStatus::Damaged => 'error',
            default => DashboardEventType::QUIET,
        };
    }

    /** @param callable(): (DashboardSignal|list<DashboardSignal>|null) $build */
    private static function send(callable $build): void
    {
        try {
            $events = $build();

            foreach (is_array($events) ? $events : [$events] as $event) {
                if ($event !== null) {
                    event($event);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function actor(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }
}
