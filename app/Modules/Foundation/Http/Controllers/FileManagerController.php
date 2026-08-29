<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\FileVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every file the business holds, in one place.
 *
 * ⚠⚠ NO NEW TABLE AND NO NEW DOWNLOAD ROUTE. Files already live in
 * `attachments` on a private disk, reachable only through the authenticated,
 * business-scoped `attachments.show` route. A file manager that introduced its
 * own storage or its own streaming endpoint would be a second security surface
 * doing the same job, and the second one is always the one that is wrong.
 *
 * ⚠⚠ WHO SEES WHAT IS DECIDED BY FileVisibility, not here. Read that class
 * before changing this one: a browser over the attachments table is the easiest
 * way in this system to show somebody every payroll document at once.
 */
class FileManagerController extends Controller
{
    public function index(Request $request, FileVisibility $visibility): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'type' => (string) $request->query('type', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $allowedTypes = $visibility->typesVisibleTo($user);
        $mapped = $visibility->mappedTypes();
        $seesUnmapped = $visibility->maySeeUnmappedTypes($user);

        // ⚠ A reader entitled to nothing gets an empty list rather than an
        // unfiltered one. Without the guard, `whereIn('attachable_type', [])`
        // combined with the unmapped branch could widen instead of narrow.
        $files = Attachment::query()
            ->with('uploadedBy:id,name')
            ->where(function (Builder $q) use ($allowedTypes, $mapped, $seesUnmapped) {
                $q->whereIn('attachable_type', $allowedTypes);

                if ($seesUnmapped) {
                    $q->orWhereNotIn('attachable_type', $mapped);
                }
            })
            ->when($filters['type'] !== '' && in_array($filters['type'], $allowedTypes, true),
                fn (Builder $q) => $q->where('attachable_type', $filters['type']))
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where('original_name', 'like', '%'.$filters['search'].'%'))
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'kind' => $this->kind($file),
                'size' => $this->readableSize($file->size),
                'mime' => $file->mime_type,
                'uploaded_by' => $file->uploadedBy?->name ?? 'System',
                'uploaded_at' => $file->created_at?->format('j M Y, g:i a'),
                'expires_on' => $file->expires_at?->toDateString(),
                'expired' => $file->isExpired(),
                'url' => route('attachments.show', $file),
                'can_delete' => $visibility->mayDelete($user, $file->attachable_type, $file->collection),
            ]);

        return Inertia::render('files/index', [
            'files' => $files,
            'filters' => $filters,
            // ⚠ Only the types this reader may actually see, so the filter can
            // never name a record kind they are not allowed to know exists.
            'types' => collect($allowedTypes)
                ->map(fn (string $type) => ['value' => $type, 'label' => Str::headline(class_basename($type))])
                ->sortBy('label')->values(),
            'can' => ['manage' => $user->can('files.manage')],
        ]);
    }

    /**
     * Upload a standalone file into the shared library.
     *
     * ⚠ It attaches to the BUSINESS, which is a shape StoreAttachment already
     * understands, so a library file needs no new column and no new rules.
     */
    public function store(Request $request, StoreAttachment $store): RedirectResponse
    {
        $request->validate([
            // ⚠⚠ An allow-list of extensions, not a size limit alone. This is a
            // general upload box reachable by most of the team, and the thing
            // that must never land on the disk is an executable somebody was
            // talked into uploading.
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,gif,csv,txt,doc,docx,xls,xlsx,ppt,pptx,zip'],
            'expires_on' => ['nullable', 'date'],
        ]);

        $business = Business::findOrFail(app(BusinessContext::class)->id());

        $store->handle(
            attachable: $business,
            file: $request->file('file'),
            collection: FileVisibility::LIBRARY,
            uploadedBy: $request->user(),
            expiresAt: $request->input('expires_on') ?: null,
        );

        return back()->with('success', 'File added to the library.');
    }

    /**
     * ⚠⚠ LIBRARY FILES ONLY. See FileVisibility::mayDelete: the evidence behind
     * an expense claim or a payroll adjustment is not something a generic file
     * browser gets a delete button for.
     */
    public function destroy(Request $request, Attachment $attachment, FileVisibility $visibility, DeleteAttachment $delete): RedirectResponse
    {
        abort_unless(
            $visibility->mayDelete($request->user(), $attachment->attachable_type, $attachment->collection),
            403,
            'That file belongs to a record. Remove it from the record it is evidence for, where somebody can see what was removed.',
        );

        $name = $attachment->original_name;

        $delete->handle($attachment);

        return back()->with('success', "{$name} deleted.");
    }

    /**
     * What this file hangs on, said out loud.
     */
    private function kind(Attachment $file): string
    {
        if ($file->attachable_type === Business::class && $file->collection === FileVisibility::LIBRARY) {
            return 'Library';
        }

        return Str::headline(class_basename((string) $file->attachable_type));
    }

    private function readableSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
