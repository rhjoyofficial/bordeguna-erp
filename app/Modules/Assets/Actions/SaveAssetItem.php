<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Support\AssetTag;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Register an item, or correct what the register says about one (slice A1).
 *
 * ⚠⚠ Every rule is HERE, not only in the form: one location (an office OR a
 * warehouse) of this business, a status a person may set, a part that belongs
 * to one individual item and never to itself or its own parts, no date of
 * purchase in the future, and a price only from someone who may see prices.
 *
 * ⚠ Nothing here touches the ledger (plan §2.1).
 */
final class SaveAssetItem
{
    public const MAX_COPIES = 50;

    /**
     * Register `$copies` identical individual items (ten chairs are ten tags),
     * or one counted group.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, AssetItem>
     *
     * @throws ValidationException
     */
    public function register(AssetCategory $category, array $attributes, User $by, int $copies = 1): Collection
    {
        $business = Business::query()->findOrFail($category->business_id);

        if (! $category->is_active) {
            throw ValidationException::withMessages(['asset_category_id' => "{$category->name} is switched off. Choose another category."]);
        }

        $tracking = AssetTracking::tryFrom((string) ($attributes['tracking'] ?? '')) ?? $category->default_tracking;

        if ($copies < 1 || $copies > self::MAX_COPIES) {
            throw ValidationException::withMessages(['copies' => 'Register between 1 and '.self::MAX_COPIES.' at once.']);
        }

        if ($copies > 1 && $tracking === AssetTracking::Quantity) {
            throw ValidationException::withMessages(['copies' => 'A counted group is one record: put the number in Quantity.']);
        }

        if ($copies > 1 && filled($attributes['serial_number'] ?? null)) {
            throw ValidationException::withMessages(['copies' => 'A serial number belongs to one item. Register the others separately.']);
        }

        $values = $this->checked($business, $attributes, $by, $tracking, null);

        return DB::transaction(function () use ($category, $business, $values, $tracking, $by, $copies) {
            // ⚠ Numbers are handed out under the category's lock, so two people
            // registering at once never print the same tag.
            AssetCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            $next = (int) AssetItem::query()->withoutGlobalScopes()->where('asset_category_id', $category->id)->max('sequence') + 1;
            $items = collect();

            for ($i = 0; $i < $copies; $i++) {
                $tag = AssetTag::make($business, $category->code, $next + $i);

                if (AssetItem::query()->withoutGlobalScopes()->where('tag', $tag)->exists()) {
                    throw ValidationException::withMessages([
                        'asset_category_id' => "Tag {$tag} is already used by another of the group's businesses. Give this category a different code.",
                    ]);
                }

                $item = AssetItem::create($values + [
                    'business_id' => $business->id,
                    'asset_category_id' => $category->id,
                    'tracking' => $tracking,
                    'tag' => $tag,
                    'sequence' => $next + $i,
                    'created_by' => $by->id,
                ]);

                // A counted group starts with every piece at its own place
                // (slice A2): the holdings row the movements then draw on.
                if ($tracking === AssetTracking::Quantity) {
                    $this->placeHolding($item)->forceFill(['quantity' => $item->quantity])->save();
                }

                $items->push($item);
            }

            return $items;
        });
    }

