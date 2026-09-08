<?php

namespace App\Modules\Commerce\Themes;

use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\PageSection;
use App\Modules\Commerce\Models\Store;

/**
 * Can this theme render what this store already says?
 *
 * ⭐⭐ THE WHOLE POINT OF THE CAPABILITY DECLARATION, CASHED IN. Slice 1.2 made
 * a theme state which section types it renders and up to which contract
 * version, which looked like bookkeeping at the time. This is what it was for:
 * the ERP can ask, BEFORE anything is saved, "if I put this store on that
 * theme, does every published page still have every one of its parts", and get
 * a truthful answer with the pages named.
 *
 * ⛔ WITHOUT THIS, A THEME SWITCH IS A GAMBLE WITH SOMEBODY ELSE'S SHOP. The
 * failure is silent by nature: an unsupported section does not error, it simply
 * is not drawn, so the page returns 200 with a hole where the hero was and
 * nobody finds out until a customer does, or until the month's conversion
 * figures come in.
 *
 * ⚠ THE REFUSAL FOLLOWS THE PUBLIC, NOT THE DATABASE. A PUBLISHED page losing a
 * section is a live defect and blocks the switch. A DRAFT losing one is
 * somebody's unfinished work and is reported as a warning, because the publish
 * gate in slice 1.4 will refuse it later anyway and blocking here would make an
 * abandoned draft hold a whole store hostage.
 *
 * ⚠ A FALLBACK IS A DOWNGRADE, NOT A FAILURE, and it is named rather than
 * waved through. A theme that answers "I cannot draw a split panel but I will
 * render it as a passage" keeps the words on the page, which is the difference
 * between a redesign and a data loss. The person switching should still be told
 * which pages will look different.
 */
class ThemeCompatibility
{
    /**
     * @return array<string, mixed>
     */
    public function report(Store $store, Theme $theme): array
    {
        $blocking = [];
        $downgraded = [];
        $draftWarnings = [];
        $alreadyBroken = [];

        $sectionCount = 0;

        $pages = Page::where('store_id', $store->id)->with('sections')->get();

        foreach ($pages as $page) {
            foreach ($page->sections as $section) {
                $sectionCount++;

                $type = $section->type();

                // ⚠ Reported separately and never counted against the new theme.
                // A section whose type the ERP no longer defines is broken
                // whichever theme is chosen, so blaming the candidate would send
                // somebody hunting for a fault in the wrong place.
                if ($type === null) {
                    $alreadyBroken[] = $this->row($page, $section, $section->identity(), null,
                        'This ERP no longer defines this section type, so no theme can draw it.');

                    continue;
                }

                // ⚠ A hidden section is not on the page. Blocking a switch over
                // something nobody can see would be theatre.
                if ($section->is_hidden) {
                    continue;
                }

                if ($theme->supports($type->key, $type->version)) {
                    continue;
                }

                $fallback = $theme->fallbackFor($type->key, $type->version);

                if ($fallback !== null) {
                    $downgraded[] = $this->row($page, $section, $type->identity(), $type->label,
                        "Will be rendered as [{$fallback}] instead, so it will keep its words and change its look.");

                    continue;
                }

                $problem = $this->row($page, $section, $type->identity(), $type->label,
                    'This theme cannot draw it and declares no fallback, so the section would be blank.');

                // ⛔ Published pages block. Drafts warn. See the class docblock.
                if ($page->isPublished()) {
                    $blocking[] = $problem;
                } else {
                    $draftWarnings[] = $problem;
                }
            }
        }

        return [
            'theme_key' => $theme->key(),
            'theme_name' => $theme->name(),
            'can_switch' => $blocking === [],
            'blocking' => $blocking,
            'downgraded' => $downgraded,
            'draft_warnings' => $draftWarnings,
            'already_broken' => $alreadyBroken,
            'counts' => [
                'pages' => $pages->count(),
                'sections' => $sectionCount,
                'blocking' => count($blocking),
                'downgraded' => count($downgraded),
                'draft_warnings' => count($draftWarnings),
            ],
        ];
    }

    /**
     * The refusal, as sentences somebody can act on.
     *
     * ⚠ Every blocked section is named with its page and its position, not
     * counted. "3 sections would go dark" tells nobody which three, and the
     * person reading it is about to decide whether to take pages down.
     *
     * @return array<int, string>
     */
    public function refusals(Store $store, Theme $theme): array
    {
        $report = $this->report($store, $theme);

        return array_map(
            fn (array $row) => "{$row['page_title']} ({$row['page_path']}), section {$row['section_position']}: {$row['reason']}",
            $report['blocking'],
        );
    }

    private function row(Page $page, PageSection $section, string $identity, ?string $label, string $reason): array
    {
        return [
            'page_id' => $page->id,
            'page_title' => $page->title,
            'page_path' => $page->path(),
            'page_published' => $page->isPublished(),
            'section_position' => $section->position,
            'section_label' => $label ?? $identity,
            'identity' => $identity,
            'reason' => $reason,
        ];
    }
}
