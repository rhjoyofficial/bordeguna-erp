<?php

/**
 * ⚠⚠ "ADD ALL REQUIRED FILED WITH THE * SYMBOL WITH RED COLOR, FULL ENTIRE ERP."
 *
 * Joy, 10 September 2026. The star is drawn in ONE place - the shared `Label`,
 * which `Field` wraps - so "entire ERP" was a change of one component plus a
 * marking pass over every screen. This test is what stops that from quietly
 * rotting: the mechanism has to keep working, and the pass has to stay applied.
 *
 * ⚠ It does NOT re-derive requiredness from the controllers. That inference was
 * worth doing once, from the real validation rules, with the ambiguous cases
 * left deliberately unmarked; baking the same guesswork into an assertion would
 * only mean the test and the sweep agree with each other and both disagree with
 * the application ([[reference_green_suite_lies]]).
 */
$source = fn (string $path) => file_get_contents(base_path($path));

it('draws the star in one place, in the theme red, and hides it from screen readers', function () use ($source) {
    $label = $source('resources/js/components/ui/label.tsx');

    expect($label)->toContain('required')
        // The theme's red, not a hand-picked one, so it still reads in dark mode.
        ->and($label)->toContain('text-destructive')
        // ⚠ A screen reader announcing "star" after every label is noise. The
        // input carries the real `required` attribute; this is for eyes only.
        ->and($label)->toContain('aria-hidden');

    // Field must DELEGATE rather than draw a second star of its own, or the two
    // would drift and half the ERP would end up marked differently.
    $field = $source('resources/js/components/form/field.tsx');

    expect($field)->toContain('required={required}')
        ->and($field)->not->toContain('text-destructive');
});

/**
 * ⛔ THE MARKING PASS ITSELF, GUARDED. 186 fields across 69 screens were marked
 * from the controllers' own validation rules. Without this, a careless revert or
 * a bad merge could drop the lot and the only symptom would be that the stars
 * quietly stopped appearing - which nobody notices until a form is refused.
 */
it('keeps the marking pass applied across the application', function () {
    $marked = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js/pages'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'tsx') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        // A `required` sitting as its own attribute on a Field or a Label.
        if (preg_match('/<(Field|Label)\b[^>]*\srequired[\s>]/s', $contents) === 1) {
            $marked[] = $file->getFilename();
        }
    }

    // 69 screens carried a mark when the pass was run. A floor well under that
    // catches a wholesale loss without failing every time a screen is renamed
    // or a form legitimately loses its last mandatory box.
    expect(count($marked))->toBeGreaterThanOrEqual(
        55,
        'The required-field marking pass looks to have been reverted: only '.count($marked).' screens still mark a required field.',
    );
});