    /**
     * Correct an item. Its category, tag and way of counting never change.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function update(AssetItem $item, array $attributes, User $by): AssetItem
    {
        $business = Business::query()->findOrFail($item->business_id);

        // ⚠ Missing and disposed are decided by a stock-take and a disposal, not
        // typed over. Such an item keeps its status here.
        if (! in_array($item->status, AssetStatus::settableByHand(), true)) {
            $attributes['status'] = $item->status->value;
        }

        $values = $this->checked($business, $attributes, $by, $item->tracking, $item);

        // ⚠ Someone who may not see prices leaves the price as it was.
        if (! $by->can('assets.view')) {
            unset($values['purchase_price']);
        }

        $wasAt = [$item->office_id, $item->warehouse_id];
        $wasQuantity = $item->quantity;

        return DB::transaction(function () use ($item, $values, $wasAt, $wasQuantity) {
            $item->fill($values)->save();

            // ⚠ A counted group's own row says where its bulk sits and how many
            // pieces exist. Correcting either corrects the place's holding, and
            // never what an employee or a party holds: that is a movement's job.
            if (! $item->isIndividual()) {
                $delta = $item->quantity - $wasQuantity;
                $movedHome = [$item->office_id, $item->warehouse_id] !== $wasAt;

                if ($movedHome) {
                    $old = AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)
                        ->where('holder_key', AssetHolding::keyFor($wasAt[0], $wasAt[1], null, null))->first();
                    // ⚠ The pieces at the old place JOIN whatever the new place
                    // already holds (a part-transfer may have put some there).
                    $carry = (int) ($old?->quantity ?? 0);
                    $old?->delete();
                    $home = $this->placeHolding($item);
                    $home->forceFill(['quantity' => $home->quantity + $carry])->save();
                }

                if ($delta !== 0) {
                    $holding = $this->placeHolding($item);

                    if ($holding->quantity + $delta < 0) {
                        throw ValidationException::withMessages([
                            'quantity' => "Only {$holding->quantity} of {$item->tag} are at its place; the rest are with people. Take them back before counting fewer.",
                        ]);
                    }

                    $holding->forceFill(['quantity' => $holding->quantity + $delta])->save();
                }

                AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->where('quantity', '<=', 0)->delete();
            }

            return $item->fresh();
        });
    }

    /**
     * The holding row of a counted group's own place, made if missing.
     */
    private function placeHolding(AssetItem $item): AssetHolding
    {
        return AssetHolding::query()->withoutGlobalScopes()->firstOrCreate(
            ['asset_item_id' => $item->id, 'holder_key' => AssetHolding::keyFor($item->office_id, $item->warehouse_id, null, null)],
            ['business_id' => $item->business_id, 'office_id' => $item->office_id, 'warehouse_id' => $item->warehouse_id, 'quantity' => 0],
        );
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(Business $business, array $a, User $by, AssetTracking $tracking, ?AssetItem $item): array
    {
        $name = trim((string) ($a['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name the item.']);
        }

        $quantity = $tracking === AssetTracking::Individual ? 1 : (int) ($a['quantity'] ?? 0);

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'A counted group holds at least one.']);
        }

        [$officeId, $warehouseId] = $this->place($business, $a);

        $status = AssetStatus::tryFrom((string) ($a['status'] ?? ''));
        $keeping = $item !== null && $status === $item->status;

        if ($status === null || (! $keeping && ! in_array($status, AssetStatus::settableByHand(), true))) {
            throw ValidationException::withMessages(['status' => 'Choose In store, In use or Under repair.']);
        }

        $condition = AssetCondition::tryFrom((string) ($a['condition'] ?? ''));

        if ($condition === null) {
            throw ValidationException::withMessages(['condition' => 'Say what condition it is in.']);
        }

        $acquired = filled($a['acquired_on'] ?? null) ? Carbon::parse($a['acquired_on'])->startOfDay() : null;

        if ($acquired?->isFuture()) {
            throw ValidationException::withMessages(['acquired_on' => 'It cannot have been bought in the future.']);
        }

        $supplierId = filled($a['supplier_id'] ?? null) ? (int) $a['supplier_id'] : null;

        if ($supplierId !== null && ! Supplier::query()->withoutGlobalScopes()->whereKey($supplierId)->where('business_id', $business->id)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'That supplier is not one of this business.']);
        }

        $price = null;

        if ($by->can('assets.view') && filled($a['purchase_price'] ?? null)) {
            $price = Money::ofTaka((string) $a['purchase_price']);

            if ($price->minor < 0) {
                throw ValidationException::withMessages(['purchase_price' => 'A price cannot be negative.']);
            }
        }

        return [
            'name' => mb_substr($name, 0, 160),
            'quantity' => $quantity,
            'brand' => $this->text($a['brand'] ?? null, 80),
            'model' => $this->text($a['model'] ?? null, 120),
            'serial_number' => $this->text($a['serial_number'] ?? null, 120),
            'specification' => $this->text($a['specification'] ?? null, 5000),
            'office_id' => $officeId,
            'warehouse_id' => $warehouseId,
            'area' => $this->text($a['area'] ?? null, 120),
            'status' => $status,
            'condition' => $condition,
            'parent_id' => $this->parent($business, $a['parent_id'] ?? null, $tracking, $item),
            'acquired_on' => $acquired,
            'supplier_id' => $supplierId,
            'purchase_price' => $price?->toTaka(),
            'warranty_ends_on' => filled($a['warranty_ends_on'] ?? null) ? Carbon::parse($a['warranty_ends_on'])->startOfDay() : null,
            'note' => $this->text($a['note'] ?? null, 5000),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{0: int|null, 1: int|null}
     */
    private function place(Business $business, array $a): array
    {
        $officeId = filled($a['office_id'] ?? null) ? (int) $a['office_id'] : null;
        $warehouseId = filled($a['warehouse_id'] ?? null) ? (int) $a['warehouse_id'] : null;

        if (($officeId === null) === ($warehouseId === null)) {
            throw ValidationException::withMessages(['location' => 'Choose where it is: one office or one warehouse.']);
        }

        $exists = $officeId !== null
            ? Office::query()->withoutGlobalScopes()->whereKey($officeId)->where('business_id', $business->id)->where('is_active', true)->exists()
            : Warehouse::query()->withoutGlobalScopes()->whereKey($warehouseId)->where('business_id', $business->id)->where('is_active', true)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['location' => 'That place is not an active office or warehouse of this business.']);
        }

        return [$officeId, $warehouseId];
    }

    private function parent(Business $business, mixed $parentId, AssetTracking $tracking, ?AssetItem $item): ?int
    {
        if (blank($parentId)) {
            return null;
        }

        if ($tracking !== AssetTracking::Individual) {
            throw ValidationException::withMessages(['parent_id' => 'A counted group cannot be part of another item.']);
        }

        /** @var AssetItem|null $parent */
        $parent = AssetItem::query()->withoutGlobalScopes()->whereKey((int) $parentId)->where('business_id', $business->id)->first();

        if ($parent === null || ! $parent->isIndividual() || $parent->status === AssetStatus::Disposed) {
            throw ValidationException::withMessages(['parent_id' => 'An item can only be part of another tagged item of this business that is still kept.']);
        }

        // ⚠ Never part of itself, nor of one of its own parts: walk up from the
        // chosen parent and refuse if the chain reaches this item.
        if ($item !== null) {
            for ($cursor = $parent, $steps = 0; $cursor !== null && $steps < 20; $steps++) {
                if ($cursor->id === $item->id) {
                    throw ValidationException::withMessages(['parent_id' => "{$item->tag} cannot be part of itself or of one of its own parts."]);
                }

                $cursor = $cursor->parent_id ? AssetItem::query()->withoutGlobalScopes()->find($cursor->parent_id) : null;
            }
        }

        return $parent->id;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
