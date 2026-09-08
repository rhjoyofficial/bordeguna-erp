<?php

namespace App\Modules\Commerce\Actions;

use App\Models\User;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreMedia;
use App\Modules\Foundation\Support\ImageSlimmer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Put an image into a store's library.
 *
 * ⛔ EVERY IMAGE GOES THROUGH `ImageSlimmer` FIRST, because that is the standing
 * rule in this ERP since 3 September: no raw files are kept, everything is
 * downscaled and re-encoded on the way in. It matters more here than anywhere
 * else in the system: these are the only images strangers will ever download,
 * thousands of times each, and a shop that uploads a 6 MB photograph off a
 * phone is a shop whose landing page nobody on mobile data waits for.
 *
 * ⚠ Dimensions are read AFTER slimming, from the file that was actually stored.
 * Reading them from the upload would record the size of a file that no longer
 * exists, and the storefront would reserve the wrong space for every image.
 */
class StoreMediaUpload
{
    public function handle(Store $store, UploadedFile $file, ?User $uploadedBy = null): StoreMedia
    {
        $originalName = $file->getClientOriginalName();

        $file = ImageSlimmer::slim($file);

        $disk = 'local';
        $path = $file->store("store-media/{$store->id}", $disk);

        [$width, $height] = $this->dimensions($disk, $path);

        return StoreMedia::create([
            'business_id' => $store->business_id,
            'store_id' => $store->id,
            'disk' => $disk,
            'path' => $path,
            // ⚠ The name the person recognises, not the temporary one the
            // slimmer produced. Nobody's photograph should become slim_xyz.tmp.
            'original_name' => $originalName,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => Storage::disk($disk)->size($path),
            'width' => $width,
            'height' => $height,
            'uploaded_by' => $uploadedBy?->id,
        ]);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(string $disk, string $path): array
    {
        // ⚠ Never throws. An image GD cannot measure is still a usable image,
        // and refusing an upload over an exotic encoding helps nobody: the
        // consequence of not knowing is a layout that shifts, not a broken page.
        try {
            $full = Storage::disk($disk)->path($path);
            $size = @getimagesize($full);

            return $size === false ? [null, null] : [(int) $size[0], (int) $size[1]];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
