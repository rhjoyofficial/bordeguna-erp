<?php

use App\Modules\Commerce\Sections\Field;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use App\Modules\Commerce\Themes\BionicClassicTheme;
use App\Modules\Commerce\Themes\SectionSupport;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;

/**
 * Phase 1, slice 1.2: the theme registry and the capability declaration.
 *
 * ⭐⭐ The point of this slice is that the ERP can ask a theme, BEFORE anything
 * is saved, whether it can render a given section. That question is what turns
 * a theme switch from a gamble into a decision with a report, and it is why a
 * theme declares its capabilities the way an HQ adapter does.
 */
function themes(): ThemeRegistry
{
    return app(ThemeRegistry::class);
}

/** A deliberately narrow theme, for the cases the real one cannot show. */
function minimalTheme(array $sections = [], array $capabilities = []): Theme
{
    return new class($sections, $capabilities) extends Theme
    {
        public function __construct(private array $sections, private array $capabilities) {}

        public function key(): string
        {
            return 'minimal-test';
        }

        public function name(): string
        {
            return 'Minimal';
        }

        public function sections(): array
        {
            return $this->sections;
        }

        public function capabilities(): array
        {
            return $this->capabilities;
        }
    };
}

it('registers the first theme at boot', function () {
    expect(themes()->has('bionic-classic'))->toBeTrue()
        ->and(themes()->get('bionic-classic'))->toBeInstanceOf(BionicClassicTheme::class);
});

it('refuses to register two themes under one key', function () {
    $registry = new ThemeRegistry;
    $registry->register(new BionicClassicTheme);

    // A store stores only the key, so two themes answering to it is ambiguous.
    expect(fn () => $registry->register(new BionicClassicTheme))->toThrow(RuntimeException::class);
});

it('says which theme is missing rather than rendering an empty page', function () {
    // A storefront that cannot find its theme is a deployment mistake. It should
    // fail loudly, not quietly serve nothing.
    expect(fn () => themes()->get('no-such-theme'))
        ->toThrow(RuntimeException::class, 'Unknown theme [no-such-theme]');
});

it('renders every section type the ERP defines, because the first theme is a faithful rebuild', function () {
    $missing = themes()->unsupportedTypes(themes()->get('bionic-classic'), app(SectionTypeRegistry::class));

    // ⭐ Joy's decision: the first theme is a faithful rebuild of today's design,
    // and the store it replaces uses the whole vocabulary. A type it quietly
    // dropped would surface as a blank space on a live shop.
    expect($missing)->toBe([]);
});

it('⚠ catches a theme claiming a section type the ERP has never defined', function () {
    // ⚠⚠ This is the check that catches a typo AT BOOT rather than at a theme
    // switch. `story.splt` would otherwise look healthy until somebody put a
    // real `story.split` on a page.
    $typo = minimalTheme([new SectionSupport('story.splt', 1)]);

    expect(themes()->undefinedClaims($typo, app(SectionTypeRegistry::class)))
        ->toBe(['story.splt']);
});

it('⚠ catches a fallback that points at nothing', function () {
    $bad = minimalTheme([new SectionSupport('story.split', 1, fallback: 'story.imaginary')]);

    // A fallback naming a type that does not exist is worse than no fallback:
    // the theme claims it can cope and then cannot.
    expect(themes()->undefinedClaims($bad, app(SectionTypeRegistry::class)))
        ->toContain('story.imaginary');
});

it('answers honestly about a version it is too old to understand', function () {
    $old = minimalTheme([new SectionSupport('story.split', 1)]);

    expect($old->supports('story.split', 1))->toBeTrue()
        // A theme written against version 1 keeps working when version 1 gains
        // an optional field, but a new MAJOR needs a new declaration.
        ->and($old->supports('story.split', 2))->toBeFalse()
        ->and($old->canRender('story.split', 2))->toBeFalse();
});

it('⛔ distinguishes "cannot render" from "will render something else"', function () {
    $withFallback = minimalTheme([
        new SectionSupport('story.split', 1, fallback: 'story.prose', note: 'Renders as a plain passage.'),
    ]);

    // ⛔ This distinction is the whole basis of the theme-switch rule in slice
    // 1.5. No fallback means a published page would go blank, and the ERP takes
    // the theme at its word and refuses the switch.
    expect($withFallback->supports('story.split', 2))->toBeFalse()
        ->and($withFallback->canRender('story.split', 2))->toBeTrue()
        ->and($withFallback->fallbackFor('story.split', 2))->toBe('story.prose');

    $without = minimalTheme([new SectionSupport('story.split', 1)]);

    expect($without->canRender('story.split', 2))->toBeFalse()
        ->and($without->fallbackFor('story.split', 2))->toBeNull();
});

it('knows nothing about a type it never declared', function () {
    $narrow = minimalTheme([new SectionSupport('story.prose', 1)]);

    expect($narrow->canRender('commerce.checkout', 1))->toBeFalse()
        ->and($narrow->fallbackFor('commerce.checkout', 1))->toBeNull();
});

it('types its own settings with the same primitives sections use', function () {
    $settings = themes()->get('bionic-classic')->settings();

    // One validator serves both, so a theme cannot invent a setting shape that
    // nothing is able to check.
    expect($settings)->not->toBeEmpty()
        ->and($settings[0])->toBeInstanceOf(Field::class);

    $names = array_map(fn (Field $f) => $f->name, $settings);

    // ⚠ A real defect this exists to prevent: the Latin face carries no Bengali
    // glyphs, so a Bangla storefront without a declared Bengali face falls back
    // to whatever the visitor's OS happens to have.
    expect($names)->toContain('bengali_font');
});

it('reports its coarse capabilities', function () {
    $theme = themes()->get('bionic-classic');

    expect($theme->hasCapability('sticky_cart_bar'))->toBeTrue()
        ->and($theme->hasCapability('drag_and_drop_builder'))->toBeFalse();
});

it('describes itself as data a screen can render', function () {
    $described = themes()->get('bionic-classic')->toArray();

    expect($described)->toHaveKeys(['key', 'name', 'version', 'capabilities', 'sections', 'settings'])
        ->and($described['sections'])->toHaveCount(20);
});
