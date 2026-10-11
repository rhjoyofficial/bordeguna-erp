<?php

namespace App\Modules\Sales\Couriers\Console;

use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Couriers\Booking\SettleBookingRequest;
use App\Modules\Sales\Couriers\Drivers\BooksParcels;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Tracking\RefreshParcelStatus;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The 15-minute pull (courier hub slice 4): for every ACTIVE account, asks the
 * courier about each parcel it has not finished with, oldest-asked first,
 * paced, a bounded number per run. Also settles CarryBee bookings whose answer
 * was lost, by looking our reference up (never by booking again).
 *
 * ⚠ Read-only at the courier and never money: it writes the timeline and the
 * courier status, exactly as the webhook does.
 */
class SyncCourierParcelsCommand extends Command
{
    protected $signature = 'couriers:sync-parcels
        {--account= : Only this courier account id}
        {--limit= : Parcels per account in one run}';

    protected $description = 'Ask the couriers where open parcels are, and settle CarryBee bookings whose answer was lost';

    public function handle(RefreshParcelStatus $refresh, SettleBookingRequest $settle): int
    {
        $limit = (int) ($this->option('limit') ?: config('couriers.parcel_sync_limit', 200));
        $delay = (int) config('couriers.parcel_sync_delay_ms', 300);
        $before = app(BusinessContext::class)->id();

        $accounts = CourierAccount::query()->with('provider')->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        try {
            foreach ($accounts as $account) {
                if (! is_subclass_of((string) $account->definition()->driverClass(), BooksParcels::class)) {
                    continue;
                }

                $asked = 0;

                $parcels = Consignment::query()->withoutGlobalScope(BusinessScope::class)
                    ->where('courier_account_id', $account->id)
                    ->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', CourierStatus::finalValues()))
                    // A parcel still open after two months is a person's job.
                    ->where('created_at', '>=', now()->subDays(60))
                    ->orderByRaw('status_synced_at is not null')->orderBy('status_synced_at')->orderBy('id')
                    ->limit($limit)
                    ->get();

                foreach ($parcels as $parcel) {
                    TenantScope::enter((int) $parcel->business_id);

                    try {
                        [$ok, $message] = $refresh->handle($parcel);

                        if (! $ok) {
                            $this->warn("  {$parcel->consignment_number}: {$message}");
                        }
                    } catch (Throwable $e) {
                        $this->warn("  {$parcel->consignment_number}: ".$e->getMessage());
                    }

                    $asked++;

                    if ($delay > 0) {
                        usleep($delay * 1000);
                    }
                }

                $settled = $this->settleLostBookings($account, $settle);

                $this->info("{$account->label}: asked about {$asked} parcel(s), settled {$settled} lost booking(s).");
            }
        } finally {
            $before === null ? TenantScope::leave() : TenantScope::enter((int) $before);
        }

        return self::SUCCESS;
    }

    private function settleLostBookings(CourierAccount $account, SettleBookingRequest $settle): int
    {
        $settled = 0;

        $open = CourierBookingRequest::query()->withoutGlobalScope(BusinessScope::class)
            ->with('createdBy')
            ->where('courier_account_id', $account->id)
            ->whereIn('status', [CourierBookingRequest::UNKNOWN, CourierBookingRequest::REQUESTED])
            ->where('created_at', '<', now()->subSeconds(CourierBookingRequest::STALE_SECONDS))
            ->whereNull('provider_consignment_id')
            ->orderBy('id')->limit(50)->get();

        foreach ($open as $intent) {
            if (! SettleBookingRequest::canLookUp($intent)) {
                // Pathao: a person decides. Left exactly as it is.
                continue;
            }

            TenantScope::enter((int) $intent->business_id);

            try {
                // The person who pressed Book is recorded as having booked it.
                $settle->lookUp($intent, $intent->createdBy);
                $settled += $intent->fresh()->status !== CourierBookingRequest::UNKNOWN ? 1 : 0;
            } catch (ValidationException|Throwable $e) {
                $this->warn("  booking #{$intent->id}: ".$e->getMessage());
            }
        }

        return $settled;
    }
}
