<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Upload slimming (Joy, 3 Sep 2026): no raw files kept - every image is
 * downscaled and re-encoded ON THE WAY IN, before it is ever stored.
 *
 * The rules, chosen so nothing is ever lost that mattered:
 * - Only JPEG, PNG and WEBP are touched; anything else passes through.
 * - The longest edge is capped at MAX_EDGE; smaller images with JPEG's
 *   quality already sane pass through untouched.
 * - PNG stays PNG so transparency survives (signatures, logos); JPEG and
 *   WEBP re-encode at QUALITY.
 * - If the slimmed file would be BIGGER than the original, the original
 *   wins - the point is fewer bytes, not ceremony.
 * - Slimming NEVER throws. A file GD cannot read is stored as it came,
 *   because refusing somebody's expense bill over an exotic camera format
 *   helps nobody.
 */
class ImageSlimmer
{
    public const MAX_EDGE = 1600;

    private const QUALITY = 80;

    public static function slim(UploadedFile $file): UploadedFile
    {
        try {
            $mime = $file->getClientMimeType();

            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! function_exists('imagecreatefromstring')) {
                return $file;
            }

            $bytes = @file_get_contents($file->getRealPath());
            if ($bytes === false || $bytes === '') {
                return $file;
            }

            $source = @imagecreatefromstring($bytes);
            if ($source === false) {
                return $file;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $edge = max($width, $height);

            // A JPEG straight off a phone benefits from re-encoding even at
            // size; a small PNG or WEBP is already as slim as it should be.
            if ($edge <= self::MAX_EDGE && $mime !== 'image/jpeg') {
                imagedestroy($source);

                return $file;
            }

            if ($edge > self::MAX_EDGE) {
                $scale = self::MAX_EDGE / $edge;
                $newWidth = max(1, (int) round($width * $scale));
                $newHeight = max(1, (int) round($height * $scale));

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                // Transparency must survive the resize for PNG/WEBP.
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($source);
                $source = $resized;
            }

            $target = tempnam(sys_get_temp_dir(), 'slim');
            if ($target === false) {
                imagedestroy($source);

                return $file;
            }

            $written = match ($mime) {
                'image/png' => imagepng($source, $target, 9),
                'image/webp' => function_exists('imagewebp') && imagewebp($source, $target, self::QUALITY),
                default => imagejpeg($source, $target, self::QUALITY),
            };
            imagedestroy($source);

            if (! $written || ! is_file($target) || filesize($target) === 0 || filesize($target) >= strlen($bytes)) {
                @unlink($target);

                return $file;
            }

            return new UploadedFile(
                $target,
                $file->getClientOriginalName(),
                $mime,
                null,
                true,
            );
        } catch (Throwable) {
            return $file;
        }
    }
}
