<?php

namespace App\Modules\Commerce\Themes;

use App\Modules\Commerce\Sections\SectionTypeRegistry;
use RuntimeException;

/**
 * Every theme the ERP knows, by key.
 *
 * ⚠ Populated from code at boot. A store stores only the KEY, so a theme that
 * stops being registered leaves stores pointing at nothing. That is why
 * `get()` throws with the key in the message rather than returning null: a
 * storefront failing to find its theme is a deployment mistake, and it should
 * say so loudly rather than render an empty page.
 */
class ThemeRegistry
{
    /** @var array<string, Theme> */
    private array $themes = [];

    public function register(Theme $theme): void
    {
        $key = $theme->key();

        if (isset($this->themes[$key])) {
            throw new RuntimeException("Theme [{$key}] is registered twice. A store stores only the key, so two themes answering to it is ambiguous.");
        }

        $this->themes[$key] = $theme;
    }

    public function has(string $key): bool
    {
        return isset($this->themes[$key]);
    }

    public function get(string $key): Theme
    {
        return $this->themes[$key]
            ?? throw new RuntimeException("Unknown theme [{$key}]. A store points at it and nothing is registered under that key.");
    }

    /** @return array<int, Theme> */
    public function all(): array
    {
        return array_values($this->themes);
    }

    /**
     * Every section type a theme claims to render but the ERP does not define.
     *
     * ⚠⚠ THIS IS THE CHECK THAT CATCHES A TYPO AT BOOT rather than at a theme
     * switch. A theme declaring `story.splt` would otherwise look perfectly
     * healthy until somebody put a `story.split` on a page and the switch
     * refused for a reason nobody could explain.
     *
     * @return array<int, string>
     */
    public function undefinedClaims(Theme $theme, SectionTypeRegistry $sections): array
    {
        $unknown = [];

        foreach ($theme->sections() as $support) {
            if ($sections->latestVersion($support->typeKey) === null) {
                $unknown[] = $support->typeKey;
            }

            if ($support->fallback !== null && $sections->latestVersion($support->fallback) === null) {
                $unknown[] = $support->fallback;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Section types the ERP defines that this theme cannot render at all.
     *
     * ⚠ Not an error. A deliberately minimal theme is allowed to render fewer
     * types; it simply means a page using one of them cannot be shown by it, and
     * the theme-switch report is where that becomes a decision.
     *
     * @return array<int, string>
     */
    public function unsupportedTypes(Theme $theme, SectionTypeRegistry $sections): array
    {
        $missing = [];

        foreach ($sections->forAuthoring() as $group) {
            foreach ($group as $type) {
                if (! $theme->canRender($type->key, $type->version)) {
                    $missing[] = $type->key;
                }
            }
        }

        sort($missing);

        return $missing;
    }
}
