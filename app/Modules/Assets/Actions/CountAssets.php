<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\DiscrepancyKind;
use App\Modules\Assets\Enums\DiscrepancyResolution;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetCountLine;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetDiscrepancy;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A stock-take of one place (asset management slice A3, plan §4d).
 *
 *   start    snapshot what the register expects at the place
 *   mark     Found (with condition, or a piece count) or Missing, explicitly
 *   add      something found there that was not expected, by its tag
 *   close    every expected line marked; gaps become discrepancies; a missing
 *            tagged thing becomes Missing; conditions noted are written
 *   resolve  located / wrongly recorded / found damaged / confirmed lost
 *
 * ⚠⚠ Nothing here is inferred from somebody opening a page. Only a mark counts.
 * ⚠⚠ Confirmed lost is the write-off decision: `assets.items.write_off` only.
 */
final class CountAssets
{
    /**
     * ⭐ FEA-006 (14 Oct 2026): a thing that went missing takes its passwords
     * with it, so its logins are flagged for review, as a return or a
     * reassignment already flags them (FlagCredentialsForReview).
     */
    private function flagLoginsForReview(AssetItem $item, User $by, AssetCount $count): void
    {
        $flagged = AssetCredential::query()->withoutGlobalScopes()
            ->where('credentialable_type', $item->getMorphClass())
            ->where('credentialable_id', $item->id)
            ->where('needs_review', false)
            ->update(['needs_review' => true]);

        if ($flagged > 0) {
            activity('assets.secrets')
                ->performedOn($item)
                ->causedBy($by)
                ->log("{$flagged} login(s) of {$item->tag} {$item->name} flagged for review after it was marked missing ({$count->number})");
        }
    }

