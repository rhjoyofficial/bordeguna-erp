<?php

namespace App\Modules\Sales\Couriers\Console;

use App\Modules\Sales\Couriers\Locations\LocationSync;
use App\Modules\Sales\Couriers\Locations\SyncCourierLocations;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Reads a courier's city, zone and area lists through one ACTIVE account, then
 * proposes maps. The lists are provider-wide, so any active account of that
 * courier will do. An unfinished earlier run is picked up where it stopped.
 */
class SyncCourierLocationsCommand extends Command
{
    protected $signature = 'couriers:sync-locations {account : The courier account id (Settings > Couriers)}';

    protected $description = 'Queue a read-only sync of a courier\'s city, zone and area lists, then match them to Bangladesh\'s thanas';

    public function handle(LocationSync $sync): int
    {
        $account = CourierAccount::query()->with('provider')->find((int) $this->argument('account'));

        if ($account === null) {
            $this->error('No courier account with that id.');

            return self::FAILURE;
        }

        try {
            $run = $sync->start($account);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        SyncCourierLocations::dispatch($run->id);

        $run->refresh();
        $this->info(sprintf(
            'Sync #%d for %s through "%s": %s. %d cities, %d zones, %d areas, %d calls so far.%s',
            $run->id,
            $account->definition()->name(),
            $account->label,
            $run->status,
            $run->cities,
            $run->zones,
            $run->areas,
            $run->calls,
            $run->last_error ? ' Last error: '.$run->last_error : '',
        ));

        return self::SUCCESS;
    }
}
