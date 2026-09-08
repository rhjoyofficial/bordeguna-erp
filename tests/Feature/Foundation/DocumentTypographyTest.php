<?php

use Illuminate\Support\Facades\File;

/**
 * The cascade trap that printed a receipt whose TOTAL was smaller than its item
 * lines, caught statically so it cannot be re-armed on the thirteenth document.
 *
 * ⚠⚠ WHAT HAPPENED. The thermal slip carried
 * `official-document font-mono text-[11px]` on ONE element. In the built
 * stylesheet `.official-document` is UNLAYERED and Tailwind's utilities live
 * inside `@layer utilities`, and in the CSS cascade an unlayered rule beats a
 * layered one whatever the source order. So both utilities were silently dead:
 * the paper rendered in the serif document face at .95rem, while the TOTAL - on
 * a child element, carrying its own size utility where it DID win - printed
 * smaller than the lines above it.
 *
 * ⚠ Nothing about that fails a build, a typecheck or a test that renders the
 * page. The markup is valid, the classes are real, the page returns 200. The
 * only way to see it is to print the paper, and the ERP has printed none.
 *
 * ⚠ FAMILY AND SIZE ONLY. `.official-document` sets `font-family` and
 * `font-size` and nothing else, so `text-black` (a colour) and `font-bold` (a
 * weight) do not conflict and are not flagged. Flagging them would make this
 * guard noise, and a noisy guard gets deleted.
 */
it('never puts a size or family utility on the same element as .official-document', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        $contents = File::get($file->getPathname());

        // ⚠ COMMENTS ARE STRIPPED FIRST, and this guard's own docblock is why:
        // it quotes the offending class string as the example of what not to do,
        // and a scanner that reads prose reports its own explanation as a
        // defect. Block comments cover JSX `{/* ... */}` too.
        $contents = (string) preg_replace(['#/\*.*?\*/#s', '#//.*#'], '', $contents);

        // Every class list that mentions the document face, however it is quoted.
        preg_match_all('/["\'`]([^"\'`]*\bofficial-document\b[^"\'`]*)["\'`]/', $contents, $matches);

        foreach ($matches[1] as $classList) {
            $conflicts = [];

            // Size utilities: the named scale, and arbitrary values that are a
            // LENGTH. `text-[#fff]` is a colour and does not conflict.
            if (preg_match('/\btext-(xs|sm|base|lg|xl|\dxl)\b/', $classList, $m)) {
                $conflicts[] = $m[0];
            }
            if (preg_match('/\btext-\[[0-9.]+(px|pt|rem|em)\]/', $classList, $m)) {
                $conflicts[] = $m[0];
            }
            // Family utilities. Weights (font-bold, font-medium) are fine.
            if (preg_match('/\bfont-(mono|sans|serif)\b/', $classList, $m)) {
                $conflicts[] = $m[0];
            }

            if ($conflicts !== []) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                    .' carries '.implode(' and ', $conflicts).' beside official-document';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['An element carries .official-document AND a font size or family utility.'],
        ['The utility loses: .official-document is unlayered and beats @layer utilities.'],
        ['Put the document typography on an inner element, or set it as an inline style.'],
        $offenders,
    )));
});
