<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Models\Popup;
use App\Modules\Foundation\Models\RelaxItem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Relax zone (Joy, 14 Oct 2026): a page every signed-in employee can open
 * for a short break (show, media), and Settings > Relax zone where Owners and
 * Managers keep the list (relax.manage, route middleware). Group-wide.
 *
 * ⚠ An upload is judged BY ITS BYTES: the `mimetypes:` rule reads the type
 * finfo finds in the file, so a text file named .mp3 is refused. The stored
 * mime is that type normalised to one name per format, and it is what the
 * stream declares with nosniff. The file on disk gets a random name; the
 * original name is never used.
 *
 * Every change is written to the activity log.
 */
class RelaxController extends Controller
{
    /** /relax: the active items, in their order, for anybody signed in. */
    public function show(): Response
    {
        return Inertia::render('relax', [
            'items' => RelaxItem::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RelaxItem $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'kind' => $item->kind,
                    'media_url' => $item->mediaUrl(),
                    'media_mime' => $item->media_mime,
                    'youtube_id' => $item->kind === 'youtube' ? $item->youtube_id : null,
                ])
                // A file item whose file is missing has nothing to play.
                ->filter(fn (array $row) => $row['kind'] === 'youtube' ? $row['youtube_id'] !== null : $row['media_url'] !== null)
                ->values(),
        ]);
    }

    /** The uploaded file, for any signed-in person, with Range so it can seek. */
    public function media(Request $request, RelaxItem $item): StreamedResponse|BinaryFileResponse
    {
        abort_if($request->user() === null, 403);
        abort_unless($item->is_active && $item->isFileKind() && is_string($item->media_path) && $item->media_path !== '', 404);

        $disk = Storage::disk(RelaxItem::DISK);

        abort_unless($disk->exists($item->media_path), 404);

        $headers = [
            'Content-Type' => (string) $item->media_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ];

        // response()->file() is a BinaryFileResponse, which answers a Range
        // request with 206: without it a video cannot seek in some browsers.
        if ($disk instanceof FilesystemAdapter && is_file($local = $disk->path($item->media_path))) {
            $response = response()->file($local, $headers);
            $response->setContentDisposition('inline', 'relax-'.$item->id);

            return $response;
        }

        return $disk->response($item->media_path, 'relax-'.$item->id, $headers, 'inline');
    }

    /** Settings > Relax zone: every item, paused ones included. */
    public function index(): Response
    {
        return Inertia::render('settings/relax', [
            'items' => RelaxItem::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (RelaxItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'kind' => $item->kind,
                'media_url' => $item->mediaUrl(),
                'media_size' => $item->media_size,
                'youtube_id' => $item->youtube_id,
                'sort_order' => $item->sort_order,
                'is_active' => $item->is_active,
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $item = RelaxItem::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->applyMedia($request, $item);

        $this->log($request, 'Added a relax zone item', ['relax_item_id' => $item->id, 'title' => $item->title] + $this->facts($item));

        return back()->with('success', 'The item is on the relax zone.');
    }

    /** POST, not PUT: a file can only travel in a multipart body PHP parses on POST. */
    public function update(Request $request, RelaxItem $item): RedirectResponse
    {
        $data = $this->validated($request, $item);
        $before = $this->facts($item);

        $item->update($data + ['updated_by' => $request->user()->id]);
        $replaced = $this->applyMedia($request, $item);

        $this->log($request, 'Changed a relax zone item', [
            'relax_item_id' => $item->id,
            'title' => $item->title,
            'replaced_file' => $replaced,
            'before' => $before,
            'after' => $this->facts($item->fresh()),
        ]);

        return back()->with('success', 'The item was saved.');
    }

    public function toggle(Request $request, RelaxItem $item): RedirectResponse
    {
        $item->update(['is_active' => ! $item->is_active, 'updated_by' => $request->user()->id]);

        $this->log($request, $item->is_active ? 'Resumed a relax zone item' : 'Paused a relax zone item', ['relax_item_id' => $item->id, 'title' => $item->title]);

        return back()->with('success', $item->is_active ? 'The item is on.' : 'The item is paused.');
    }

    /**
     * One place up or down. The whole list is renumbered in steps of 10 first,
     * so two items that share a sort number still swap cleanly.
     */
    public function move(Request $request, RelaxItem $item): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($item, $direction) {
            $ids = RelaxItem::query()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $at = array_search($item->id, $ids, true);
            $to = $direction === 'up' ? $at - 1 : $at + 1;

            if ($at === false || ! isset($ids[$to])) {
                return;
            }

            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

            foreach ($ids as $position => $id) {
                // ⚠ A query update, so updated_at (the media URL's version) is untouched.
                RelaxItem::query()->whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });

        $this->log($request, 'Moved a relax zone item', ['relax_item_id' => $item->id, 'title' => $item->title, 'direction' => $direction]);

        return back();
    }

    public function destroy(Request $request, RelaxItem $item): RedirectResponse
    {
        $properties = ['relax_item_id' => $item->id, 'title' => $item->title, 'kind' => $item->kind];
        $path = $item->media_path;

        $item->delete();
        $this->forget($path);

        $this->log($request, 'Removed a relax zone item', $properties);

        return back()->with('success', 'The item was removed.');
    }

    /** @return array<string, mixed> the columns to write (the file is handled apart) */
    private function validated(Request $request, ?RelaxItem $item): array
    {
        $kind = $request->input('kind');
        $fileRules = match ($kind) {
            'audio' => ['mimetypes:'.implode(',', RelaxItem::AUDIO_MIME_TYPES), 'extensions:'.implode(',', RelaxItem::AUDIO_EXTENSIONS), 'max:'.RelaxItem::AUDIO_MAX_KB],
            'video' => ['mimetypes:'.implode(',', RelaxItem::VIDEO_MIME_TYPES), 'extensions:'.implode(',', RelaxItem::VIDEO_EXTENSIONS), 'max:'.RelaxItem::VIDEO_MAX_KB],
            default => [],
        };

        $data = validator($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::in(RelaxItem::KINDS)],
            'youtube' => ['nullable', 'string', 'max:200', 'required_if:kind,youtube'],
            'file' => ['nullable', 'file', ...$fileRules],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ])->after(function (Validator $v) use ($request, $item, $kind) {
            if ($v->errors()->any()) {
                return;
            }
            if ($kind === 'youtube' && Popup::youtubeId($request->input('youtube')) === null) {
                $v->errors()->add('youtube', 'That is not a YouTube address.');
            }
            // A file kind needs a file: a new item, or a kind switch (an audio
            // file never becomes a video), must bring one.
            $keepsFile = $item !== null && $item->kind === $kind && is_string($item->media_path) && $item->media_path !== '';
            if (in_array($kind, ['audio', 'video'], true) && ! $request->hasFile('file') && ! $keepsFile) {
                $v->errors()->add('file', 'Choose a file.');
            }
        })->validate();

        return [
            'title' => trim($data['title']),
            'description' => ($description = trim((string) ($data['description'] ?? ''))) === '' ? null : $description,
            'kind' => $data['kind'],
            'youtube_id' => $data['kind'] === 'youtube' ? Popup::youtubeId($data['youtube']) : null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) $data['is_active'],
        ];
    }

    /**
     * Store a new file and drop the old one; keep the old one when none was
     * sent; drop it when the item is no longer a file kind. True when a file
     * was replaced or removed.
     */
    private function applyMedia(Request $request, RelaxItem $item): bool
    {
        $old = $item->media_path;

        if (! $item->isFileKind()) {
            if ($old !== null) {
                $item->update(['media_path' => null, 'media_mime' => null, 'media_size' => null]);
                $this->forget($old);

                return true;
            }

            return false;
        }

        if (! $request->hasFile('file')) {
            return false;
        }

        $file = $request->file('file');
        $item->update([
            'media_path' => $file->storeAs(RelaxItem::DIRECTORY, Str::random(40).'.'.strtolower($file->getClientOriginalExtension()), RelaxItem::DISK),
            'media_mime' => $this->servedType($file, $item->kind),
            'media_size' => $file->getSize(),
        ]);

        // The new file is stored before the old one goes, so a failed write
        // never leaves the item pointing at nothing.
        $this->forget($old);

        return $old !== null;
    }

    /** The allowlisted type read from the bytes, normalised to one name per format. */
    private function servedType(UploadedFile $file, string $kind): string
    {
        $type = strtolower((string) $file->getMimeType());

        if ($kind === 'video') {
            return $type === 'video/webm' ? 'video/webm' : 'video/mp4';
        }

        return match ($type) {
            'audio/mpeg', 'audio/mp3' => 'audio/mpeg',
            'audio/ogg' => 'audio/ogg',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'audio/wav',
            default => 'audio/mp4',
        };
    }

    private function forget(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk(RelaxItem::DISK)->delete($path);
        }
    }

    /** @return array<string, mixed> */
    private function facts(RelaxItem $item): array
    {
        return $item->only(['kind', 'youtube_id', 'media_mime', 'media_size', 'sort_order', 'is_active']);
    }

    private function log(Request $request, string $message, array $properties): void
    {
        activity('system')->causedBy($request->user())->withProperties($properties)->log($message);
    }
}
