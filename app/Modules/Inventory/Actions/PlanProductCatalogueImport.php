<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\CatalogueImportPlan;
use App\Modules\Inventory\Support\CatalogueText;
use Illuminate\Support\Collection;

/**
 * Works out what an import would do, without doing any of it.
 *
 * ⚠⚠ Every row is validated, and every problem collected. It deliberately does
 * NOT stop at the first error: a review that reveals one problem per run is a
 * review nobody finishes, and the point of this stage is that Joy sees the
 * whole state of his file at once.
 *
 * ⚠ Requires a tenant to be entered - it reads the business's existing
 * products, categories and units through the global scope.
 */
class PlanProductCatalogueImport
{
    private const TRUE_WORDS = ['1', 'true', 'yes', 'y', 'active'];

    private const FALSE_WORDS = ['0', 'false', 'no', 'n', 'inactive'];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): CatalogueImportPlan
    {
        $plan = new CatalogueImportPlan;

        $existingProducts = Product::all()->keyBy('sku');
        $existingCategories = Category::all()->keyBy(fn (Category $c) => CatalogueText::categoryKey($c->name));
        $existingUnits = UnitOfMeasure::all()->keyBy(fn (UnitOfMeasure $u) => CatalogueText::unitKey($u->abbreviation));

        // ⚠⚠ Brands are RESOLVED, never created (D-4). Keyed by both code and
        // name so the file may say `LLD` or `LaLaDia` and mean the same thing.
        // Unlike categories, an unrecognised brand STOPS the run: brands are
        // few and deliberate, and auto-creating on a typo produces "Bionc"
        // which nobody finds until a profit report is short.
        $brands = Brand::all();
        $existingBrands = collect();

        foreach ($brands as $brand) {
            $existingBrands->put(CatalogueText::unitKey($brand->code), $brand);
            $existingBrands->put(CatalogueText::categoryKey($brand->name), $brand);
        }

        // Barcode → SKU, so a barcode already worn by a different product is
        // caught against the database as well as within the file.
        $barcodeOwners = $existingProducts
            ->filter(fn (Product $p) => filled($p->barcode))
            ->mapWithKeys(fn (Product $p) => [$p->barcode => $p->sku]);

        $seenSkus = [];
        $seenBarcodes = [];
        $categoryNames = [];   // key => the spelling that will be used
        $unitNames = [];       // key => ['name' => ..., 'line' => ...]
        $looseNames = [];      // loose product name => sku

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];

            // ⚠⚠ Where this row's errors start. Every resolver below reports and
            // keeps going, so the only honest way to ask "did THIS row survive?"
            // is to count what it added. See the guard before $attributes.
            $errorsBefore = count($plan->errors);

            $sku = CatalogueText::clean($values['sku'] ?? '');
            $name = CatalogueText::clean($values['name'] ?? '');

            if ($sku === '') {
                $plan->addError($line, '(none)', 'sku is mandatory.');

                continue;
            }

            if (isset($seenSkus[$sku])) {
                $plan->addError($line, $sku, "sku is already used on line {$seenSkus[$sku]}.");

                continue;
            }

            $seenSkus[$sku] = $line;

            if (mb_strlen($sku) > 100) {
                $plan->addError($line, $sku, 'sku is longer than 100 characters.');
            }

            if ($name === '') {
                $plan->addError($line, $sku, 'name is mandatory.');
            } elseif (mb_strlen($name) > 255) {
                $plan->addError($line, $sku, 'name is longer than 255 characters.');
            }

            // ⚠ C-CAT-6: a near-duplicate name is a warning. Only a person can
            // say whether two similar names are one product.
            if ($name !== '') {
                $loose = CatalogueText::looseKey($name);

                // ⚠ An empty loose key means the name carried no letters or
                // digits at all. It must never become a bucket that everything
                // else falls into - that is exactly how the Bangla catalogue
                // warned every row as a duplicate of the first.
                if ($loose === '') {
                    // nothing comparable in this name; no duplicate check
                } elseif (isset($looseNames[$loose])) {
                    $plan->addWarning($line, $sku, "name is nearly identical to {$looseNames[$loose]}: \"{$name}\".");
                } else {
                    $looseNames[$loose] = $sku;
                }
            }

            $type = $this->resolveType($values['type'] ?? '', $line, $sku, $plan);
            $categoryKey = $this->resolveCategory($values['category'] ?? '', $line, $sku, $plan, $existingCategories, $categoryNames);
            $unitKey = $this->resolveUnit($values, $line, $sku, $plan, $existingUnits, $unitNames);
            $brandId = $this->resolveBrand($values['brand'] ?? null, $type, $line, $sku, $plan, $existingBrands);

            $barcode = CatalogueText::nullIfBlank($values['barcode'] ?? null);

            if ($barcode !== null) {
                if (mb_strlen($barcode) > 100) {
                    $plan->addError($line, $sku, 'barcode is longer than 100 characters.');
                }

                if (isset($seenBarcodes[$barcode])) {
                    $plan->addError($line, $sku, "barcode {$barcode} is already used on line {$seenBarcodes[$barcode]}.");
                } else {
                    $seenBarcodes[$barcode] = $line;
                }

                // ⚠⚠ C-CAT-3. Moving a barcode between products is either a
                // repack or a mistake, and nothing here can tell which.
                $owner = $barcodeOwners[$barcode] ?? null;

                if ($owner !== null && $owner !== $sku) {
                    $plan->addError($line, $sku, "barcode {$barcode} already belongs to product {$owner}.");
                }
            }

            $threshold = $this->resolveThreshold($values['low_stock_threshold'] ?? null, $line, $sku, $plan);
            $details = $this->resolveDetails($values, $line, $sku, $plan, $existingUnits);
            $isActive = $this->resolveIsActive($values['is_active'] ?? null, $line, $sku, $plan);

            if ($type !== null && $unitKey !== null && $name !== '') {
                $this->warnOnPackSizeVersusUnit($name, $unitKey, $line, $sku, $plan);
            }

            // ⚠⚠ A row that failed ANYTHING above must not become a create or an
            // update. Testing only $type and $unitKey was not enough: a row with
            // a blank name, an over-long barcode or a negative threshold still
            // resolved a type and a unit, so it was listed under "Would create"
            // and counted in "1 to create" - while the very same run refused to
            // write it. Nothing was ever written wrongly, but the review said
            // the file would do something it would not, and on this command the
            // review IS the product.
            if (count($plan->errors) !== $errorsBefore || $type === null || $unitKey === null) {
                continue;
            }

            $attributes = [
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'type' => $type,
                'brand_id' => $brandId,
                'description' => CatalogueText::nullIfBlank($values['description'] ?? null),
                'low_stock_threshold' => $threshold,
                'is_active' => $isActive,
                ...$details,
            ];

            $existing = $existingProducts->get($sku);

            if ($existing === null) {
                $plan->creates[] = compact('line', 'sku', 'name', 'attributes', 'categoryKey', 'unitKey');

                continue;
            }

            $changes = $this->changesFor($existing, $attributes, $categoryKey, $unitKey, $existingCategories, $existingUnits);

            if ($changes === []) {
                $plan->unchanged[] = compact('line', 'sku', 'name');

                continue;
            }

            $plan->updates[] = [
                'line' => $line, 'sku' => $sku, 'name' => $name, 'id' => $existing->id,
                'attributes' => $attributes, 'categoryKey' => $categoryKey, 'unitKey' => $unitKey,
                'changes' => $changes,
            ];
        }

        $plan->newCategories = array_diff_key($categoryNames, $existingCategories->all());
        $plan->newUnits = array_diff_key($unitNames, $existingUnits->all());

        // ⚠ C-CAT-1: reported, never touched.
        $plan->absentFromFile = $existingProducts->keys()
            ->reject(fn (string $sku) => isset($seenSkus[$sku]))
            ->values()->all();

        $this->warnOnNearDuplicateCategories($plan, $categoryNames, $existingCategories);

        return $plan;
    }

    private function resolveType(string $raw, int $line, string $sku, CatalogueImportPlan $plan): ?string
    {
        $value = strtolower(str_replace([' ', '-'], '_', CatalogueText::clean($raw)));

        if ($value === '') {
            $plan->addError($line, $sku, 'type is mandatory.');

            return null;
        }

        $type = ProductType::tryFrom($value);

        if ($type === null) {
            $allowed = implode(', ', array_column(ProductType::cases(), 'value'));
            $plan->addError($line, $sku, "type \"{$raw}\" is not one of: {$allowed}.");

            return null;
        }

        return $type->value;
    }

    /**
     * @param  Collection<string, Category>  $existing
     * @param  array<string, string>  $names
     */
    private function resolveCategory(string $raw, int $line, string $sku, CatalogueImportPlan $plan, $existing, array &$names): ?string
    {
        $clean = CatalogueText::clean($raw);

        if ($clean === '') {
            $plan->addError($line, $sku, 'category is mandatory.');

            return null;
        }

        if (mb_strlen($clean) > 255) {
            $plan->addError($line, $sku, 'category is longer than 255 characters.');

            return null;
        }

        $key = CatalogueText::categoryKey($clean);

        if ($key === '') {
            $plan->addError($line, $sku, "category \"{$raw}\" contains no usable characters.");

            return null;
        }

        // ⚠ C-CAT-3 sibling: the spelling already in the database wins, and the
        // file never renames what is already there.
        $settled = $existing->get($key)?->name ?? ($names[$key] ?? $clean);

        if ($settled !== $clean) {
            $plan->addWarning($line, $sku, "category \"{$clean}\" resolves to the existing \"{$settled}\".");
        }

        $names[$key] = $settled;

        return $key;
    }

    /**
     * @param  array<string, string>  $values
     * @param  Collection<string, UnitOfMeasure>  $existing
     * @param  array<string, array{name:string, abbreviation:string, line:int}>  $units
     */
    private function resolveUnit(array $values, int $line, string $sku, CatalogueImportPlan $plan, $existing, array &$units): ?string
    {
        $name = CatalogueText::clean($values['unit'] ?? '');
        $abbr = CatalogueText::clean($values['unit_abbr'] ?? '');

        if ($name === '') {
            $plan->addError($line, $sku, 'unit is mandatory.');
        }

        if ($abbr === '') {
            $plan->addError($line, $sku, 'unit_abbr is mandatory.');
        }

        if ($name === '' || $abbr === '') {
            return null;
        }

        if (mb_strlen($name) > 255 || mb_strlen($abbr) > 255) {
            $plan->addError($line, $sku, 'unit or unit_abbr is longer than 255 characters.');

            return null;
        }

        $key = CatalogueText::unitKey($abbr);

        // ⚠⚠ C-CAT-2. The abbreviation is the identity, so a disagreement about
        // the NAME behind it has to stop the run. Renaming a unit changes how
        // every stock figure in the system reads, and that is a person's call.
        $existingUnit = $existing->get($key);

        if ($existingUnit !== null && CatalogueText::looseKey($existingUnit->name) !== CatalogueText::looseKey($name)) {
            $plan->addError($line, $sku, "unit \"{$abbr}\" already exists as \"{$existingUnit->name}\", but the file calls it \"{$name}\".");

            return null;
        }

        if (isset($units[$key]) && CatalogueText::looseKey($units[$key]['name']) !== CatalogueText::looseKey($name)) {
            $plan->addError($line, $sku, "unit \"{$abbr}\" is called \"{$units[$key]['name']}\" on line {$units[$key]['line']} and \"{$name}\" here.");

            return null;
        }

        // ⚠ The other direction: one unit NAME must not arrive under two
        // abbreviations, or the catalogue ends up with Piece/pcs and Piece/pc
        // as two different units that mean the same thing.
        // ⚠ Guarded on a non-empty loose key. Two unit names that both reduce to
        // nothing are not "the same name" - treating them as such raised a false
        // blocking error on the first real Bangla file.
        $looseName = CatalogueText::looseKey($name);

        foreach ($units as $otherKey => $other) {
            if ($looseName !== '' && $otherKey !== $key && CatalogueText::looseKey($other['name']) === $looseName) {
                $plan->addError($line, $sku, "unit \"{$name}\" is abbreviated \"{$other['abbreviation']}\" on line {$other['line']} and \"{$abbr}\" here.");

                return null;
            }
        }

        if ($existingUnit === null && ! isset($units[$key])) {
            $units[$key] = ['name' => $name, 'abbreviation' => $abbr, 'line' => $line];
        }

        return $key;
    }

    /**
     * ⚠⚠ RESOLVED, NEVER CREATED (D-4). Unlike a category, a brand the file does
     * not recognise stops the run. Brands are few, deliberate and are the axis
     * profit is reported along, so auto-creating one on a typo would produce
     * "Bionc" and split a brand's margin in two without anybody noticing.
     *
     * ⚠ Blank is legitimate: raw materials have no brand (D-5). A FINISHED GOOD
     * without one is a warning rather than an error (D-3), because a hard
     * failure there would stop the whole first import on exactly the rows whose
     * brand has not been decided yet.
     *
     * @param  Collection<string, Brand>  $brands
     */
    private function resolveBrand(?string $raw, ?string $type, int $line, string $sku, CatalogueImportPlan $plan, $brands): ?int
    {
        $clean = CatalogueText::nullIfBlank($raw);

        if ($clean === null) {
            if ($type === ProductType::FinishedGood->value) {
                $plan->addWarning($line, $sku, 'finished good has no brand.');
            }

            return null;
        }

        // Accepted by code (LLD) or by name (LaLaDia), matched the same way the
        // rest of this importer matches: case and whitespace folded in PHP.
        $brand = $brands->get(CatalogueText::unitKey($clean))
            ?? $brands->get(CatalogueText::categoryKey($clean));

        if ($brand === null) {
            $known = $brands->unique('id')->map(fn (Brand $b) => $b->code)->sort()->values()->all();

            // ⚠ Kept SHORT on purpose. A long line wraps in the console, and a
            // wrapped message breaks every test asserting on its text - the same
            // trap that made this command render plain lines instead of tables.
            // The policy sentence belongs in the report footer, said once,
            // rather than repeated on forty rows.
            $plan->addError($line, $sku, "unknown brand \"{$clean}\". Known: "
                .($known === [] ? 'none' : implode(' ', $known)));

            return null;
        }

        if (! $brand->is_active) {
            $plan->addError($line, $sku, "brand \"{$brand->name}\" is retired and cannot take new products.");

            return null;
        }

        return $brand->id;
    }

    private function resolveThreshold(?string $raw, int $line, string $sku, CatalogueImportPlan $plan): ?string
    {
        $value = CatalogueText::nullIfBlank($raw);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            $plan->addError($line, $sku, "low_stock_threshold \"{$value}\" is not a number.");

            return null;
        }

        if ((float) $value < 0) {
            $plan->addError($line, $sku, 'low_stock_threshold cannot be negative.');

            return null;
        }

        return $value;
    }

    /**
     * The optional product-detail columns (ProductCatalogueFile::PRODUCT_DETAILS),
     * as product attributes. A column absent from the file is absent here.
     *
     * @return array<string, mixed>
     */
    private function resolveDetails(array $values, int $line, string $sku, CatalogueImportPlan $plan, $units): array
    {
        $details = [];

        if (array_key_exists('tracks_expiry', $values)) {
            $raw = strtolower((string) CatalogueText::nullIfBlank($values['tracks_expiry']));
            if ($raw !== '' && ! in_array($raw, [...self::TRUE_WORDS, ...self::FALSE_WORDS], true)) {
                $plan->addError($line, $sku, "tracks_expiry \"{$values['tracks_expiry']}\" is not a yes/no value.");
            }

            $details['tracks_expiry'] = in_array($raw, self::TRUE_WORDS, true);
        }

        if (array_key_exists('shelf_life_warning_days', $values)) {
            $raw = CatalogueText::nullIfBlank($values['shelf_life_warning_days']);

            if ($raw !== null && (! ctype_digit($raw) || (int) $raw > 3650)) {
                $plan->addError($line, $sku, "shelf_life_warning_days \"{$raw}\" must be whole days between 0 and 3650.");
            }

            $details['shelf_life_warning_days'] = $raw === null ? null : (int) $raw;
        }

        if (array_key_exists('content_quantity', $values) || array_key_exists('content_unit', $values)) {
            $quantity = CatalogueText::nullIfBlank($values['content_quantity'] ?? null);
            $unit = CatalogueText::nullIfBlank($values['content_unit'] ?? null);
            $unitId = $unit === null ? null : $units->get(CatalogueText::unitKey($unit))?->id;

            if ($quantity !== null && (! is_numeric($quantity) || (float) $quantity <= 0)) {
                $plan->addError($line, $sku, "content_quantity \"{$quantity}\" must be a number greater than zero.");
            } elseif (($quantity === null) !== ($unit === null)) {
                $plan->addError($line, $sku, 'content_quantity and content_unit go together: give both or neither.');
            } elseif ($unit !== null && $unitId === null) {
                $plan->addError($line, $sku, "content_unit \"{$unit}\" is not a unit in this business (use its abbreviation, e.g. g or mL).");
            }

            $details['content_quantity'] = $quantity;
            $details['content_unit_id'] = $unitId;
        }

        return $details;
    }

    private function resolveIsActive(?string $raw, int $line, string $sku, CatalogueImportPlan $plan): bool
    {
        $value = strtolower((string) CatalogueText::nullIfBlank($raw));

        // ⚠ C-CAT-4: a catalogue is a list of what the business sells.
        if ($value === '') {
            return true;
        }

        if (in_array($value, self::TRUE_WORDS, true)) {
            return true;
        }

        if (in_array($value, self::FALSE_WORDS, true)) {
            return false;
        }

        $plan->addError($line, $sku, "is_active \"{$raw}\" is not a yes/no value.");

        return true;
    }

    /**
     * ⚠⚠ C-CAT-5, and it is Joy's own trap from the stage 3 brief: a sealed
     * 500g jar sold as `kg` makes stock read `12` meaning twelve kilos when
     * twelve jars are held. A warning only - loose honey really is sold by the
     * kilo, so this must never be able to stop a legitimate catalogue.
     */
    private function warnOnPackSizeVersusUnit(string $name, string $unitKey, int $line, string $sku, CatalogueImportPlan $plan): void
    {
        // ⚠⚠ Bangla spellings included, in BOTH halves. The first version matched
        // only ASCII digits and ASCII unit words, so on Bor de Guna's own
        // catalogue - which is written in Bangla - this check never fired once.
        // It is the single warning Joy asked for by name, and it was dead on
        // exactly the data it was written for.
        $measured = [
            'kg', 'g', 'gm', 'gram', 'grams', 'kilo', 'kilogram', 'l', 'ltr', 'litre', 'liter', 'ml',
            'কেজি', 'গ্রাম', 'লিটার', 'মিলি', 'মিলিলিটার',
        ];

        if (! in_array($unitKey, $measured, true)) {
            return;
        }

        // Bangla digits are U+09E6..U+09EF, and no \b after the Bangla words -
        // word boundaries are an ASCII notion and would never match.
        $number = '[0-9\x{09E6}-\x{09EF}]+';
        $ascii = '(?:kg|g|gm|gram|grams|ml|l|ltr|litre|liter)\b';
        $bangla = '(?:কেজি|গ্রাম|কিলো|লিটার|মিলিলিটার|মিলি)';

        if (preg_match('/'.$number.'\s*(?:'.$ascii.'|'.$bangla.')/iu', $name) !== 1) {
            return;
        }

        $plan->addWarning($line, $sku,
            "name states a pack size but the unit is \"{$unitKey}\" - if this is a sealed pack, the unit should be the pack, not its weight."
        );
    }

    /**
     * @param  array<string, string>  $categoryNames
     * @param  Collection<string, Category>  $existing
     */
    private function warnOnNearDuplicateCategories(CatalogueImportPlan $plan, array $categoryNames, $existing): void
    {
        $all = array_values(array_unique(array_merge(
            array_values($categoryNames),
            $existing->pluck('name')->all()
        )));

        foreach ($all as $i => $one) {
            foreach (array_slice($all, $i + 1) as $other) {
                $a = CatalogueText::looseKey($one);
                $b = CatalogueText::looseKey($other);

                if ($a === '' || $b === '' || $a === $b) {
                    continue;
                }

                // ⚠⚠ Characters, not bytes, on both the guard and the distance.
                // strlen()/levenshtein() count bytes, and Bangla runs three
                // bytes per character with shared lead bytes - so unrelated
                // words scored as near-identical while the "too short to judge"
                // guard failed to exempt anything.
                if (min(CatalogueText::length($a), CatalogueText::length($b)) > 3
                    && CatalogueText::distance($a, $b) <= 2) {
                    $plan->addWarning(0, '(catalogue)', "categories \"{$one}\" and \"{$other}\" are nearly identical - should they be one?");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Collection<string, Category>  $categories
     * @param  Collection<string, UnitOfMeasure>  $units
     * @return array<string, array{0:mixed,1:mixed}>
     */
    private function changesFor(Product $existing, array $attributes, ?string $categoryKey, string $unitKey, $categories, $units): array
    {
        $changes = [];

        foreach (['sku', 'barcode', 'name', 'description'] as $field) {
            if ((string) $existing->{$field} !== (string) $attributes[$field]) {
                $changes[$field] = [$existing->{$field}, $attributes[$field]];
            }
        }

        // The optional product details, compared only when the file carries them.
        foreach (['tracks_expiry', 'shelf_life_warning_days', 'content_unit_id'] as $field) {
            if (array_key_exists($field, $attributes) && $existing->{$field} !== $attributes[$field]) {
                $changes[$field] = [$existing->{$field}, $attributes[$field]];
            }
        }

        if (array_key_exists('content_quantity', $attributes)) {
            $was = $existing->content_quantity;
            $now = $attributes['content_quantity'];

            if (($was === null) !== ($now === null) || ($was !== null && bccomp((string) $was, (string) $now, 4) !== 0)) {
                $changes['content_quantity'] = [$was, $now];
            }
        }

        if ($existing->type->value !== $attributes['type']) {
            $changes['type'] = [$existing->type->value, $attributes['type']];
        }

        if ($existing->is_active !== $attributes['is_active']) {
            $changes['is_active'] = [$existing->is_active, $attributes['is_active']];
        }

        // ⚠ Compared as ids, both nullable. Moving a product between brands is a
        // real change and must show in the review, because brand is what profit
        // is grouped by.
        if ($existing->brand_id !== $attributes['brand_id']) {
            $changes['brand_id'] = [$existing->brand_id, $attributes['brand_id']];
        }

        // ⚠⚠ NUMERIC comparison. low_stock_threshold is decimal(15,4), so the
        // database hands back "12.0000" where the file says "12". Compared as
        // strings every product would report as an update, for ever - the
        // decimal stringification trap that has already cost this project once.
        $was = $existing->low_stock_threshold;
        $now = $attributes['low_stock_threshold'];

        if (($was === null) !== ($now === null)) {
            $changes['low_stock_threshold'] = [$was, $now];
        } elseif ($was !== null && bccomp((string) $was, (string) $now, 4) !== 0) {
            $changes['low_stock_threshold'] = [$was, $now];
        }

        // A category or unit that does not exist yet cannot be compared by id;
        // if it is new, it is necessarily a change, and saying "(new category)"
        // is honest where saying "null" would read as "removed".
        if ($categoryKey === null) {
            if ($existing->category_id !== null) {
                $changes['category_id'] = [$existing->category_id, null];
            }
        } else {
            $categoryId = $categories->get($categoryKey)?->id;

            if ($categoryId === null) {
                $changes['category_id'] = [$existing->category_id, '(new category)'];
            } elseif ($existing->category_id !== $categoryId) {
                $changes['category_id'] = [$existing->category_id, $categoryId];
            }
        }

        $unitId = $units->get($unitKey)?->id;

        if ($unitId !== null && $existing->unit_of_measure_id !== $unitId) {
            $changes['unit_of_measure_id'] = [$existing->unit_of_measure_id, $unitId];
        } elseif ($unitId === null) {
            $changes['unit_of_measure_id'] = [$existing->unit_of_measure_id, '(new unit)'];
        }

        return $changes;
    }
}
