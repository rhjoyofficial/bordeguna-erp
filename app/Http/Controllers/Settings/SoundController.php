<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Sound;
use App\Modules\Foundation\Models\SoundSlot;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings > Sounds (Owner, system.popups.manage, 14 Oct 2026): the sound
 * library and which sound each event plays. Any signed-in person may play a
 * sound (file); only the Owner changes anything, and every change is logged.
 *
 * ⚠ The stored mime is the one finfo READ FROM THE BYTES, normalised to the
 * allowlist, and it is what the stream declares with nosniff. The original
 * file name is only a default for the display name; the file on disk gets a
 * random name.
 */
class SoundController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/sounds', [
            'sounds' => Sound::query()->orderBy('name')->get()->map(fn (Sound $sound) => [
                'id' => $sound->id,
                'name' => $sound->name,
                'mime_type' => $sound->mime_type,
                'size' => $sound->size,
                'duration_ms' => $sound->duration_ms,
                'url' => $sound->url(),
                'used_by' => $sound->usedBy(),
                'created_at' => $sound->created_at?->toIso8601String(),
            ])->values(),
            'slots' => SoundSlot::query()->get()->mapWithKeys(fn (SoundSlot $slot) => [$slot->slot => $slot->sound_id]),
            'builtIn' => ['success' => '/sounds/success.mp3', 'attention' => '/sounds/attention.mp3', 'error' => '/sounds/error.mp3', 'schedule' => '/sounds/success.mp3'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'file' => $this->fileRules(true),
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        $file = $request->file('file');
        $sound = Sound::create([
            'name' => trim((string) ($data['name'] ?? '')) ?: Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 80, ''),
            'disk' => Sound::DISK,
            'path' => $this->put($file),
            'mime_type' => $this->servedType($file),
            'size' => $file->getSize(),
            'duration_ms' => $data['duration_ms'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        $this->log($request, 'Added a sound', ['sound_id' => $sound->id, 'name' => $sound->name, 'size' => $sound->size]);

        return back()->with('success', 'The sound is in the library.');
    }

    /** Rename, and/or replace the file. Replacing keeps every link to this sound. */
    public function update(Request $request, Sound $sound): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'file' => $this->fileRules(false),
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        $changes = ['name' => trim($data['name'])];
        $properties = ['sound_id' => $sound->id, 'name' => $changes['name'], 'was' => $sound->name];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $old = $sound->path;
            $changes += [
                'path' => $this->put($file),
                'mime_type' => $this->servedType($file),
                'size' => $file->getSize(),
                'duration_ms' => $data['duration_ms'] ?? null,
            ];
            $properties['replaced_file'] = true;
            $properties['used_by'] = $sound->usedBy();
        }

        $sound->update($changes);

        if (isset($old)) {
            Storage::disk($sound->disk)->delete($old);
        }

        $this->log($request, 'Changed a sound', $properties);

        return back()->with('success', 'The sound was saved.');
    }

    public function destroy(Request $request, Sound $sound): RedirectResponse
    {
        if ($sound->inUse()) {
            return back()->with('error', 'This sound is in use. Point the events and popups that use it elsewhere first.');
        }

        $path = $sound->path;
        $disk = $sound->disk;
        $properties = ['sound_id' => $sound->id, 'name' => $sound->name];

        $sound->delete();
        Storage::disk($disk)->delete($path);

        $this->log($request, 'Removed a sound', $properties);

        return back()->with('success', 'The sound was removed.');
    }

    /** Which library sound each event plays; null puts the event back on its built-in sound. */
    public function slots(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slots' => ['required', 'array'],
            'slots.*' => ['nullable', 'integer', Rule::exists('sounds', 'id')],
        ]);

        $changed = [];

        foreach (SoundSlot::SLOTS as $name) {
            if (! array_key_exists($name, $data['slots'])) {
                continue;
            }

            $slot = SoundSlot::query()->firstOrCreate(['slot' => $name]);
            $soundId = $data['slots'][$name] === null ? null : (int) $data['slots'][$name];

            if ($slot->sound_id !== $soundId) {
                $changed[$name] = ['from' => $slot->sound_id, 'to' => $soundId];
                $slot->update(['sound_id' => $soundId, 'updated_by' => $request->user()->id]);
            }
        }

        if ($changed !== []) {
            $this->log($request, 'Changed which sound an event plays', ['slots' => $changed]);
        }

        return back()->with('success', 'The event sounds were saved.');
    }

    /** The bytes, for any signed-in person. The URL carries a version, so a long cache is safe. */
    public function file(Request $request, Sound $sound): StreamedResponse|BinaryFileResponse
    {
        abort_if($request->user() === null, 403);

        $disk = Storage::disk($sound->disk);

        abort_unless($disk->exists($sound->path), 404);

        $headers = [
            'Content-Type' => $sound->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ];

        if ($disk instanceof FilesystemAdapter && is_file($local = $disk->path($sound->path))) {
            $response = response()->file($local, $headers);
            $response->setContentDisposition('inline', 'sound-'.$sound->id);

            return $response;
        }

        return $disk->response($sound->path, 'sound-'.$sound->id, $headers, 'inline');
    }

    /** @return list<mixed> */
    private function fileRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimetypes:'.implode(',', Sound::MIME_TYPES),
            'extensions:'.implode(',', Sound::EXTENSIONS),
            'max:'.Sound::MAX_KB,
        ];
    }

    private function put(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $file->storeAs(Sound::DIRECTORY, Str::random(40).'.'.$extension, Sound::DISK);
    }

    /** The allowlisted type finfo read from the bytes, normalised to one name per format. */
    private function servedType(UploadedFile $file): string
    {
        return match (strtolower((string) $file->getMimeType())) {
            'audio/mpeg', 'audio/mp3' => 'audio/mpeg',
            'audio/ogg' => 'audio/ogg',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'audio/wav',
            default => 'audio/mp4',
        };
    }

    private function log(Request $request, string $message, array $properties): void
    {
        activity('system')->causedBy($request->user())->withProperties($properties)->log($message);
    }
}
