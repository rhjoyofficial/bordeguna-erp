<?php

use App\Modules\Sales\Couriers\Enums\CourierStatus;

/**
 * The Bangla dictionary is the one place in this system where a defect is
 * invisible to everybody who reviews it in English.
 *
 * TypeScript already refuses a key that carries only one language. What it
 * cannot see is a key that carries English twice: `bn: 'Total'` compiles, ships,
 * and shows a Bangla-speaking counter clerk an English word. Nor can it see a
 * Bangla string typed in decomposed form, which renders correctly in the editor
 * and then sorts and compares as a different string everywhere else
 * (the standing NFC rule).
 *
 * ⚠ Digits, dates and money never come through here (Joy's 31 August decision),
 * so a Bangla string is allowed to contain English digits and punctuation - it
 * just has to contain some Bangla.
 */
/**
 * ⚠ Every dictionary file: i18n.ts and the per-area parts in lib/i18n/ that it
 * spreads in (11 Oct 2026). A part this file did not read would be a part
 * nobody checks.
 */
function dictionarySource(): string
{
    $files = [resource_path('js/lib/i18n.ts'), ...glob(resource_path('js/lib/i18n/*.ts'))];

    return implode('
', array_map('file_get_contents', $files));
}

function dictionaryEntries(): array
{
    $source = dictionarySource();

    // ⚠ Either quote: prettier (singleQuote) writes a string that holds an
    // apostrophe in double quotes, and an entry this cannot read would be
    // skipped in silence by every check below.
    $string = "(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")";

    preg_match_all(
        "/'([a-z0-9_.]+)':\s*\{\s*en:\s*{$string}\s*,\s*bn:\s*{$string}\s*,?\s*\}/su",
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    // [whole, key, en, bn], whichever quote each string used.
    return array_map(fn (array $m) => [
        $m[0],
        $m[1],
        ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''),
        ($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? ''),
    ], $matches);
}

it('parses every entry in the dictionary', function () {
    $source = dictionarySource();

    preg_match_all("/^\s*'[a-z0-9_.]+':/m", $source, $declared);

    // ⚠ If a key is declared in a shape this file cannot read, every check
    // below skips it in silence - which is exactly the failure this file
    // exists to prevent. Count the keys, not the `bn:` occurrences: the type
    // declaration carries one of those too.
    expect(dictionaryEntries())->toHaveCount(count($declared[0]));
    expect($declared[0])->not->toBeEmpty();
});

it('never ships an English string as the Bangla one', function () {
    $englishOnly = [];

    foreach (dictionaryEntries() as [, $key, $en, $bn]) {
        if (preg_match('/\p{Bengali}/u', $bn) !== 1) {
            $englishOnly[$key] = $bn;
        }

        expect(trim($bn))->not->toBe('', "Key {$key} has an empty Bangla string.");
        expect(trim($en))->not->toBe('', "Key {$key} has an empty English string.");
    }

    expect($englishOnly)->toBe([], 'These keys carry no Bangla at all: '.implode(', ', array_keys($englishOnly)));
});

it('keeps every Bangla string in NFC', function () {
    $decomposed = [];

    foreach (dictionaryEntries() as [, $key, , $bn]) {
        if (Normalizer::normalize($bn, Normalizer::FORM_C) !== $bn) {
            $decomposed[] = $key;
        }
    }

    expect($decomposed)->toBe([], 'These keys are not in NFC: '.implode(', ', $decomposed));
});

it('keeps the same placeholders in both languages', function () {
    foreach (dictionaryEntries() as [, $key, $en, $bn]) {
        preg_match_all('/\{(\w+)\}/', $en, $inEnglish);
        preg_match_all('/\{(\w+)\}/', $bn, $inBangla);

        sort($inEnglish[1]);
        sort($inBangla[1]);

        // A dropped {name} does not fail to compile; it renders as a sentence
        // with a hole in it, in the language nobody reviewing this reads.
        expect($inBangla[1])->toBe($inEnglish[1], "Key {$key} has different placeholders in each language.");
    }
});

it('translates the POS counter, the screen that is read all day', function () {
    $counter = file_get_contents(resource_path('js/pages/sales/counter/create.tsx'));

    expect($counter)->toContain("import { useTranslation } from '@/lib/i18n';");

    // The strings a clerk actually stares at. A future edit that reintroduces
    // any of them as a literal is the whole regression.
    expect($counter)
        ->not->toContain('>Total<')
        ->not->toContain('>Subtotal<')
        ->not->toContain("'Take payment and finish'")
        ->not->toContain('Tap a product to start the sale.');
});

it('defines every key once across the dictionary files', function () {
    // ⚠ The parts are spread into one object: a key defined twice would let
    // the later file silently replace the earlier one.
    preg_match_all("/^\s*'([a-z0-9_.]+)':/m", dictionarySource(), $declared);

    $twice = array_keys(array_filter(array_count_values($declared[1]), fn (int $n) => $n > 1));

    expect($twice)->toBe([], 'Defined more than once: '.implode(', ', $twice));
});

it('speaks Bangla on the courier, dashboard and attention screens (11 Oct 2026)', function (string $file) {
    expect(file_get_contents(resource_path("js/{$file}")))->toContain('useTranslation');
})->with([
    'pages/settings/couriers.tsx',
    'pages/settings/courier-locations.tsx',
    'pages/settings/courier-rate-cards.tsx',
    'pages/settings/dashboards.tsx',
    'pages/settings/failed-jobs.tsx',
    'pages/sales/courier-fees/index.tsx',
    'pages/sales/consignments/index.tsx',
    'pages/sales/courier-statements/index.tsx',
    'pages/sales/courier-statements/show.tsx',
    'components/couriers/courier-parcel.tsx',
    'components/couriers/parcel-settlement.tsx',
    'pages/dashboards/show.tsx',
    'components/dashboards/dashboard-toolbar.tsx',
    'components/dashboards/widget-card.tsx',
    'components/dashboards/widget-body.tsx',
    'components/attention/attention-list.tsx',
    'pages/attention.tsx',
]);

it('names every courier status in Bangla', function () {
    $source = dictionarySource();

    foreach (CourierStatus::cases() as $status) {
        expect($source)->toContain("'courier_status.{$status->value}'");
    }
});
