<?php

/**
 * ⚠⚠ "WHY DO YOU ALWAYS MAKE LONG WIDTH INPUT? USE THEM AS THEY NEED."
 *
 * Joy, 30 August 2026, about date boxes. Joy AGAIN, 3 September 2026, about
 * number boxes - the same sentence, a different control, because the first fix
 * was a convention nobody could enforce. A default that lives only in a comment
 * is a default that comes back as a complaint.
 *
 * So the rule is a test now:
 *   - the base Input keeps its self-sizing date family and its short number box
 *   - no screen quietly stretches one back to the full width of its column
 *
 * ⚠ Text inputs are deliberately NOT covered. A name, an address or a purpose
 * genuinely wants the room it is given, and `Field` sizes those by wrapper.
 */
$source = fn (string $path) => file_get_contents(base_path($path));

it('keeps the short defaults in the one place every control inherits them', function () use ($source) {
    $input = $source('resources/js/components/ui/input.tsx');

    // A date box is as wide as a date.
    expect($input)->toContain("const SELF_SIZING = ['date', 'time', 'datetime-local', 'month', 'week']")
        ->and($input)->toContain("'w-auto min-w-0'");

    // A number box is as wide as a number, and clamps inside a narrower Field.
    expect($input)->toContain("'w-36 max-w-full'")
        ->and($input)->toContain("type === 'number'");
});

it('lets no screen stretch a date or a number across its column', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'tsx') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        // ⚠⚠ ONLY screens that do NOT use the Field convention. A `Field` sizes
        // the WRAPPER and every control inside it is meant to fill that wrapper -
        // `size="sm"` plus a `w-full` input is how the helper has always worked,
        // and forbidding it outright would forbid the correct pattern. The real
        // defect is a short box stretched by a COLUMN IT DOES NOT CONTROL, which
        // is precisely what a screen without Field has.
        if (str_contains($contents, 'components/form/field')) {
            continue;
        }

        preg_match_all('/<Input [^>]*>/s', $contents, $matches);

        foreach ($matches[0] as $tag) {
            $isShort = preg_match('/type="(number|date|time|datetime-local|month|week)"/', $tag) === 1;

            if ($isShort && str_contains($tag, 'w-full')) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect(array_unique($offenders))->toBe(
        [],
        'A date or number box was given w-full on a screen with no Field to size it, '
        .'so it takes the whole column. It is as wide as its content; only a text area takes the row.',
    );
});
