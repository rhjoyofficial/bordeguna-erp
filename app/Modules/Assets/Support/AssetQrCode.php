<?php

namespace App\Modules\Assets\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code on an item's tag, as inline SVG (plan §2.8).
 *
 * ⚠ It encodes the item's PERMANENT address and nothing else: never where the
 * item is or who has it, which change, and never its cost.
 *
 * ⚠ Error correction M: a sticker on a desk leg gets scuffed, and M still reads
 * with about 15% of the code damaged while staying small enough for a 21 mm
 * label.
 */
final class AssetQrCode
{
    public static function svg(string $content, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        $svg = $writer->writeString($content, Encoder::DEFAULT_BYTE_MODE_ECODING, ErrorCorrectionLevel::M());

        // Inline in a page, so no XML declaration.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }
}
