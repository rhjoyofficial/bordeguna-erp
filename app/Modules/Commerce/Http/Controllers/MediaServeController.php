<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\StoreMedia;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one public door in this module.
 *
 * ⛔⛔ ANONYMOUS BY DESIGN, AND IT IS THE ONLY THING HERE THAT IS. A storefront
 * image is downloaded by strangers who have no session and no business context,
 * so this route sits outside `auth` and outside `business` and steps out of
 * `BusinessScope` explicitly. It is written as one greppable
 * `withoutGlobalScope` call rather than a branch, exactly as `Store::resolveByHost`
 * is, so every cross-tenant read in this codebase stays countable.
 *
 * ⚠ THE URL CARRIES A UUID, NOT AN ID, and that is what makes anonymity safe.
 * Sequential ids can be walked from 1, so a store that has not launched would
 * hand its unreleased product photography to anybody counting upwards.
 *
 * ⚠ Cached hard and immutably. The bytes behind a uuid never change, because
 * editing an image is not a thing this module offers: a new picture is a new
 * upload with a new uuid. That is what lets the cache header be honest.
 */
class MediaServeController extends Controller
{
    public function __invoke(Request $request, string $uuid): StreamedResponse
    {
        $media = StoreMedia::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->where('uuid', $uuid)
            ->first();

        abort_if($media === null, 404);

        // MFC-001 (14 Oct 2026): on a shop's own hostname, only that shop's
        // business's media, so one brand's domain cannot serve another's files.
        $store = $request->attributes->get('storefront_store');
        abort_if($store !== null && (int) $store->business_id !== (int) $media->business_id, 404);

        $disk = Storage::disk($media->disk);

        // ⚠ A row whose file has gone is a real state after a restore from a
        // database-only backup. It answers 404 rather than a 500, because a
        // missing picture is not a broken website: the theme puts its
        // placeholder there and the page carries on.
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Disposition' => 'inline; filename="'.addslashes($media->original_name).'"',
            // ⛔ Belt and braces against a proxy or a browser extension deciding
            // a jpeg is really something executable.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
