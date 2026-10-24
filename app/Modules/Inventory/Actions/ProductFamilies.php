<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): the four ways a product
 * becomes or stops being a size of a family. Joy: "Product can be easily
 * changeable to single variants to multiple variants."
 *
 * - make(): a single product becomes the first size of a new family.
 * - addSize(): a NEW product, pre-filled from the family, joins it.
 * - join(): an existing single product joins a family.
 * - leave(): a size becomes a single product again.
 *
 * ⚠⚠ Each one writes the product's two family columns (and, for make and
 * addSize, creates a row) and nothing else. Stock, batches, prices, recipes
 * and journals stay exactly where they are: every size is its own product
 * before, during and after.
 *
 * ⚠ Double-click guard: every step re-reads the product (and the family)
 * under a lock, so a second press finds the work done and is refused with a
 * plain sentence instead of doing it twice.
 */
class ProductFamilies
{
    /**
     * The columns a new size copies from the family's first size: what it is
     * and how it is tracked and sold. Never its weight, MRP, barcode, net
     * content or threshold: those belong to the size.
     */
    public const COPIED_FROM_TEMPLATE = [
        'unit_of_measure_id', 'type', 'tax_class_id', 'pricing_mode',
        'market_markup_percent', 'market_min_margin_percent', 'market_rounding_step',
        'tracks_expiry', 'shelf_life_warning_days', 'shelf_life_days',
        'allows_fractional_stock', 'can_be_sold', 'material_kind',
        'default_supplier_id', 'availability_override', 'lead_time_days',
    ];

    /**
     * @param  array{name: string, sku: string, attribute_name?: ?string, description?: ?string, value: string}  $data
     */
    public function make(Product $product, array $data): ProductFamily
    {
        return DB::transaction(function () use ($product, $data) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($current->product_family_id !== null) {
                throw ValidationException::withMessages(['value' => "{$current->name} is already a size of a family."]);
            }

            $this->refuseLiveSku($data['sku']);

            $family = ProductFamily::create([
                'business_id' => $current->business_id,
                'name' => trim($data['name']),
                'sku' => strtoupper(trim($data['sku'])),
                'category_id' => $current->category_id,
                'brand_id' => $current->brand_id,
                'attribute_name' => $this->attributeName($data['attribute_name'] ?? null),
                'description' => $data['description'] ?? null,
            ]);

            $this->attach($current, $family, $data['value']);

            return $family;
        });
    }

    /**
     * A new product that is one more size of the family, pre-filled from it:
     * category and brand from the family; unit, kind, tracking and the selling
     * switches from its first size. An empty family has no first size, so the
     * popup then asks for the unit and the kind itself.
     *
     * @param  array{value: string, sku: string, name: string, mrp?: mixed, unit_of_measure_id?: ?int, type?: ?string}  $data
     */
    public function addSize(ProductFamily $family, array $data): Product
    {
        return DB::transaction(function () use ($family, $data) {
            $current = ProductFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $template = $current->templateSize();

            $copied = $template === null
                ? $this->withoutTemplate($data)
                : collect(self::COPIED_FROM_TEMPLATE)->mapWithKeys(fn (string $column) => [$column => $template->getAttributes()[$column] ?? null])->all();

            $sku = strtoupper(trim($data['sku']));

            if (Product::query()->where('sku', $sku)->exists()) {
                throw ValidationException::withMessages(['sku' => "The SKU {$sku} is already used by another product."]);
            }

            $this->refuseTakenValue($current, $data['value']);

            $product = Product::create(array_merge($copied, [
                'business_id' => $current->business_id,
                'category_id' => $current->category_id,
                'brand_id' => $current->brand_id,
                'sku' => $sku,
                'name' => trim($data['name']),
                'mrp' => ($data['mrp'] ?? null) === '' ? null : ($data['mrp'] ?? null),
                'is_active' => true,
            ]));

            $this->attach($product, $current, $data['value']);

            return $product;
        });
    }

    public function join(Product $product, ProductFamily $family, string $value): Product
    {
        return DB::transaction(function () use ($product, $family, $value) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $target = ProductFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();

            if ($current->product_family_id !== null) {
                throw ValidationException::withMessages(['product_family_id' => "{$current->name} is already a size of a family. Use Leave family first."]);
            }

            $template = $target->templateSize();

            // ⚠ One kind per family, so the family stays on one list: a
            // product family on Products, a raw material family on Raw materials.
            if ($template !== null && $template->type !== $current->type) {
                throw ValidationException::withMessages(['product_family_id' => "{$target->name} holds {$this->kindWord($template->type)}s, and {$current->name} is {$this->kindWord($current->type, true)}."]);
            }

            $this->attach($current, $target, $value);

            return $current;
        });
    }

    public function leave(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($current->product_family_id === null) {
                throw ValidationException::withMessages(['product' => "{$current->name} is not in a family."]);
            }

            $current->forceFill(['product_family_id' => null, 'family_attribute_value' => null])->save();

            return $current;
        });
    }

    /** ⚠ The one place the two family columns are written for a joining size. */
    private function attach(Product $product, ProductFamily $family, string $value): void
    {
        $value = $this->cleanValue($value);
        $this->refuseTakenValue($family, $value);

        $product->forceFill(['product_family_id' => $family->id, 'family_attribute_value' => $value])->save();
    }

    private function cleanValue(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            throw ValidationException::withMessages(['value' => 'Write the size, for example 25 kg.']);
        }

        return $value;
    }

    /** "25 kg" and "25 KG" are one size: refused, compared without case or spaces. */
    private function refuseTakenValue(ProductFamily $family, string $value): void
    {
        $key = mb_strtolower((string) preg_replace('/\s+/u', '', $value));

        $taken = Product::query()->where('product_family_id', $family->id)->get(['name', 'family_attribute_value'])
            ->first(fn (Product $size) => mb_strtolower((string) preg_replace('/\s+/u', '', (string) $size->family_attribute_value)) === $key);

        if ($taken !== null) {
            throw ValidationException::withMessages(['value' => "{$family->name} already has the size {$taken->family_attribute_value} ({$taken->name})."]);
        }
    }

    private function refuseLiveSku(string $sku): void
    {
        $sku = strtoupper(trim($sku));

        if (ProductFamily::query()->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages(['sku' => "Another family already uses the SKU {$sku}."]);
        }
    }

    private function attributeName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? ProductFamily::DEFAULT_ATTRIBUTE : $name;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutTemplate(array $data): array
    {
        if (empty($data['unit_of_measure_id']) || empty($data['type'])) {
            throw ValidationException::withMessages(['unit_of_measure_id' => 'This family has no sizes yet, so choose the stock unit and the kind.']);
        }

        return ['unit_of_measure_id' => (int) $data['unit_of_measure_id'], 'type' => $data['type']];
    }

    private function kindWord(?ProductType $type, bool $withArticle = false): string
    {
        $word = match ($type) {
            ProductType::RawMaterial => 'raw material',
            ProductType::Service => 'service',
            default => 'product',
        };

        return $withArticle ? ($type === ProductType::RawMaterial ? 'a raw material' : "a {$word}") : $word;
    }
}
