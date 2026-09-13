<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\PageSection;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreMedia;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Commerce\Sections\SectionReferences;
use App\Modules\Commerce\Sections\SectionType;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Foundation\Support\DocumentMoney;

/**
 * A stored page, made ready for a theme to draw.
 *
 * ⭐⭐ THE THEME NEVER TOUCHES THE DATABASE. It receives sections whose media and
 * product references are already resolved, so a template is markup and nothing
 * else. That is what makes a theme a skin: the day somebody writes a second one,
 * they write Blade, not queries, and cannot accidentally give their theme a
 * different idea of what a product costs.
 *
 * ⚠ Hidden sections are dropped here, once, rather than in twenty templates.
 * A rule every template has to remember is a rule one of them will forget.
 */
final class StorefrontPage
{
    /**
     * @return list<array{key:string, view:string, payload:array<string,mixed>, media:array<int,array<string,mixed>>, products:array<int,array<string,mixed>>}>
     */
    public static function sectionsFor(Store $store, Page $page, Theme $theme): array
    {
        $sections = [];

        foreach ($page->visibleSections()->orderBy('position')->get() as $section) {
            $type = self::typeFor($section);

            if ($type === null) {
                // ⛔ A type the ERP no longer defines is skipped, not guessed at.
                // The publish gate already refuses this, so reaching here means
                // a type was retired after publication - and drawing a payload
                // whose meaning nobody can state is worse than a gap.
                continue;
            }

            $sections[] = [
                'key' => $section->type_key,
                'view' => self::viewFor($theme, $section->type_key),
                'payload' => $section->payload ?? [],
                'media' => self::mediaFor($store, $section, $type),
                'products' => self::productsFor($store, $section, $type),
                // ⛔ Slice 2.5b makes the contract's promise true: a coupon named
                // in a strip is asked of the ERP at render, and a code checkout
                // would refuse on the code alone (off, expired, used up) is not
                // shown. Until now the template printed whatever was typed.
                'coupon' => $section->type_key === 'announcement.strip'
                    ? CouponPricing::advertisable($store, $section->payload['coupon_code'] ?? null)?->code
                    : null,
            ];
        }

        return $sections;
    }

    /**
     * ⚠⚠ A THEME THAT DECLARED A TYPE MUST HAVE A TEMPLATE FOR IT. Slice 1.2 let
     * a theme say what it can draw and slice 1.5 refused a switch on the
     * strength of that answer; this is where the promise is kept. A missing
     * template is not rendered as a blank - `ThemeTemplatesTest` fails the build
     * instead, because a blank is exactly the silent failure the capability
     * declaration exists to prevent.
     */
    public static function viewFor(Theme $theme, string $typeKey): string
    {
        return 'themes.'.$theme->key().'.sections.'.str_replace('.', '-', $typeKey);
    }

    /**
     * ⚠ Looked up by key AND version, because a page records the version it was
     * written against. Asking for the key alone would silently draw an old
     * payload with a new type's meaning, which is the one thing the version in
     * the section contract exists to prevent.
     */
    private static function typeFor(PageSection $section): ?SectionType
    {
        $registry = app(SectionTypeRegistry::class);

        return $registry->has($section->type_key, $section->type_version)
            ? $registry->get($section->type_key, $section->type_version)
            : null;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private static function mediaFor(Store $store, PageSection $section, SectionType $type): array
    {
        $ids = app(SectionReferences::class)->media($type, $section->payload ?? []);

        if ($ids === []) {
            return [];
        }

        return StoreMedia::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (StoreMedia $item) => [$item->id => $item->toReference()])
            ->all();
    }

    /**
     * Products, as this shop presents them, with the price a visitor is quoted.
     *
     * ⚠ Only PUBLISHED rows. A section pointing at a product the shop has taken
     * off sale renders without it rather than reviving it: taking something off
     * sale has to mean that everywhere, not only on the listing somebody
     * remembered to edit.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function productsFor(Store $store, PageSection $section, SectionType $type): array
    {
        $ids = app(SectionReferences::class)->products($type, $section->payload ?? []);

        if ($ids === []) {
            return [];
        }

        return StoreProduct::query()
            ->with('product', 'image')
            ->where('store_id', $store->id)
            ->whereIn('product_id', $ids)
            ->whereNotNull('published_at')
            ->get()
            ->mapWithKeys(fn (StoreProduct $row) => [$row->product_id => self::present($store, $row)])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public static function present(Store $store, StoreProduct $row): array
    {
        $price = $row->product === null ? null : CataloguePublishing::priceFor($store, $row->product);

        return [
            'id' => $row->product_id,
            'title' => $row->displayTitle(),
            'summary' => $row->summary_override,
            'slug' => $row->slug,
            'url' => '/products/'.$row->slug,
            'image' => $row->image?->toReference(),
            // ⚠ Formatted here, by the one formatter every document uses, so a
            // price never reaches a template as something it has to interpret.
            'price' => $price === null ? null : DocumentMoney::amount($price),
            // Slice 2.1. ⚠ Decided ONCE, here, by the same class the cart asks,
            // so the page and the add button cannot disagree about whether a
            // thing can be sold. A product with no price is not sellable
            // whatever the stock says: the cart refuses it, so the page must
            // not offer it.
            'availability' => $row->product === null
                ? ['sellable' => false, 'message' => null, 'made_to_order' => false]
                : ($price === null
                    ? ['sellable' => false, 'message' => null, 'made_to_order' => false]
                    : Availability::decide($store, $row->product)->toArray()),
        ];
    }
}
