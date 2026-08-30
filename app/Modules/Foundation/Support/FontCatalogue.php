<?php

namespace App\Modules\Foundation\Support;

/**
 * The faces a document may be set in.
 *
 * Joy, 30 August 2026: make the font choice a setting rather than a decision
 * baked into the code, with sensible defaults chosen on the evidence.
 *
 * ⚠⚠ EVERY FACE HERE COVERS BANGLA **AND** LATIN IN ONE FAMILY, and that is the
 * entry requirement, not a nicety. Most of this business's writing is bilingual
 * inside a single sentence - a customer name in Bangla beside an invoice number
 * in Latin digits. A face that covers only one of them does not fail loudly; the
 * browser silently borrows a second font for the other script, and the document
 * goes out in two typefaces that were never chosen together. The previous
 * payslip font, DejaVu Sans, is exactly that trap: fine in English, and no
 * Bengali glyphs at all.
 *
 * ⚠⚠ `pdf` IS NOT COSMETIC. Documents are sent as PDF because the circular says
 * the layout must not shift, and the payslip PDF is rendered by dompdf, which
 * can parse only static TrueType from local disk. It cannot fetch a font, and it
 * cannot use woff2. A face marked pdf:false still works everywhere a browser
 * renders, and falls back in the one place a font must NOT silently change.
 * OfficialDocumentFontTest renders a real PDF per face and asserts the embedded
 * subset, so this flag is measured rather than believed.
 */
final class FontCatalogue
{
    public const DEFAULT = 'tiro_bangla';

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            'tiro_bangla' => [
                'bundled' => true,
                'label' => 'Tiro Bangla',
                'category' => 'Serif',
                'stack' => "'Tiro Bangla', 'Noto Serif Bengali', Georgia, serif",
                'css_family' => 'Tiro Bangla',
                'ttf' => 'fonts/tiro-bangla/TiroBangla-Regular.ttf',
                'woff2' => 'fonts/tiro-bangla/tiro-bangla-latin.woff2',
                'pdf' => true,
                'note' => 'The face named in Office Circular BdG-HQ-OD-26071201. A text serif cut for '
                    .'Bangla by Tiro Typeworks, with matching Latin, so a bilingual line stays one voice.',
            ],
            'noto_serif_bengali' => [
                'bundled' => true,
                'label' => 'Noto Serif Bengali',
                'category' => 'Serif',
                'stack' => "'Noto Serif Bengali', 'Tiro Bangla', Georgia, serif",
                'css_family' => 'Noto Serif Bengali',
                'ttf' => 'fonts/library/NotoSerifBengali-Variable.ttf',
                'woff2' => null,
                'pdf' => false,
                'note' => 'Google and Monotype\'s Bengali serif, the widest character coverage of any of '
                    .'these. Published only as a variable font, which is why it is not offered for PDFs.',
            ],
            'noto_sans_bengali' => [
                'bundled' => true,
                'label' => 'Noto Sans Bengali',
                'category' => 'Sans',
                'stack' => "'Noto Sans Bengali', 'Hind Siliguri', system-ui, sans-serif",
                'css_family' => 'Noto Sans Bengali',
                'ttf' => 'fonts/library/NotoSansBengali-Variable.ttf',
                'woff2' => null,
                'pdf' => false,
                'note' => 'The sans companion to the above. A plainer, more modern register than a serif, '
                    .'and easier to read at small sizes on a screen.',
            ],
            'nikosh' => [
                'label' => 'Nikosh (installed on the reader machine)',
                'category' => 'Serif',
                'stack' => "'Nikosh', 'NikoshBAN', 'Tiro Bangla', Georgia, serif",
                'css_family' => 'Nikosh',
                // ⚠⚠ NOT BUNDLED, AND THAT IS A LICENCE DECISION, NOT AN
                // OVERSIGHT. Nikosh is the Bangla Academy / Bangladesh Computer
                // Council face that government offices expect, but it is
                // released under Creative Commons BY-NC-ND 3.0:
                // NonCommercial and NoDerivatives. Shipping it inside a
                // commercial company's ERP is redistribution for a commercial
                // purpose, and subsetting or converting it to woff2 would be a
                // derivative. Both are outside that licence however many
                // download sites call the font "free for commercial use".
                //
                // ⚠ So nothing is redistributed: the page simply ASKS for
                // Nikosh, and it renders on machines that already have it
                // installed - which, in Bangladeshi government-facing offices,
                // is most of them. Anywhere else the stack falls through to
                // Tiro Bangla and the document still reads correctly.
                'ttf' => null,
                'woff2' => null,
                'bundled' => false,
                'pdf' => false,
                'note' => 'The face Bangladeshi government offices expect. It is NOT shipped with the system, '
                    .'because its licence (CC BY-NC-ND) does not allow that for a commercial business - it renders '
                    .'only on computers where somebody has installed Nikosh, and falls back to Tiro Bangla '
                    .'everywhere else, including in PDFs.',
            ],
            'hind_siliguri' => [
                'bundled' => true,
                'label' => 'Hind Siliguri',
                'category' => 'Sans',
                'stack' => "'Hind Siliguri', 'Noto Sans Bengali', system-ui, sans-serif",
                'css_family' => 'Hind Siliguri',
                'ttf' => 'fonts/library/HindSiliguri-Regular.ttf',
                'woff2' => null,
                'pdf' => true,
                'note' => 'A compact interface face from the Indian Type Foundry, very widely used on '
                    .'Bangladeshi sites. Short vertical metrics, so it holds up in dense tables.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get(?string $key): array
    {
        $all = self::all();

        return $all[$key] ?? $all[self::DEFAULT];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The CSS font stack for a chosen key.
     *
     * ⚠ Always a STACK, never a single family. A face that fails to load for
     * any reason must land on something that can still write Bangla rather than
     * on the browser's default, which on many machines cannot.
     */
    public static function stack(?string $key): string
    {
        return (string) self::get($key)['stack'];
    }

    /**
     * What dompdf should be told to use.
     *
     * ⚠⚠ Falls back to the DEFAULT rather than to whatever was chosen. If
     * somebody picks a face that only browsers can render, the PDF must come out
     * in the official font, not in dompdf's own last resort - which has no
     * Bengali glyphs and would print a Bangla name as empty boxes.
     *
     * @return array{family:string, ttf:string}
     */
    public static function forPdf(?string $key): array
    {
        $font = self::get($key);

        // ⚠ Covers the non-bundled case too: a face with no file on the server
        // cannot possibly be embedded, whatever its pdf flag says.
        if ($font['pdf'] !== true || ($font['ttf'] ?? null) === null) {
            $font = self::get(self::DEFAULT);
        }

        return ['family' => (string) $font['css_family'], 'ttf' => (string) $font['ttf']];
    }
}
