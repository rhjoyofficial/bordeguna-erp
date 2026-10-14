<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Popup;
use App\Modules\Foundation\Models\Sound;
use App\Modules\Foundation\Support\ImageSlimmer;
use App\Support\Popups\PopupFeed;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings > Popups (Owner, system.popups.manage, 14 Oct 2026): the popup
 * engine's rows. The Bismillah popup is built in (key 'bismillah'): editable
 * and pausable, never deleted, and the server refuses the delete even when
 * the button is not there.
 *
 * Any signed-in person reaches claim() (a once-rule popup about to show) and
 * media() (an uploaded video or image). Everything else is the Owner's.
 */
class PopupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/popups', [
            'popups' => Popup::query()->with('sound')->orderByDesc('priority')->orderBy('id')->get()->map(fn (Popup $popup) => [
                'id' => $popup->id,
                'key' => $popup->key,
                'title' => $popup->title,
                'lines' => $popup->lines,
                'style' => $popup->style,
                'on_login' => $popup->on_login,
                'idle_minutes' => $popup->idle_minutes,
                'audience_roles' => $popup->audience_roles ?? [],
                'frequency' => $popup->frequency,
                'starts_on' => $popup->starts_on?->toDateString(),
                'ends_on' => $popup->ends_on?->toDateString(),
                'sound_mode' => $popup->sound_mode,
                'sound_id' => $popup->sound_id,
                'close_after_seconds' => $popup->close_after_seconds,
                'priority' => $popup->priority,
                'is_active' => $popup->is_active,
                'media_type' => $popup->media_type,
                'media_url' => in_array($popup->media_type, ['video', 'image'], true) ? route('popups.media', ['popup' => $popup->id, 'v' => $popup->updated_at?->timestamp ?? 0]) : null,
                'media_size' => $popup->media_size,
                'youtube_id' => $popup->youtube_id,
                'views' => $popup->views()->count(),
            ])->values(),
            'sounds' => Sound::query()->orderBy('name')->get(['id', 'name'])->map(fn (Sound $s) => ['id' => $s->id, 'name' => $s->name, 'url' => $s->url()]),
            'roles' => Role::query()->distinct()->orderBy('name')->pluck('name')->values(),
            'popupSound' => PopupFeed::sounds()['popup'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $popup = Popup::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->applyMedia($request, $popup);

        $this->log($request, 'Made a popup', ['popup_id' => $popup->id, 'title' => $popup->title] + $this->facts($popup));

        return back()->with('success', 'The popup was made.');
    }

    public function update(Request $request, Popup $popup): RedirectResponse
    {
        $data = $this->validated($request, $popup);
        $before = $this->facts($popup);

        $popup->update($data + ['updated_by' => $request->user()->id]);
        $this->applyMedia($request, $popup);

        $this->log($request, 'Changed a popup', ['popup_id' => $popup->id, 'title' => $popup->title, 'before' => $before, 'after' => $this->facts($popup->fresh())]);

        return back()->with('success', 'The popup was saved.');
    }

    public function toggle(Request $request, Popup $popup): RedirectResponse
    {
        $popup->update(['is_active' => ! $popup->is_active, 'updated_by' => $request->user()->id]);

        $this->log($request, $popup->is_active ? 'Resumed a popup' : 'Paused a popup', ['popup_id' => $popup->id, 'title' => $popup->title]);

        return back()->with('success', $popup->is_active ? 'The popup is on.' : 'The popup is paused.');
    }

    public function destroy(Request $request, Popup $popup): RedirectResponse
    {
        if ($popup->isBuiltIn()) {
            return back()->with('error', 'This popup is built in. Pause it instead.');
        }

        $properties = ['popup_id' => $popup->id, 'title' => $popup->title];
        $this->forgetMedia($popup);
        $popup->delete();

        $this->log($request, 'Removed a popup', $properties);

        return back()->with('success', 'The popup was removed.');
    }

    /** The browser asks before it shows a once-a-day or once-per-person popup. */
    public function claim(Request $request, Popup $popup): JsonResponse
    {
        // ⚠ Audit SWF-005 (14 Oct 2026): the same rules as the feed. A paused,
        // out-of-window or other-audience popup cannot be claimed (and so
        // cannot be marked shown) by calling this address directly.
        $live = Popup::query()->whereKey($popup->id)->liveOn(now()->toDateString())->exists();
        abort_unless($live && $popup->appliesToRoles($request->user()->getRoleNames()->all()), 404);

        return response()->json(['shown' => PopupFeed::claim($popup, $request->user())]);
    }

    /** The uploaded video or image, for any signed-in person, with Range so a video can seek. */
    public function media(Request $request, Popup $popup): StreamedResponse|BinaryFileResponse
    {
        abort_if($request->user() === null, 403);
        abort_unless(in_array($popup->media_type, ['video', 'image'], true) && is_string($popup->media_path), 404);
        // A paused popup's file is for the people who manage popups (the preview), nobody else.
        abort_unless($popup->is_active || $request->user()->can('system.popups.manage'), 404);

        $disk = Storage::disk(Popup::MEDIA_DISK);

        abort_unless($disk->exists($popup->media_path), 404);

        $headers = [
            'Content-Type' => (string) $popup->media_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ];

        if ($disk instanceof FilesystemAdapter && is_file($local = $disk->path($popup->media_path))) {
            $response = response()->file($local, $headers);
            $response->setContentDisposition('inline', 'popup-'.$popup->id);

            return $response;
        }

        return $disk->response($popup->media_path, 'popup-'.$popup->id, $headers, 'inline');
    }

    /** @return array<string, mixed> the columns to write (media handled apart) */
    private function validated(Request $request, ?Popup $existing = null): array
    {
        $data = validator($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1', 'max:8'],
            'lines.*.kind' => ['required', Rule::in(Popup::LINE_KINDS)],
            'lines.*.text' => ['required', 'string', 'max:500'],
            'style' => ['required', Rule::in(Popup::STYLES)],
            'on_login' => ['required', 'boolean'],
            'idle_minutes' => ['nullable', 'integer', 'min:'.Popup::MIN_IDLE_MINUTES, 'max:1440'],
            'audience_roles' => ['nullable', 'array'],
            'audience_roles.*' => ['string', 'max:80'],
            'frequency' => ['required', Rule::in(Popup::FREQUENCIES)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'sound_mode' => ['required', Rule::in(Popup::SOUND_MODES)],
            'sound_id' => ['nullable', 'integer', Rule::exists('sounds', 'id'), 'required_if:sound_mode,custom'],
            'close_after_seconds' => ['nullable', 'integer', 'min:3', 'max:120'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'media_type' => ['required', Rule::in(Popup::MEDIA_TYPES)],
            'youtube' => ['nullable', 'string', 'max:200', 'required_if:media_type,youtube'],
            'video' => ['nullable', 'file', 'mimetypes:'.implode(',', Popup::MEDIA_MIME_TYPES), 'extensions:'.implode(',', Popup::MEDIA_EXTENSIONS), 'max:'.Popup::MEDIA_MAX_KB],
            'image' => ['nullable', 'file', 'image', 'mimetypes:'.implode(',', Popup::IMAGE_MIME_TYPES), 'extensions:'.implode(',', Popup::IMAGE_EXTENSIONS), 'max:'.Popup::IMAGE_MAX_KB],
        ])->after(function (Validator $v) use ($request, $existing) {
            $input = $v->getData();

            if (! $v->errors()->any()) {
                if (! filter_var($input['on_login'] ?? false, FILTER_VALIDATE_BOOLEAN) && empty($input['idle_minutes'])) {
                    $v->errors()->add('on_login', 'Pick when it shows: on sign-in, after idle minutes, or both.');
                }
                if (($input['media_type'] ?? 'none') === 'youtube' && Popup::youtubeId($input['youtube'] ?? null) === null) {
                    $v->errors()->add('youtube', 'That is not a YouTube address.');
                }
                // ⚠ Audit SWF-003 (14 Oct 2026): an image or video popup with no
                // file was saved and quietly became a text popup. Say so instead.
                $kind = $input['media_type'] ?? 'none';
                $hasOld = $existing !== null && is_string($existing->media_mime) && str_starts_with($existing->media_mime, $kind.'/');
                if (in_array($kind, ['image', 'video'], true) && ! $request->hasFile($kind) && ! $hasOld) {
                    $v->errors()->add($kind, $kind === 'image' ? 'Choose the image file.' : 'Choose the video file.');
                }
            }
        })->validate();

        return [
            'title' => trim($data['title']),
            'lines' => array_values(array_map(fn (array $line) => ['kind' => $line['kind'], 'text' => trim($line['text'])], $data['lines'])),
            'style' => $data['style'],
            'on_login' => (bool) $data['on_login'],
            'idle_minutes' => $data['idle_minutes'] ?? null,
            'audience_roles' => array_values(array_unique(array_filter($data['audience_roles'] ?? []))) ?: null,
            'frequency' => $data['frequency'],
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'sound_mode' => $data['sound_mode'],
            'sound_id' => $data['sound_mode'] === 'custom' ? (int) $data['sound_id'] : null,
            'close_after_seconds' => $data['close_after_seconds'] ?? null,
            'priority' => (int) $data['priority'],
            'is_active' => (bool) $data['is_active'],
            'media_type' => $data['media_type'],
            'youtube_id' => $data['media_type'] === 'youtube' ? Popup::youtubeId($data['youtube']) : null,
        ];
    }

    /**
     * Store a new video or image, keep the old file when none was sent, drop it
     * when the type changed. ⚠ An image goes through ImageSlimmer first, the
     * standing rule since 3 Sep: no raw photographs are kept.
     */
    private function applyMedia(Request $request, Popup $popup): void
    {
        $type = $popup->media_type;

        if (! in_array($type, ['video', 'image'], true)) {
            $this->forgetMedia($popup);
            $popup->update(['media_path' => null, 'media_mime' => null, 'media_size' => null]);

            return;
        }

        if ($request->hasFile($type)) {
            $file = $request->file($type);
            $mime = $type === 'image' ? $this->imageType($file->getMimeType()) : (strtolower((string) $file->getMimeType()) === 'video/webm' ? 'video/webm' : 'video/mp4');
            $file = $type === 'image' ? ImageSlimmer::slim($file) : $file;
            $this->forgetMedia($popup);
            $path = $file->storeAs(Popup::MEDIA_DIRECTORY, Str::random(40).'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), Popup::MEDIA_DISK);
            $popup->update([
                'media_path' => $path,
                // The type of what was stored: the slimmer may re-encode.
                'media_mime' => $type === 'image' ? $this->imageType(Storage::disk(Popup::MEDIA_DISK)->mimeType($path) ?: $mime) : $mime,
                'media_size' => Storage::disk(Popup::MEDIA_DISK)->size($path),
                'youtube_id' => null,
            ]);

            return;
        }

        // No new file: keep the old one only if it is the same kind of media.
        $kept = is_string($popup->media_mime) && str_starts_with($popup->media_mime, $type.'/');

        if (! $kept) {
            $this->forgetMedia($popup);
            $popup->update(['media_type' => 'none', 'media_path' => null, 'media_mime' => null, 'media_size' => null]);
        }
    }

    private function imageType(?string $detected): string
    {
        return match (strtolower((string) $detected)) {
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function forgetMedia(Popup $popup): void
    {
        if (is_string($popup->media_path) && $popup->media_path !== '') {
            Storage::disk(Popup::MEDIA_DISK)->delete($popup->media_path);
        }
    }

    /** @return array<string, mixed> */
    private function facts(Popup $popup): array
    {
        return $popup->only(['style', 'on_login', 'idle_minutes', 'audience_roles', 'frequency', 'starts_on', 'ends_on', 'sound_mode', 'sound_id', 'close_after_seconds', 'priority', 'is_active', 'media_type', 'youtube_id']);
    }

    private function log(Request $request, string $message, array $properties): void
    {
        activity('system')->causedBy($request->user())->withProperties($properties)->log($message);
    }
}