    /**
     * @param  array{office_id?: int|null, warehouse_id?: int|null}  $place
     *
     * @throws ValidationException
     */
    public function start(Business $business, array $place, User $by, ?string $note = null): AssetCount
    {
        $officeId = filled($place['office_id'] ?? null) ? (int) $place['office_id'] : null;
        $warehouseId = filled($place['warehouse_id'] ?? null) ? (int) $place['warehouse_id'] : null;

        if (($officeId === null) === ($warehouseId === null)) {
            throw ValidationException::withMessages(['place' => 'Choose the place to count: one office or one warehouse.']);
        }

        $exists = $officeId !== null
            ? Office::query()->withoutGlobalScopes()->whereKey($officeId)->where('business_id', $business->id)->where('is_active', true)->exists()
            : Warehouse::query()->withoutGlobalScopes()->whereKey($warehouseId)->where('business_id', $business->id)->where('is_active', true)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['place' => 'That place is not an active office or warehouse of this business.']);
        }

        $key = AssetHolding::keyFor($officeId, $warehouseId, null, null);

        try {
            return DB::transaction(function () use ($business, $officeId, $warehouseId, $key, $by, $note) {
                $last = (int) AssetCount::query()->withoutGlobalScopes()->where('business_id', $business->id)
                    ->selectRaw('max(cast(substr(number, 4) as signed)) as n')->value('n');

                $count = AssetCount::create([
                    'business_id' => $business->id,
                    'number' => sprintf('AC-%06d', $last + 1),
                    'office_id' => $officeId,
                    'warehouse_id' => $warehouseId,
                    'open_place_key' => $key,
                    'note' => $note,
                    'started_by' => $by->id,
                ]);

                // Tagged things whose place this is, still kept, not on their
                // way and not away with an outside party. A thing issued to an
                // employee still sits in the place (a laptop on a desk).
                $individual = AssetItem::query()->withoutGlobalScopes()
                    ->where('business_id', $business->id)
                    ->where('tracking', 'individual')
                    ->when($officeId, fn ($q) => $q->where('office_id', $officeId), fn ($q) => $q->where('warehouse_id', $warehouseId))
                    ->where('status', '!=', AssetStatus::Disposed->value)
                    ->whereNull('transit_movement_id')
                    ->whereNull('custodian_party_id')
                    ->orderBy('tag')->pluck('id');

                foreach ($individual as $id) {
                    $count->lines()->create(['asset_item_id' => $id, 'expected' => true, 'expected_quantity' => 1]);
                }

                // Counted groups with pieces at this place: that many expected.
                $pieces = AssetHolding::query()->withoutGlobalScopes()
                    ->where('business_id', $business->id)->where('holder_key', $key)->where('quantity', '>', 0)
                    ->pluck('quantity', 'asset_item_id');

                foreach ($pieces as $itemId => $quantity) {
                    $count->lines()->create(['asset_item_id' => $itemId, 'expected' => true, 'expected_quantity' => $quantity]);
                }

                return $count->fresh('lines');
            });
        } catch (QueryException $e) {
            // The unique open_place_key: a count of this place is already open.
            if (AssetCount::query()->withoutGlobalScopes()->where('open_place_key', $key)->exists()) {
                throw ValidationException::withMessages(['place' => 'A count of that place is already open. Finish it first.']);
            }

            throw $e;
        }
    }

    /**
     * Say what the counter saw. A tagged thing is found or not; a group is a
     * number of pieces (0 is missing).
     *
     * @throws ValidationException
     */
    public function mark(AssetCount $count, AssetItem $item, bool $found, User $by, ?int $pieces = null, ?string $condition = null, ?string $note = null): AssetCountLine
    {
        return DB::transaction(function () use ($count, $item, $found, $by, $pieces, $condition, $note) {
            $locked = $this->openCount($count);

            /** @var AssetCountLine|null $line */
            $line = $locked->lines()->where('asset_item_id', $item->id)->lockForUpdate()->first();

            if ($line === null) {
                throw ValidationException::withMessages(['item' => "{$item->tag} is not on {$locked->number}. If it is there, add it as found."]);
            }

            $this->write($line, $item, $found, $by, $pieces, $condition, $note);

            return $line->fresh();
        });
    }

    /**
     * Something found at the place that the register did not expect there.
     *
     * @throws ValidationException
     */
    public function addFound(AssetCount $count, AssetItem $item, User $by, ?int $pieces = null, ?string $condition = null, ?string $note = null): AssetCountLine
    {
        return DB::transaction(function () use ($count, $item, $by, $pieces, $condition, $note) {
            $locked = $this->openCount($count);

            if ($item->business_id !== $locked->business_id) {
                throw ValidationException::withMessages(['item' => 'That thing is not in this business\'s register.']);
            }

            if ($item->status === AssetStatus::Disposed) {
                throw ValidationException::withMessages(['item' => "{$item->tag} was disposed of. Tell whoever keeps the register."]);
            }

            if ($locked->lines()->where('asset_item_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['item' => "{$item->tag} is already on {$locked->number}. Mark it there."]);
            }

            $line = $locked->lines()->create(['asset_item_id' => $item->id, 'expected' => false, 'expected_quantity' => 0]);
            $this->write($line, $item, true, $by, $pieces, $condition, $note);

            return $line->fresh();
        });
    }

    /**
     * Close the count: every expected line marked, gaps written down.
     *
     * @throws ValidationException
     */
    public function close(AssetCount $count, User $by): AssetCount
    {
        return DB::transaction(function () use ($count, $by) {
            $locked = $this->openCount($count);
            $lines = $locked->lines()->with('item')->get();

            $unmarked = $lines->filter(fn (AssetCountLine $line) => $line->expected && ! $line->isMarked());

            if ($unmarked->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'count' => $unmarked->count().' still to mark: '.$unmarked->take(5)->map(fn ($line) => $line->item->tag)->implode(', ').($unmarked->count() > 5 ? '...' : '').'.',
                ]);
            }

            foreach ($lines as $line) {
                /** @var AssetItem $item */
                $item = AssetItem::query()->withoutGlobalScopes()->whereKey($line->asset_item_id)->lockForUpdate()->firstOrFail();
                $gap = $this->gap($line, $item);

                if ($line->condition !== null && $line->found) {
                    $item->forceFill(['condition' => $line->condition])->save();
                }

                if ($gap !== null) {
                    [$kind, $quantity] = $gap;

                    AssetDiscrepancy::create([
                        'business_id' => $locked->business_id,
                        'asset_count_id' => $locked->id,
                        'asset_item_id' => $item->id,
                        'kind' => $kind,
                        'quantity' => $quantity,
                        'status_before' => $kind === DiscrepancyKind::Missing ? $item->status->value : null,
                        'responsible_user_id' => $by->id,
                    ]);

                    if ($kind === DiscrepancyKind::Missing) {
                        $item->forceFill(['status' => AssetStatus::Missing])->save();
                        $this->flagLoginsForReview($item, $by, $locked);
                    }
                }

                // ⭐ A thing an earlier count lost, found here: that gap closes itself.
                if ($line->found && $item->isIndividual()) {
                    foreach (AssetDiscrepancy::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->where('kind', DiscrepancyKind::Missing->value)
                        ->where('state', 'open')->where('asset_count_id', '!=', $locked->id)->get() as $earlier) {
                        $this->settle($earlier, DiscrepancyResolution::Located, $by, "Found in {$locked->number}.");
                    }
                }

                activity('assets.items')->performedOn($item)->causedBy($by)->log($this->words($line, $locked));
            }

            $locked->forceFill(['status' => 'closed', 'open_place_key' => null, 'closed_by' => $by->id, 'closed_at' => Carbon::now()])->save();

            return $locked->fresh(['lines', 'discrepancies']);
        });
    }

    /**
     * Settle a gap.
     *
     * @throws ValidationException
     */
    public function resolve(AssetDiscrepancy $discrepancy, DiscrepancyResolution $resolution, User $by, ?string $note = null): AssetDiscrepancy
    {
        if (! in_array($resolution, $discrepancy->kind->resolutions(), true)) {
            throw ValidationException::withMessages(['resolution' => "\"{$resolution->label()}\" does not settle \"{$discrepancy->kind->label()}\"."]);
        }

        if ($resolution === DiscrepancyResolution::ConfirmedLost && ! $by->can('assets.items.write_off')) {
            throw ValidationException::withMessages(['resolution' => 'Only the Owner or a Manager confirms a loss.']);
        }

        return DB::transaction(function () use ($discrepancy, $resolution, $by, $note) {
            /** @var AssetDiscrepancy $locked */
            $locked = AssetDiscrepancy::query()->withoutGlobalScopes()->whereKey($discrepancy->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['resolution' => 'That gap is already settled.']);
            }

            $this->settle($locked, $resolution, $by, $note);

            return $locked->fresh();
        });
    }

    private function settle(AssetDiscrepancy $discrepancy, DiscrepancyResolution $resolution, User $by, ?string $note): void
    {
        /** @var AssetItem $item */
        $item = AssetItem::query()->withoutGlobalScopes()->whereKey($discrepancy->asset_item_id)->lockForUpdate()->firstOrFail();
        $count = AssetCount::query()->withoutGlobalScopes()->findOrFail($discrepancy->asset_count_id);
        $placeKey = AssetHolding::keyFor($count->office_id, $count->warehouse_id, null, null);

        switch ([$discrepancy->kind, $resolution]) {
            case [DiscrepancyKind::Missing, DiscrepancyResolution::Located]:
            case [DiscrepancyKind::Missing, DiscrepancyResolution::WronglyRecorded]:
                // ⚠ Only if nothing else changed its status since.
                if ($item->status === AssetStatus::Missing) {
                    $item->forceFill(['status' => AssetStatus::tryFrom((string) $discrepancy->status_before) ?? AssetStatus::InStore])->save();
                }
                break;

            case [DiscrepancyKind::Missing, DiscrepancyResolution::FoundDamaged]:
                $item->forceFill([
                    'condition' => AssetCondition::Damaged,
                    'status' => $item->status === AssetStatus::Missing ? (AssetStatus::tryFrom((string) $discrepancy->status_before) ?? AssetStatus::InStore) : $item->status,
                ])->save();
                break;

            case [DiscrepancyKind::Short, DiscrepancyResolution::ConfirmedLost]:
                $holding = AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->where('holder_key', $placeKey)->lockForUpdate()->first();

                if ($holding === null || $holding->quantity < $discrepancy->quantity || $item->quantity < $discrepancy->quantity) {
                    throw ValidationException::withMessages(['resolution' => "{$item->tag} no longer has {$discrepancy->quantity} at {$count->placeLabel()} to write off. Count it again."]);
                }

                $holding->quantity === $discrepancy->quantity
                    ? $holding->delete()
                    : $holding->forceFill(['quantity' => $holding->quantity - $discrepancy->quantity])->save();
                $item->forceFill(['quantity' => $item->quantity - $discrepancy->quantity])->save();
                break;

            case [DiscrepancyKind::Over, DiscrepancyResolution::Located]:
                $holding = AssetHolding::query()->withoutGlobalScopes()->firstOrCreate(
                    ['asset_item_id' => $item->id, 'holder_key' => $placeKey],
                    ['business_id' => $item->business_id, 'office_id' => $count->office_id, 'warehouse_id' => $count->warehouse_id, 'quantity' => 0],
                );
                $holding->forceFill(['quantity' => $holding->quantity + $discrepancy->quantity])->save();
                $item->forceFill(['quantity' => $item->quantity + $discrepancy->quantity])->save();
                break;

            default:
                // Nothing changes on the item: missing and lost stays Missing;
                // an unexpected thing is put right by a hand-over; a short that
                // turned up or an over that was a miscount changes nothing.
                break;
        }

        $discrepancy->forceFill([
            'state' => 'resolved',
            'resolution' => $resolution,
            'resolved_by' => $by->id,
            'resolved_at' => Carbon::now(),
            'note' => $note !== null ? mb_substr($note, 0, 500) : $discrepancy->note,
        ])->save();

        activity('assets.items')->performedOn($item)->causedBy($by)
            ->log("{$count->number}: {$discrepancy->kind->label()} settled as {$resolution->label()}".($note ? " ({$note})" : ''));
    }

    private function write(AssetCountLine $line, AssetItem $item, bool $found, User $by, ?int $pieces, ?string $condition, ?string $note): void
    {
        $conditionValue = null;

        if (filled($condition)) {
            $conditionValue = AssetCondition::tryFrom((string) $condition);

            if ($conditionValue === null) {
                throw ValidationException::withMessages(['condition' => 'Say what condition it is in.']);
            }
        }

        if ($item->isIndividual()) {
            $quantity = $found ? 1 : 0;
        } else {
            if ($found && ($pieces === null || $pieces < 1)) {
                throw ValidationException::withMessages(['pieces' => "Say how many pieces of {$item->tag} are there."]);
            }

            $quantity = $found ? $pieces : 0;
        }

        $line->forceFill([
            'found' => $found,
            'found_quantity' => $quantity,
            'condition' => $found ? $conditionValue : null,
            'marked_by' => $by->id,
            'marked_at' => Carbon::now(),
            'note' => $note !== null ? mb_substr($note, 0, 255) : $line->note,
        ])->save();
    }

    /**
     * @return array{0: DiscrepancyKind, 1: int}|null
     */
    private function gap(AssetCountLine $line, AssetItem $item): ?array
    {
        if (! $line->expected) {
            return [DiscrepancyKind::Unexpected, max(1, (int) $line->found_quantity)];
        }

        if ($item->isIndividual()) {
            return $line->found ? null : [DiscrepancyKind::Missing, 1];
        }

        $found = (int) $line->found_quantity;

        return match (true) {
            $found < $line->expected_quantity => [DiscrepancyKind::Short, $line->expected_quantity - $found],
            $found > $line->expected_quantity => [DiscrepancyKind::Over, $found - $line->expected_quantity],
            default => null,
        };
    }

    private function words(AssetCountLine $line, AssetCount $count): string
    {
        if (! $line->expected) {
            return "{$count->number}: found at {$count->placeLabel()}, not expected there";
        }

        return $line->found
            ? "{$count->number}: counted at {$count->placeLabel()}".($line->expected_quantity > 1 || $line->found_quantity > 1 ? " ({$line->found_quantity} of {$line->expected_quantity})" : '')
            : "{$count->number}: not found at {$count->placeLabel()}";
    }

    private function openCount(AssetCount $count): AssetCount
    {
        /** @var AssetCount $locked */
        $locked = AssetCount::query()->withoutGlobalScopes()->whereKey($count->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['count' => "{$locked->number} is closed."]);
        }

        return $locked;
    }
}
