<?php

namespace App\Modules\Sales\Couriers\Locations;

use App\Models\User;
use App\Modules\Sales\Couriers\Drivers\ListsLocations;
use App\Modules\Sales\Couriers\Drivers\LocationList;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Copies one courier's coverage list (cities, then each city's zones, then
 * each zone's areas) into `courier_locations`, read-only.
 *
 * ⚠⚠ RESUMABLE BY CONSTRUCTION. A parent's children are fetched once per run:
 * `children_synced_at` later than the run's start means "done in this run", so
 * a run picked up again after a crash, a failed call or a worker restart skips
 * straight to what is left. Nothing is held in memory between batches.
 *
 * ⚠ Idempotent: rows are upserted by (provider, level, id). A child the courier
 * stopped listing under a parent gets `removed_at` and is never deleted, so a
 * map pointing at it fails in the resolver instead of quietly vanishing. A
 * FAILED call never marks anything removed: an error is not an empty list.
 */
class LocationSync
{
    /**
     * Start a run, or pick up the provider's unfinished one.
     *
     * @throws ValidationException
     */
    public function start(CourierAccount $account, ?User $by = null): CourierLocationSync
    {
        $provider = $account->provider->code;

        if (! $account->is_active) {
            throw ValidationException::withMessages(['sync' => "{$account->label} is switched off. Lists are read only through an active, tested account."]);
        }

        if (! $this->driver($account) instanceof ListsLocations) {
            throw ValidationException::withMessages(['sync' => $account->definition()->name().' cannot list its locations through the ERP yet.']);
        }

        $open = CourierLocationSync::query()->where('provider', $provider)
            ->whereIn('status', [CourierLocationSync::RUNNING, CourierLocationSync::FAILED])
            ->latest('id')->first();

        if ($open !== null) {
            $open->forceFill(['status' => CourierLocationSync::RUNNING, 'courier_account_id' => $account->id, 'last_error' => null])->save();

            return $open;
        }

        return CourierLocationSync::create([
            'provider' => $provider,
            'courier_account_id' => $account->id,
            'status' => CourierLocationSync::RUNNING,
            'started_by' => $by?->id,
            'started_at' => now(),
        ]);
    }

    /**
     * Make at most `$budget` calls. True when the whole list is synced.
     */
    public function step(CourierLocationSync $run, int $budget): bool
    {
        $account = $run->account()->with('provider')->first();

        if ($account === null || ! $account->is_active) {
            $this->fail($run, 'The account used for this sync is no longer active.');

            return false;
        }

        $driver = $this->driver($account);

        if (! $driver instanceof ListsLocations) {
            $this->fail($run, 'This courier cannot list its locations.');

            return false;
        }

        $provider = $run->provider;
        $since = $run->started_at ?? now();
        $calls = 0;

        // 1. Cities, once per run.
        if ($run->cities === 0) {
            $list = $driver->cities($account);
            $calls++;

            if (! $list->ok() || $list->items === []) {
                $this->fail($run, 'City list: '.($list->error ?? 'the courier returned no cities.'), $calls);

                return false;
            }

            $this->store($provider, CourierLocation::CITY, null, $list);
            $this->markRemoved($provider, CourierLocation::CITY, null, $list);
            $run->forceFill(['cities' => count($list->items)])->save();
        }

        // 2. Each city's zones, then 3. each zone's areas.
        foreach ([[CourierLocation::CITY, CourierLocation::ZONE, 'zones'], [CourierLocation::ZONE, CourierLocation::AREA, 'areas']] as [$parentLevel, $childLevel, $counter]) {
            $parents = $this->pendingParents($provider, $parentLevel, $since);

            foreach ($parents as $parent) {
                if ($calls >= $budget) {
                    $run->increment('calls', $calls);

                    return false;
                }

                $this->pause($calls);

                $list = $childLevel === CourierLocation::ZONE
                    ? $driver->zones($account, $parent->provider_location_id)
                    : $driver->areas($account, (string) $parent->parent_provider_id, $parent->provider_location_id);
                $calls++;

                if (! $list->ok()) {
                    $this->fail($run, ucfirst($counter)." of {$parent->name}: {$list->error}", $calls);

                    return false;
                }

                // ⚠⚠ The key assumes a zone id (or an area id) is unique across
                // the whole courier, which the ids seen so far suggest but no
                // document promises. If the same id turns up under a second
                // parent in one run, an upsert would silently move it and every
                // map pointing at it would send parcels to the other city.
                $clash = $this->idSeenUnderAnotherParent($provider, $childLevel, $parent->provider_location_id, $list, $since);

                if ($clash !== null) {
                    $this->fail($run, "{$clash} is listed under two different parents, so its id is not unique. Sync stopped before anything was moved; this needs a developer.", $calls);

                    return false;
                }

                $this->store($provider, $childLevel, $parent->provider_location_id, $list);
                $this->markRemoved($provider, $childLevel, $parent->provider_location_id, $list);
                $parent->forceFill(['children_synced_at' => now()])->save();
                $run->increment($counter, count($list->items));
            }
        }

        $run->increment('calls', $calls);
        $run->forceFill(['status' => CourierLocationSync::DONE, 'finished_at' => now(), 'last_error' => null])->save();

        return true;
    }

