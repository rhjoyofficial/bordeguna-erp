<?php

namespace App\Modules\Commerce\Themes;

use App\Modules\Commerce\Sections\Field;
use App\Modules\Commerce\Sections\SectionCatalogue;

/**
 * The first theme, and deliberately not a redesign.
 *
 * ⭐ Joy's decision: the first theme is a FAITHFUL REBUILD of today's design. A
 * redesign at cutover would confound any conversion drop, because two things
 * would have changed at once and neither could be blamed. A refresh becomes a
 * second theme, later, on its own.
 *
 * It therefore declares support for **every** section type in the catalogue.
 * That is not laziness: the store it has to replace uses the whole vocabulary,
 * and a theme that quietly dropped one would surface as a blank space on a live
 * shop rather than as a refusal at switch time.
 *
 * ⚠ This class is the CONTRACT, not the markup. Its templates arrive with the
 * storefront in slice 1.8; what matters here is that the ERP can already ask it
 * what it renders, and get a truthful answer.
 */
class BionicClassicTheme extends Theme
{
    public function key(): string
    {
        return 'bionic-classic';
    }

    public function name(): string
    {
        return 'Bionic Classic';
    }

    public function description(): string
    {
        return 'A faithful rebuild of the current Bionic Garden storefront. Deep green, warm ivory, gold.';
    }

    /**
     * Every type in the catalogue, at the version the catalogue currently
     * defines.
     *
     * ⚠ Built FROM the catalogue rather than typed out by hand, so a new section
     * type cannot be added to the ERP and silently forgotten here. ⛔ The moment
     * that stops being the right answer for this theme, list the types
     * explicitly instead: a theme that claims everything by construction can
     * never truthfully report that it lacks something.
     */
    public function sections(): array
    {
        $support = [];

        foreach (SectionCatalogue::all() as $type) {
            $support[] = new SectionSupport($type->key, $type->version);
        }

        return $support;
    }

    public function settings(): array
    {
        return [
            Field::media('logo', help: 'Shown in the header and used for the browser tab icon.'),
            Field::text('brand_primary', 9, help: 'Hex colour, for example #18693b.'),
            Field::text('brand_accent', 9, help: 'Hex colour, for example #c5a34e.'),
            Field::choice('density', ['comfortable', 'compact'], help: 'How much air the layout leaves.'),
            // ⚠ A real defect this exists to prevent: Inter carries no Bengali
            // glyphs, so a shop with Bangla copy and no Bengali face falls back
            // to whatever the visitor's operating system happens to have, and
            // looks different on every device.
            Field::choice('bengali_font', ['hind-siliguri', 'tiro-bangla', 'noto-sans-bengali'],
                help: 'The Bengali face. The Latin face carries no Bengali glyphs, so this is not optional on a Bangla storefront.'),
        ];
    }

    public function capabilities(): array
    {
        return [
            'sticky_cart_bar',
            'announcement_bar',
            'product_quick_view',
        ];
    }
}
