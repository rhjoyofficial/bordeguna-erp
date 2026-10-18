<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * ⚠⚠ DD/MM/YYYY and Dhaka time everywhere (Joy, 18 Oct 2026: "in our country
 * we always write Day Month Year", "time Dhaka Asia +6"), RUN through
 * tests/js/dates.harness.ts on a node whose clock is set to New York, plus
 * source checks for the wiring a harness cannot reach.
 */
function datesDmyUi(array $calls): array
{
    $root = dirname(__DIR__, 2);
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the dates harness.');
    }

    $process = new Process(
        [$node, 'tests/js/run-ts.mjs', 'tests/js/dates.harness.ts'],
        $root,
        ['TZ' => 'America/New_York', 'LANG' => 'en_US.UTF-8'],
        json_encode($calls),
        120,
    );
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('reads a typed date day first, and a pasted ISO date as ISO', function () {
    $texts = ['17/09/2026', '5/9/2026', '05-09-2026', '05.09.2026', '17092026', '2026-09-17', '১৭/০৯/২০২৬', '09/17/2026', '31/02/2026', '17/09'];

    expect(datesDmyUi(array_map(fn ($text) => ['op' => 'parse', 'text' => $text], $texts)))->toBe([
        '2026-09-17', '2026-09-05', '2026-09-05', '2026-09-05', '2026-09-17', '2026-09-17', '2026-09-17', null, null, null,
    ]);
});

it('adds the slashes while digits are typed and keeps a one-digit day', function () {
    expect(datesDmyUi([
        ['op' => 'type', 'keys' => ['1', '7', '0', '9', '2', '0', '2', '6']],
        ['op' => 'type', 'keys' => ['17092026']],
        ['op' => 'type', 'keys' => ['5', '/', '9', '/', '2', '0', '2', '6']],
        ['op' => 'type', 'keys' => ['2026-09-17']],
        ['op' => 'type', 'keys' => ['1', '7', '<BS>', '<BS>']],
        ['op' => 'complete', 'text' => '17/09/20'],
        ['op' => 'typeMonth', 'keys' => ['0', '9', '2', '0', '2', '6']],
        ['op' => 'parseMonth', 'text' => '9/2026'],
    ]))->toBe(['17/09/2026', '17/09/2026', '5/9/2026', '17/09/2026', '1', null, '09/2026', '2026-09']);
});

it('prints every date DD/MM/YYYY and every time on the Dhaka clock, whatever the PC clock says', function () {
    // 2026-10-17T20:30:00Z is 18 Oct 02:30 in Dhaka (and 17 Oct 16:30 in New York).
    $instant = 1792269000000;

    expect(datesDmyUi([
        ['op' => 'show', 'value' => '2026-09-17'],
        ['op' => 'show', 'value' => '2026-09-17 14:05:00'],
        ['op' => 'show', 'value' => '2026-10-17T20:30:00Z'],
        ['op' => 'show', 'value' => '2026-10-18T02:30:00+06:00'],
        ['op' => 'show', 'value' => '17 Sep 2026'],
        ['op' => 'show', 'value' => null],
        ['op' => 'instant', 'ms' => $instant],
        ['op' => 'dmy', 'value' => '2026-01-02'],
        ['op' => 'addDays', 'iso' => '2026-03-01', 'days' => -1],
    ]))->toBe([
        '17/09/2026', '17/09/2026 14:05', '18/10/2026 02:30', '18/10/2026 02:30', '17 Sep 2026', '', '18/10/2026 02:30', '02/01/2026', '2026-02-28',
    ]);

    [$clock] = datesDmyUi([['op' => 'clock', 'ms' => $instant]]);
    expect($clock)->toMatchArray(['date' => '2026-10-18', 'time' => '02:30', 'hour' => 2]);
});

it('turns the dates inside a server-written sentence DD/MM/YYYY, and leaves a document number alone', function () {
    expect(datesDmyUi([
        ['op' => 'text', 'text' => 'add a In punch at 08:10 on 2026-09-23'],
        ['op' => 'text', 'text' => 'Leave 2026-09-26 to 2026-09-27'],
        ['op' => 'text', 'text' => 'Invoice INV-2026-09-17 paid'],
    ]))->toBe(['add a In punch at 08:10 on 23/09/2026', 'Leave 26/09/2026 to 27/09/2026', 'Invoice INV-2026-09-17 paid']);
});

it('sends every date, date-and-time and month box through the DD/MM/YYYY boxes', function () {
    $root = dirname(__DIR__, 2).'/resources/js/';
    $input = file_get_contents($root.'components/ui/input.tsx');

    expect($input)->toContain("if (type === 'date') return <DateInput")
        ->and($input)->toContain("if (type === 'datetime-local') return <DateTimeInput")
        ->and($input)->toContain("if (type === 'month') return <MonthInput");

    // No screen draws the browser's own date box (mm/dd/yyyy on an American PC).
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'tsx' || str_ends_with($file->getPathname(), 'date-input.tsx')) {
            continue;
        }

        if (preg_match('/<input\b[^>]*type="(date|datetime-local|month)"/s', file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