    /**
     * Listed parents whose children were not fetched in this run. Ordered by
     * id so a resumed run walks the same way.
     *
     * @return Collection<int, CourierLocation>
     */
    private function pendingParents(string $provider, string $level, Carbon $since)
    {
        return CourierLocation::query()->listed()
            ->where('provider', $provider)->where('level', $level)
            ->where(fn ($q) => $q->whereNull('children_synced_at')->orWhere('children_synced_at', '<', $since))
            // A zone whose city is gone is not worth a call.
            ->when($level === CourierLocation::ZONE, fn ($q) => $q->whereIn('parent_provider_id', CourierLocation::query()->listed()
                ->where('provider', $provider)->where('level', CourierLocation::CITY)->select('provider_location_id')))
            ->orderBy('id')
            ->get();
    }

    private function store(string $provider, string $level, ?string $parentId, LocationList $list): void
    {
        $now = now();
        $rows = array_map(fn (array $item) => [
            'provider' => $provider,
            'level' => $level,
            'provider_location_id' => $item['id'],
            'parent_provider_id' => $parentId,
            'name' => mb_substr($item['name'], 0, 191),
            'extra' => $item['extra'] === [] ? null : json_encode($item['extra']),
            'synced_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $list->items);

        foreach (array_chunk($rows, 500) as $chunk) {
            CourierLocation::query()->upsert(
                $chunk,
                ['provider', 'level', 'provider_location_id'],
                ['parent_provider_id', 'name', 'extra', 'synced_at', 'removed_at', 'updated_at'],
            );
        }
    }

    private function idSeenUnderAnotherParent(string $provider, string $level, string $parentId, LocationList $list, Carbon $since): ?string
    {
        $ids = array_column($list->items, 'id');

        if ($ids === []) {
            return null;
        }

        $row = CourierLocation::query()
            ->where('provider', $provider)->where('level', $level)
            ->whereIn('provider_location_id', $ids)
            ->where('parent_provider_id', '!=', $parentId)
            ->whereNull('removed_at')
            ->where('synced_at', '>=', $since)
            ->first();

        return $row === null ? null : ucfirst($level)." \"{$row->name}\" ({$row->provider_location_id})";
    }

    private function markRemoved(string $provider, string $level, ?string $parentId, LocationList $list): void
    {
        CourierLocation::query()
            ->where('provider', $provider)->where('level', $level)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_provider_id'), fn ($q) => $q->where('parent_provider_id', $parentId))
            ->whereNotIn('provider_location_id', array_column($list->items, 'id'))
            ->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
    }

    private function fail(CourierLocationSync $run, string $error, int $calls = 0): void
    {
        $run->forceFill([
            'status' => CourierLocationSync::FAILED,
            'last_error' => mb_substr($error, 0, 500),
            'calls' => $run->calls + $calls,
        ])->save();
    }

    private function pause(int $callsSoFar): void
    {
        $ms = (int) config('couriers.location_sync_delay_ms', 250);

        if ($callsSoFar > 0 && $ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function driver(CourierAccount $account): ?object
    {
        $class = $account->definition()->driverClass();

        return $class === null ? null : app($class);
    }
}
