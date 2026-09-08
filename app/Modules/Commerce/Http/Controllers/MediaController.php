<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Actions\StoreMediaUpload;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreMedia;
use App\Modules\Commerce\Sections\SectionReferences;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A store's image library.
 *
 * ⛔ SVG IS NOT ACCEPTED, and that is a security decision rather than a taste
 * one. An SVG is a document that may carry script, and these files are served
 * from the shop's own origin to the public: accepting one would be accepting a
 * cross-site scripting payload with a picture's file extension.
 */
class MediaController extends Controller
{
    public function index(Request $request): Response
    {
        $stores = Store::orderBy('name')->get();
        $storeId = $request->integer('store') ?: $stores->first()?->id;

        $media = StoreMedia::query()
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderByDesc('created_at')
            ->get();

        // ⚠ Counted once here rather than asked per row in the view, which
        // would be a query per image on a library that is meant to grow.
        $usage = $storeId ? $this->usageCounts((int) $storeId) : [];

        return Inertia::render('commerce/media/index', [
            'media' => $media->map(fn (StoreMedia $item) => array_merge($item->toReference(), [
                'alt_text' => $item->alt_text,
                'created_at' => $item->created_at?->toDateString(),
                'used_on' => $usage[$item->id] ?? [],
                // ⚠ A row whose file has gone is a real state after a restore
                // from a database-only backup. It is shown as missing rather
                // than rendered as a broken image.
                'file_missing' => ! $item->exists(),
            ]))->values(),
            'stores' => $stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'key' => $store->key,
            ])->values(),
            'filters' => ['store' => $storeId],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('business_id', $this->businessId())],
            // ⛔ No svg. See the class docblock.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_decorative' => ['boolean'],
        ], [
            'file.mimes' => 'Images only: JPG, PNG or WEBP. An SVG can carry script and is served to the public from your own domain, so it is not accepted.',
        ]);

        $store = Store::findOrFail($data['store_id']);

        $media = app(StoreMediaUpload::class)->handle($store, $request->file('file'), $request->user());

        if (($data['alt_text'] ?? null) !== null || ($data['is_decorative'] ?? false)) {
            $media->update([
                'alt_text' => $data['alt_text'] ?? null,
                'is_decorative' => $data['is_decorative'] ?? false,
            ]);
        }

        return back()->with('success', 'Image added.');
    }

    /**
     * ⚠ Alt text is edited on its own, deliberately. The file never changes:
     * replacing an image in place would silently change every page using it,
     * and "the picture on the ghee page is wrong now" is very hard to trace
     * back to somebody having re-uploaded a file three weeks earlier.
     */
    public function update(Request $request, StoreMedia $medium): RedirectResponse
    {
        $data = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_decorative' => ['boolean'],
        ]);

        $decorative = $data['is_decorative'] ?? false;

        $medium->update([
            // ⚠ A decorative image keeps no alt text at all. Storing both would
            // leave a sentence nobody will ever read and nobody will maintain.
            'alt_text' => $decorative ? null : ($data['alt_text'] ?? null),
            'is_decorative' => $decorative,
        ]);

        return back()->with('success', 'Image details saved.');
    }

    /**
     * ⛔ Deleting an image a page still uses is refused, and the refusal names
     * the pages. This is the whole "never a broken image" rule enforced at the
     * only moment it can be: before the file goes. Afterwards there is nothing
     * to do but notice.
     */
    public function destroy(StoreMedia $medium): RedirectResponse
    {
        $usage = $this->usageCounts($medium->store_id)[$medium->id] ?? [];

        if ($usage !== []) {
            throw ValidationException::withMessages([
                'media' => 'This image is still used on: '.implode(', ', $usage).'. Take it off those pages first.',
            ]);
        }

        Storage::disk($medium->disk)->delete($medium->path);
        $medium->delete();

        return back()->with('success', 'Image deleted.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Which pages use each image on this store.
     *
     * @return array<int, array<int, string>> media id => page titles
     */
    private function usageCounts(int $storeId): array
    {
        $references = app(SectionReferences::class);
        $usage = [];

        $pages = Page::where('store_id', $storeId)->with('sections')->get();

        foreach ($pages as $page) {
            foreach ($page->sections as $section) {
                $type = $section->type();

                if ($type === null) {
                    continue;
                }

                foreach ($references->media($type, $section->payload ?? []) as $mediaId) {
                    $usage[$mediaId][$page->title] = true;
                }
            }
        }

        return array_map(fn (array $titles) => array_keys($titles), $usage);
    }

    private function businessId(): ?int
    {
        return app(BusinessContext::class)->id();
    }
}
