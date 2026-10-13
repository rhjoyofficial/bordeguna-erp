<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Support\AssetImportPlan;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\CatalogueText;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Works out what a things sheet would do, without doing any of it (asset
 * register bulk import, 13 Oct 2026).
 *
 * ⚠⚠ Every row is checked and every problem collected, the way the catalogue
 * importer does it: a review that reveals one problem per run is a review
 * nobody finishes.
 *
 * ⚠⚠ The sheet never invents master data. A category, place, supplier,
 * employee, outside party or parent tag it does not recognise stops the row.
 * A thing is created with the category's own way of counting; a tag names a
 * thing to correct, and its category and way of counting never change.
 *
 * ⚠⚠ ON A ROW WITH A TAG, A BLANK CELL MEANS "LEAVE IT AS IT IS" (review 13 Oct
 * 2026, ruling 8). An export opened in Excel, two cells corrected and uploaded
 * again must not wipe every brand, date and note the person did not touch. So
 * only a filled cell is applied; clearing a field is the Edit form's job.
 *
 * ⚠⚠ THE SHEET NEVER MOVES A THING (ruling 7). On a row with a tag the place
 * must be the place the register has, the status the one it has (or the one
 * the row's own holder implies), and the holder or outside party the one it
 * has. Anything else is a hand-over or a transfer, which has its own document
 * and its own history; a sheet that could rewrite them would leave a custody
 * chain nobody can trust.
 *
 * ⚠ Requires a tenant to be entered: it reads the business's register through
 * the global scope.
 */
class PlanAssetItemImport
{
    private const MAX_PIECES = 100000;

    private const TAG_PLACEHOLDER = '(new)';

    /**
     * The sheet's optional columns and the attribute each one writes, for the
     * "blank means unchanged" rule on update rows. `place` writes two.
     */
    private const OPTIONAL_COLUMNS = [
        'brand' => 'brand',
        'model' => 'model',
        'serial_number' => 'serial_number',
        'specification' => 'specification',
        'area' => 'area',
        'condition' => 'condition',
        'part_of' => 'parent_id',
        'acquired_on' => 'acquired_on',
        'supplier' => 'supplier_id',
        'purchase_price' => 'purchase_price',
        'warranty_ends_on' => 'warranty_ends_on',
        'note' => 'note',
    ];

    /** @var array<string, AssetCategory> */
    private array $categories = [];

    /** @var array<string, int> */
    private array $offices = [];

    /** @var array<string, int> */
    private array $warehouses = [];

    /** @var array<string, int> */
    private array $suppliers = [];

    /** @var array<string, AssetParty> */
    private array $parties = [];

    /** @var array<string, array{id:int, label:string}|false> false = two employees share the name */
    private array $employees = [];

    /** @var array<string, AssetItem> keyed by upper-case tag */
    private array $items = [];

    /** @var array<string, string> upper-case serial => tag that wears it */
    private array $serialOwners = [];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows, User $by): AssetImportPlan
    {
        $plan = new AssetImportPlan;
        $this->load();

        $seesPrice = $by->can('assets.view');
        $seenSerials = [];
        $seenTags = [];
        $today = Carbon::now()->startOfDay();

        foreach ($rows as $row) {
            $line = $row['line'];
            $v = $row['values'];
            $tag = CatalogueText::clean($v['tag'] ?? '');
            $shown = $tag !== '' ? $tag : self::TAG_PLACEHOLDER;

            // ⚠ Where this row's errors start: every resolver below reports and
            // keeps going, so "did THIS row survive?" is answered by counting.
            $errorsBefore = count($plan->errors);

            $name = CatalogueText::clean($v['name'] ?? '');

            if ($name === '') {
                $plan->addError($line, $shown, 'name is mandatory.');
            } elseif (mb_strlen($name) > 160) {
                $plan->addError($line, $shown, 'name is longer than 160 characters.');
            }

            $category = $this->category($v['category'] ?? '', $line, $shown, $plan);
            $item = null;

            if ($tag !== '') {
                $item = $this->items[mb_strtoupper($tag)] ?? null;

                // ⚠ The same tag twice would correct one thing twice, the second
                // row silently winning: refused (ruling 8).
                if (isset($seenTags[mb_strtoupper($tag)])) {
                    $plan->addError($line, $shown, "tag {$tag} is already on line {$seenTags[mb_strtoupper($tag)]}. One row per thing.");
                } else {
                    $seenTags[mb_strtoupper($tag)] = $line;
                }

                if ($item === null) {
                    $plan->addError($line, $shown, "No thing with tag {$tag} is on this register. Leave tag blank to register a new thing.");
                } elseif ($category !== null && $item->asset_category_id !== $category->id) {
                    $plan->addError($line, $shown, "{$tag} is in category {$item->category?->name}; a thing's category never changes.");
                }

                // ⚠ The export writes who holds it, so a row that says the same
                // passes; a DIFFERENT holder is a hand-over, which the sheet
                // never records on a thing already registered.
                if ($item !== null && (filled($v['holder'] ?? null) || filled($v['outside_party'] ?? null))) {
                    $holderNow = $this->holder($v['holder'] ?? '', $line, $shown, $plan);
                    $partyNow = $this->party($v['outside_party'] ?? '', $line, $shown, $plan);

                    if (($holderNow['id'] ?? null) !== $item->custodian_employee_id || $partyNow?->id !== $item->custodian_party_id) {
                        $plan->addError($line, $shown, "{$tag} is with {$item->custodyLabel()}. The sheet does not move a thing already registered: record a hand-over.");
                    }
                }
            }

            $tracking = $item?->tracking ?? $category?->default_tracking ?? AssetTracking::Individual;
            [$copies, $pieces] = $this->quantity($v['quantity'] ?? '', $tracking, $item, $line, $shown, $plan);

            $serial = CatalogueText::nullIfBlank($v['serial_number'] ?? null);

            if ($serial !== null) {
                $serialKey = mb_strtoupper($serial);

                if ($copies > 1) {
                    $plan->addError($line, $shown, 'A serial number belongs to one thing. A row with a serial number registers one thing.');
                }

                if (isset($seenSerials[$serialKey])) {
                    $plan->addError($line, $shown, "serial_number {$serial} is already on line {$seenSerials[$serialKey]}.");
                } else {
                    $seenSerials[$serialKey] = $line;
                }

                $owner = $this->serialOwners[$serialKey] ?? null;

                if ($owner !== null && $owner !== mb_strtoupper($item?->tag ?? '')) {
                    $plan->addError($line, $shown, "serial_number {$serial} is already on {$owner}.");
                }
            }

            [$officeId, $warehouseId] = $this->place($v['place'] ?? '', $item, $line, $shown, $plan);

            // ⚠ A different place on a thing already registered is a transfer,
            // with its own two-step document; the sheet cannot stand in for it.
            if ($item !== null && filled($v['place'] ?? null) && ($officeId !== null || $warehouseId !== null)
                && ($officeId !== $item->office_id || $warehouseId !== $item->warehouse_id)) {
                $plan->addError($line, $shown, "{$tag} is at {$item->locationLabel()}. Record a transfer; the sheet cannot move a thing.");
            }

            $condition = $this->condition($v['condition'] ?? '', $line, $shown, $plan);
            $parentId = $this->parent($v['part_of'] ?? '', $tracking, $item, $line, $shown, $plan);
            $acquiredOn = $this->date($v['acquired_on'] ?? '', 'acquired_on', $line, $shown, $plan);

            if ($acquiredOn !== null && Carbon::parse($acquiredOn)->gt($today)) {
                $plan->addError($line, $shown, 'acquired_on cannot be in the future.');
            }

            $warrantyEndsOn = $this->date($v['warranty_ends_on'] ?? '', 'warranty_ends_on', $line, $shown, $plan);
            $supplierId = $this->supplier($v['supplier'] ?? '', $line, $shown, $plan);
            $price = $this->price($v['purchase_price'] ?? '', $seesPrice, $line, $shown, $plan);

            $holder = $item === null ? $this->holder($v['holder'] ?? '', $line, $shown, $plan) : null;
            $party = $item === null ? $this->party($v['outside_party'] ?? '', $line, $shown, $plan) : null;

            if ($holder !== null && $party !== null) {
                $plan->addError($line, $shown, 'A thing is with an employee or with an outside party, not both. Fill holder or outside_party.');
            }

            $status = $this->status($v['status'] ?? '', $item, $holder, $party, $line, $shown, $plan);

            if (count($plan->errors) > $errorsBefore || $category === null) {
                continue;
            }

            $attributes = [
                'name' => $name,
                'quantity' => $pieces,
                'brand' => CatalogueText::nullIfBlank($v['brand'] ?? null),
                'model' => CatalogueText::nullIfBlank($v['model'] ?? null),
                'serial_number' => $serial,
                'specification' => CatalogueText::nullIfBlank($v['specification'] ?? null),
                'office_id' => $officeId,
                'warehouse_id' => $warehouseId,
                'area' => CatalogueText::nullIfBlank($v['area'] ?? null),
                'status' => $status,
                'condition' => $condition,
                'parent_id' => $parentId,
                'acquired_on' => $acquiredOn,
                'supplier_id' => $supplierId,
                'purchase_price' => $price,
                'warranty_ends_on' => $warrantyEndsOn,
                'note' => CatalogueText::nullIfBlank($v['note'] ?? null),
            ];

            if ($item === null) {
                $plan->creates[] = [
                    'line' => $line,
                    'name' => $name,
                    'category_id' => $category->id,
                    'category' => $category->name,
                    'tracking' => $tracking->value,
                    'copies' => $copies,
                    'pieces' => $pieces,
                    'attributes' => $attributes,
                    'holder' => $holder,
                    'party' => $party,
                    // The hand-over is dated the day it was bought, or today.
                    'moved_on' => $acquiredOn ?? $today->toDateString(),
                ];

                continue;
            }

            // ⚠⚠ Blank means unchanged on an update row (class comment). The
            // resolvers above answered a blank with "nothing" or a default;
            // here the thing's own value takes that place, so only a filled
            // cell reaches SaveAssetItem, which writes every field it is given.
            foreach (self::OPTIONAL_COLUMNS as $column => $field) {
                if (blank($v[$column] ?? null)) {
                    $attributes[$field] = match ($field) {
                        'condition' => $item->condition->value,
                        'acquired_on', 'warranty_ends_on' => $item->{$field}?->toDateString(),
                        default => $item->{$field},
                    };
                }
            }

            if (blank($v['place'] ?? null)) {
                $attributes['office_id'] = $item->office_id;
                $attributes['warehouse_id'] = $item->warehouse_id;
            }

            // ⚠ A thing's service contract is not on the sheet, and SaveAssetItem
            // writes every field it is given: carried over, or it would be wiped.
            $attributes['service_ends_on'] = $item->service_ends_on?->toDateString();
            $attributes['service_party_id'] = $item->service_party_id;

            $plan->updates[] = [
                'line' => $line,
                'item_id' => $item->id,
                'tag' => $item->tag,
                'name' => $name,
                'attributes' => $attributes,
                'changes' => $this->changes($item, $attributes, $seesPrice),
            ];
        }

        return $plan;
    }

    private function load(): void
    {
        $businessId = app(BusinessContext::class)->id();

        foreach (AssetCategory::query()->get() as $category) {
            $this->categories[mb_strtolower($category->code)] = $category;
            $this->categories[mb_strtolower($category->name)] = $category;
        }

        $this->offices = Office::query()->where('is_active', true)->get(['id', 'name'])
            ->mapWithKeys(fn (Office $office) => [mb_strtolower($office->name) => $office->id])->all();
        $this->warehouses = Warehouse::query()->where('is_active', true)->get(['id', 'name'])
            ->mapWithKeys(fn (Warehouse $warehouse) => [mb_strtolower($warehouse->name) => $warehouse->id])->all();
        $this->suppliers = Supplier::query()->get(['id', 'name'])
            ->mapWithKeys(fn (Supplier $supplier) => [mb_strtolower($supplier->name) => $supplier->id])->all();

        foreach (AssetParty::query()->where('is_active', true)->get() as $party) {
            $this->parties[mb_strtolower($party->name)] = $party;
        }

        // ⚠ The same rule as a hand-over (MoveAssets::receivers): an ACTIVE
        // employee of THIS business. Named by code, or by full name when only
        // one has it.
        $employees = MoveAssets::receivers($businessId)->get(['id', 'employee_code', 'first_name', 'last_name']);

        foreach ($employees as $employee) {
            $entry = ['id' => $employee->id, 'label' => $employee->fullName().' ('.$employee->employee_code.')'];
            $this->employees[mb_strtolower($employee->employee_code)] = $entry;
            $nameKey = mb_strtolower($employee->fullName());
            $this->employees[$nameKey] = array_key_exists($nameKey, $this->employees) ? false : $entry;
        }

        foreach (AssetItem::query()->with('category:id,name')->get() as $item) {
            $this->items[mb_strtoupper($item->tag)] = $item;

            if (filled($item->serial_number)) {
                $this->serialOwners[mb_strtoupper($item->serial_number)] = mb_strtoupper($item->tag);
            }
        }
    }

    private function category(string $raw, int $line, string $tag, AssetImportPlan $plan): ?AssetCategory
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            $plan->addError($line, $tag, 'category is mandatory: a code such as IT, or the category name.');

            return null;
        }

        $category = $this->categories[mb_strtolower($raw)] ?? null;

        if ($category === null) {
            $plan->addError($line, $tag, "category \"{$raw}\" is not one of this business. Add it under Categories first.");

            return null;
        }

        if (! $category->is_active) {
            $plan->addError($line, $tag, "category {$category->name} is switched off. Choose another, or switch it on first.");

            return null;
        }

        return $category;
    }

    /**
     * How many tags a row makes, and how many pieces each carries.
     *
     * @return array{0:int, 1:int}
     */
    private function quantity(string $raw, AssetTracking $tracking, ?AssetItem $item, int $line, string $tag, AssetImportPlan $plan): array
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return $tracking === AssetTracking::Individual ? [1, 1] : [1, $item?->quantity ?? 1];
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            $plan->addError($line, $tag, "quantity \"{$raw}\" is not a whole number.");

            return [1, 1];
        }

        $n = (int) $raw;

        if ($tracking === AssetTracking::Individual) {
            if ($item !== null && $n !== 1) {
                $plan->addError($line, $tag, "{$item->tag} is one tagged thing: quantity must be 1 or blank.");
            } elseif ($n < 1 || $n > SaveAssetItem::MAX_COPIES) {
                $plan->addError($line, $tag, 'quantity for a tagged category is 1 to '.SaveAssetItem::MAX_COPIES.' things per row (each gets its own tag).');
            }

            return [max(1, min($n, SaveAssetItem::MAX_COPIES)), 1];
        }

        if ($n < 1 || $n > self::MAX_PIECES) {
            $plan->addError($line, $tag, 'quantity for a counted group is 1 to '.number_format(self::MAX_PIECES).' pieces.');
        }

        return [1, max(1, $n)];
    }

    /**
     * @return array{0:int|null, 1:int|null}
     */
    private function place(string $raw, ?AssetItem $item, int $line, string $tag, AssetImportPlan $plan): array
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            // A thing being corrected stays where it is; a new one needs a place.
            if ($item === null) {
                $plan->addError($line, $tag, 'place is mandatory: the name of an office or a warehouse.');
            }

            return [null, null];
        }

        if (preg_match('/^(office|warehouse)\s*:\s*(.+)$/iu', $raw, $m) === 1) {
            $kind = mb_strtolower($m[1]);
            $key = mb_strtolower(CatalogueText::clean($m[2]));
            $id = $kind === 'office' ? ($this->offices[$key] ?? null) : ($this->warehouses[$key] ?? null);

            if ($id === null) {
                $plan->addError($line, $tag, "place: no active {$kind} named \"{$m[2]}\" in this business.");

                return [null, null];
            }

            return $kind === 'office' ? [$id, null] : [null, $id];
        }

        $key = mb_strtolower($raw);
        $officeId = $this->offices[$key] ?? null;
        $warehouseId = $this->warehouses[$key] ?? null;

        if ($officeId !== null && $warehouseId !== null) {
            $plan->addError($line, $tag, "place: both an office and a warehouse are named \"{$raw}\". Write office:{$raw} or warehouse:{$raw}.");

            return [null, null];
        }

        if ($officeId === null && $warehouseId === null) {
            $plan->addError($line, $tag, "place \"{$raw}\" is not an active office or warehouse of this business.");

            return [null, null];
        }

        return [$officeId, $warehouseId];
    }

    /**
     * The status the thing is registered with. One with a holder or an outside
     * party is registered in store and moved by the hand-over the row implies;
     * a typed status that says otherwise is a warning, since the register will
     * show the derived one.
     *
     * @param  array{id:int, label:string}|null  $holder
     */
    private function status(string $raw, ?AssetItem $item, ?array $holder, ?AssetParty $party, int $line, string $tag, AssetImportPlan $plan): string
    {
        $raw = mb_strtolower(CatalogueText::clean($raw));
        $typed = $raw === '' ? null : AssetStatus::tryFrom($raw);

        if ($raw !== '' && $typed === null) {
            $plan->addError($line, $tag, "status \"{$raw}\" is not one of: in_store, in_use, under_repair, with_outside_party.");

            return AssetStatus::InStore->value;
        }

        if ($holder !== null || $party !== null) {
            $derived = $holder !== null ? AssetStatus::InUse : $party->kind->statusWhileHolding();

            if ($typed !== null && $typed !== $derived) {
                $who = $holder['label'] ?? $party->label();
                $plan->addWarning($line, $tag, "status {$typed->label()} contradicts the hand-over to {$who}: it will be {$derived->label()}.");
            }

            return AssetStatus::InStore->value;
        }

        if ($typed === null) {
            // ⚠ A thing being corrected keeps its status when the cell is blank.
            return $item?->status->value ?? AssetStatus::InStore->value;
        }

        if ($item !== null && $typed === $item->status) {
            return $typed->value;
        }

        // ⚠⚠ A thing already registered changes status only through the
        // document that causes it (ruling 7): a hand-over, a send-out, a
        // stock-take, a disposal. The sheet may only repeat what the register
        // says, or what the row's own holder implies (handled above).
        if ($item !== null) {
            $plan->addError($line, $tag, "{$item->tag} is {$item->status->label()}, not {$typed->label()}. Record a hand-over; the sheet cannot change a thing's status.");

            return $item->status->value;
        }

        if (! in_array($typed, AssetStatus::settableByHand(), true)) {
            $plan->addError($line, $tag, $typed === AssetStatus::WithOutsideParty
                ? 'status with_outside_party needs outside_party to say who has it.'
                : "status {$typed->label()} is decided by a stock-take or a disposal, not typed. Use in_store, in_use or under_repair.");
        }

        return $typed->value;
    }

    private function condition(string $raw, int $line, string $tag, AssetImportPlan $plan): string
    {
        $raw = mb_strtolower(CatalogueText::clean($raw));

        if ($raw === '') {
            return AssetCondition::Good->value;
        }

        $condition = AssetCondition::tryFrom($raw);

        if ($condition === null) {
            $plan->addError($line, $tag, "condition \"{$raw}\" is not one of: new, good, fair, poor, damaged.");

            return AssetCondition::Good->value;
        }

        return $condition->value;
    }

    private function parent(string $raw, AssetTracking $tracking, ?AssetItem $item, int $line, string $tag, AssetImportPlan $plan): ?int
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        if ($tracking !== AssetTracking::Individual) {
            $plan->addError($line, $tag, 'part_of: a counted group cannot be part of another thing.');

            return null;
        }

        $parent = $this->items[mb_strtoupper($raw)] ?? null;

        if ($parent === null || ! $parent->isIndividual() || $parent->status === AssetStatus::Disposed) {
            $plan->addError($line, $tag, "part_of: {$raw} is not a tagged thing of this business that is still kept.");

            return null;
        }

        if ($item !== null && $parent->id === $item->id) {
            $plan->addError($line, $tag, "part_of: {$item->tag} cannot be part of itself.");

            return null;
        }

        return $parent->id;
    }

    private function date(string $raw, string $column, int $line, string $tag, AssetImportPlan $plan): ?string
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        // ⚠ Y-m-d only. Excel's 10/03/2025 is March in one locale and October
        // in another, and a guess would date half the register wrong.
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? Carbon::createFromFormat('Y-m-d', $raw) : null;

        if ($date === null || $date->format('Y-m-d') !== $raw) {
            $plan->addError($line, $tag, "{$column} \"{$raw}\" must be a date written as YYYY-MM-DD.");

            return null;
        }

        return $raw;
    }

    private function supplier(string $raw, int $line, string $tag, AssetImportPlan $plan): ?int
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        $id = $this->suppliers[mb_strtolower($raw)] ?? null;

        if ($id === null) {
            $plan->addError($line, $tag, "supplier \"{$raw}\" is not one of this business.");
        }

        return $id;
    }

    private function price(string $raw, bool $seesPrice, int $line, string $tag, AssetImportPlan $plan): ?string
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        // ⚠⚠ Someone who may not see prices cannot set them either, and a
        // price silently dropped is a price the person believes was recorded
        // (review 13 Oct 2026, ruling 7): the row is refused, so the sheet
        // comes back with the column blank or from someone who may set it.
        if (! $seesPrice) {
            $plan->addError($line, $tag, 'purchase_price is not yours to set; leave it blank.');

            return null;
        }

        try {
            $price = Money::ofTaka($raw);
        } catch (InvalidArgumentException) {
            $plan->addError($line, $tag, "purchase_price \"{$raw}\" is not a number.");

            return null;
        }

        if ($price->minor < 0) {
            $plan->addError($line, $tag, 'purchase_price cannot be negative.');

            return null;
        }

        return $price->toTaka();
    }

    /**
     * @return array{id:int, label:string}|null
     */
    private function holder(string $raw, int $line, string $tag, AssetImportPlan $plan): ?array
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        $entry = $this->employees[mb_strtolower($raw)] ?? null;

        if ($entry === false) {
            $plan->addError($line, $tag, "holder: more than one employee is named \"{$raw}\". Use the employee code.");

            return null;
        }

        if ($entry === null) {
            $plan->addError($line, $tag, "holder \"{$raw}\" is not an employee code or full name of this business.");

            return null;
        }

        return $entry;
    }

    private function party(string $raw, int $line, string $tag, AssetImportPlan $plan): ?AssetParty
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        $party = $this->parties[mb_strtolower($raw)] ?? null;

        if ($party === null) {
            $plan->addError($line, $tag, "outside_party \"{$raw}\" is not an active outside party of this business. Add it under Outside parties first.");
        }

        return $party;
    }

    /**
     * What the sheet would change on a thing, field by field, as the preview
     * shows it. Price is compared only for someone who may see it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{0:string, 1:string}>
     */
    private function changes(AssetItem $item, array $attributes, bool $seesPrice): array
    {
        $current = [
            'name' => $item->name,
            'quantity' => $item->isIndividual() ? null : (string) $item->quantity,
            'brand' => $item->brand,
            'model' => $item->model,
            'serial_number' => $item->serial_number,
            'specification' => $item->specification,
            'office_id' => $item->office_id,
            'warehouse_id' => $item->warehouse_id,
            'area' => $item->area,
            'status' => $item->status->value,
            'condition' => $item->condition->value,
            'parent_id' => $item->parent_id,
            'acquired_on' => $item->acquired_on?->toDateString(),
            'supplier_id' => $item->supplier_id,
            'purchase_price' => $seesPrice && $item->purchase_price !== null ? Money::ofTaka((string) $item->purchase_price)->toTaka() : null,
            'warranty_ends_on' => $item->warranty_ends_on?->toDateString(),
            'note' => $item->note,
        ];

        $next = $attributes;
        $next['quantity'] = $item->isIndividual() ? null : (string) $attributes['quantity'];
        $next['purchase_price'] = $seesPrice && $attributes['purchase_price'] !== null ? Money::ofTaka((string) $attributes['purchase_price'])->toTaka() : null;

        $changes = [];

        foreach ($current as $field => $was) {
            $will = $next[$field] ?? null;

            if ((string) $was !== (string) $will) {
                $changes[$field] = [(string) $was, (string) $will];
            }
        }

        return $changes;
    }
}
