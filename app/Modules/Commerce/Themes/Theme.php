<?php

namespace App\Modules\Commerce\Themes;

use App\Modules\Commerce\Sections\Field;

/**
 * A theme: how a store looks, and nothing about what it says.
 *
 * ⭐⭐ A THEME DECLARES ITS CAPABILITIES THE WAY AN HQ ADAPTER DOES. That is the
 * rule that makes theme switching a decision with a report rather than a
 * gamble. The ERP can ask, before anything is saved, "can you render every
 * section on every published page of this store?" and get a truthful answer.
 *
 * ⛔ THEMES ARE CODE REGISTERED BY KEY, NEVER UPLOADABLE PACKAGES. An upload
 * path means arbitrary code or templating executing inside the system that holds
 * the company's books. A new design ships the way any other code ships.
 *
 * ⛔ A theme owns layout, colour, type, spacing and motion. It owns no content
 * and no business rules. It cannot loosen a section's validation, cannot require
 * a field the contract does not have, and cannot make a section mean something
 * else, because two themes rendering the same page must show the same facts.
 */
abstract class Theme
{
    /** Stable identity. Stored on the store; renaming it orphans every store using it. */
    abstract public function key(): string;

    /** Shown in the ERP. Safe to change. */
    abstract public function name(): string;

    /**
     * The theme's own version, for the record. Not the contract version.
     */
    public function version(): string
    {
        return '1.0';
    }

    public function description(): string
    {
        return '';
    }

    /**
     * Which section types this theme can render.
     *
     * @return array<int, SectionSupport>
     */
    abstract public function sections(): array;

    /**
     * The theme's own knobs: palette, fonts, density, logo slots. Typed with the
     * same field primitives sections use, so one validator serves both and a
     * theme cannot invent a setting shape nothing can check.
     *
     * @return array<int, Field>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * Coarse features a storefront may ask about: a sticky cart bar, a mega
     * menu, an announcement bar, product quick view.
     *
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        return [];
    }

    /* ------------------------------------------------------------------ */

    public function supports(string $typeKey, int $version): bool
    {
        foreach ($this->sections() as $support) {
            if ($support->rendersDirectly($typeKey, $version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this theme would render instead, if anything.
     *
     * ⚠ Returns null both when the type is unknown to the theme AND when it is
     * known but declares no fallback. The caller cares about one thing: is there
     * something to render. ⛔ Null means a page using this section would go
     * blank, which is what makes a theme switch refuse.
     */
    public function fallbackFor(string $typeKey, int $version): ?string
    {
        foreach ($this->sections() as $support) {
            if ($support->typeKey !== $typeKey) {
                continue;
            }

            if ($version <= $support->maxVersion) {
                return $typeKey;
            }

            return $support->fallback;
        }

        return null;
    }

    /**
     * Can this theme put SOMETHING on the page for this section, directly or by
     * a declared fallback?
     */
    public function canRender(string $typeKey, int $version): bool
    {
        return $this->fallbackFor($typeKey, $version) !== null;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'version' => $this->version(),
            'description' => $this->description(),
            'capabilities' => $this->capabilities(),
            'sections' => array_map(fn (SectionSupport $s) => [
                'type' => $s->typeKey,
                'max_version' => $s->maxVersion,
                'fallback' => $s->fallback,
                'note' => $s->note,
            ], $this->sections()),
            'settings' => array_map(fn (Field $f) => $f->toArray(), $this->settings()),
        ];
    }
}
