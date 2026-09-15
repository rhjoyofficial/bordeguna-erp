<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\DocumentCategory;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\FileVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'category' => (string) $request->query('category', ''),
            // ⚠ Superseded documents are HIDDEN by default and never deleted.
            // The archive's job is to show the version in force; the history is
            // one checkbox away for whoever needs to prove what changed.
            // ⚠ Kept as a STRING like every other filter. useFilters round-trips
            // these through the query string, and one boolean among strings
            // breaks the shared type for all of them.
            'superseded' => $request->boolean('superseded') ? '1' : '',
        ];

        $allowedTypes = $visibility->typesVisibleTo($user);
        $mapped = $visibility->mappedTypes();
        $seesUnmapped = $visibility->maySeeUnmappedTypes($user);

        // ⚠ A reader entitled to nothing gets an empty list rather than an
        // unfiltered one. Without the guard, `whereIn('attachable_type', [])`
        // combined with the unmapped branch could widen instead of narrow.
        // ⚠⚠ For a type whose records are narrowed per reader (a lead: its owner
        // and team only), holding the permission is not enough - the file's own
        // record must be one this reader can see. The subquery goes through the
        // model, so its visibility scope decides, not a copy of the rule here.
        $rowScoped = array_values(array_intersect($allowedTypes, $visibility->rowScopedTypes()));
        $plainTypes = array_values(array_diff($allowedTypes, $rowScoped));

        $files = Attachment::query()
            ->with(['uploadedBy:id,name', 'category:id,name'])
            ->where(function (Builder $q) use ($plainTypes, $rowScoped, $mapped, $seesUnmapped) {
                $q->whereIn('attachable_type', $plainTypes);

                foreach ($rowScoped as $type) {
                    $q->orWhere(fn (Builder $one) => $one
                        ->where('attachable_type', $type)
                        ->whereIn('attachable_id', $type::query()->select((new $type)->getQualifiedKeyName())));
                }

                if ($seesUnmapped) {
                    $q->orWhereNotIn('attachable_type', $mapped);
                }
            })
            ->when($filters['type'] !== '' && in_array($filters['type'], $allowedTypes, true),
                fn (Builder $q) => $q->where('attachable_type', $filters['type']))
            ->when($filters['category'] !== '', fn (Builder $q) => $q->where('document_category_id', $filters['category']))
            // ⚠ Searches the TITLE and the REFERENCE as well as the filename.
            // Somebody looking for a trade licence types "licence", not
            // "scan_0012.pdf", and a search that only reads the filename is a
            // search that finds nothing anybody actually looks for.
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $inner->where('original_name', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('description', 'like', $term);
            }))
            ->unless($filters['superseded'] === '1', fn (Builder $q) => $q->whereNull('superseded_by_id'))
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->displayTitle(),
                'filename' => $file->original_name,
                'description' => $file->description,
                'reference' => $file->reference,
                'document_date' => $file->document_date?->toDateString(),
                'category' => $file->category?->name,
                'category_id' => $file->document_category_id,
                'version' => $file->version,
                'superseded' => $file->isSuperseded(),
                // Which player the screen should draw. See previewKind: the
                // stored mime is a hint, and nosniff is what makes it safe.
                'previewable' => $this->previewKind($file),
                'kind' => $this->kind($file),
                'size' => $this->readableSize($file->size),
                'mime' => $file->mime_type,
                'uploaded_by' => $file->uploadedBy?->name ?? 'System',
                'uploaded_at' => $file->created_at?->format('j M Y, g:i a'),
                'expires_on' => $file->expires_at?->toDateString(),
                'expired' => $file->isExpired(),
                'expiring' => $file->expiresWithin(),
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
            'categories' => DocumentCategory::query()->active()->orderBy('name')
                ->get(['id', 'name', 'retention_months'])
                ->map(fn (DocumentCategory $c) => [
                    'value' => (string) $c->id,
                    'label' => $c->name,
                    'retention_months' => $c->retention_months,
                ]),
            // ⚠ Counted on the WHOLE archive, not on the page being shown. A
            // licence expiring next month is not less urgent because it happens
            // to be on page three.
            'expiring_count' => Attachment::query()
                ->whereNull('superseded_by_id')
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', now()->toDateString())
                ->whereDate('expires_at', '<=', now()->addDays(60)->toDateString())
                ->count(),
            'expired_count' => Attachment::query()
                ->whereNull('superseded_by_id')
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', now()->toDateString())
                ->count(),
            'can' => ['manage' => $user->can('files.manage')],
        ]);
    }

    /**
     * How this file can be shown in the page, if at all.
     *
     * ⚠⚠ THE STORED MIME IS THE ONE THE BROWSER CLAIMED (StoreAttachment keeps
     * getClientMimeType), so it is a HINT, not a guarantee - no more trustworthy
     * than the extension. What actually makes previewing safe is elsewhere and
     * is worth knowing: the upload rule validates the file's real detected type
     * against an allow-list, and the stream sends an explicit Content-Type with
     * `nosniff`, so a file cannot talk the browser into treating it as something
     * else. This method only decides which player to draw.
     *
     * ⚠ The extension is consulted ONLY when the claimed type is missing or the
     * generic octet-stream, which is what several browsers send for mp4 and mov.
     * Without that fallback a perfectly good video simply refuses to preview.
     *
     * ⚠ Anything unrecognised returns null and the screen offers a download
     * rather than an empty frame. A preview that silently shows nothing reads as
     * a broken file rather than as an unsupported one.
     */
    private function previewKind(Attachment $file): ?string
    {
        $mime = strtolower((string) $file->mime_type);

        $kind = match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' => 'pdf',
            default => null,
        };

        if ($kind !== null) {
            return $kind;
        }

        // ⚠⚠ ALWAYS fall back to the extension, not only for octet-stream.
        // A faked mp4 in the test suite reports `application/mp4`, which is a
        // REAL type browsers send - it is neither video/* nor the generic
        // octet-stream, so a narrower rule silently refused to preview a
        // perfectly good video. There is no safety cost to being generous here:
        // what stops a file being treated as something dangerous is the upload
        // allow-list and the nosniff header, not this guess.
        return match (strtolower(pathinfo((string) $file->original_name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg', 'png', 'webp', 'gif' => 'image',
            'mp4', 'webm', 'mov', 'm4v' => 'video',
            'mp3', 'wav', 'ogg' => 'audio',
            'pdf' => 'pdf',
            default => null,
        };
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
            // ⚠⚠ AN ALLOW-LIST OF EXTENSIONS, not a size limit alone. This is
            // a general upload box reachable by most of the team, and the thing
            // that must never land on the disk is an executable somebody was
            // talked into uploading.
            //
            // ⚠ Video and audio are here because Joy asked for them, and the
            // cap is raised to 256MB to match. ⛔ THE SERVER HAS TO AGREE:
            // upload_max_filesize, post_max_size and the reverse proxy's own
            // body limit all cap this independently, and when they are lower the
            // request is rejected before Laravel ever sees it - so the user gets
            // a bare 413 instead of this rule's sentence.
            'file' => ['required', 'file', 'max:262144', 'mimes:pdf,jpg,jpeg,png,webp,gif,csv,txt,doc,docx,xls,xlsx,ppt,pptx,zip,mp4,webm,mov,m4v,mp3,wav,ogg'],
            'expires_on' => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'document_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'document_category_id' => ['nullable', Rule::exists('document_categories', 'id')],
        ]);

        $business = Business::findOrFail(app(BusinessContext::class)->id());

        $attachment = $store->handle(
            attachable: $business,
            file: $request->file('file'),
            collection: FileVisibility::LIBRARY,
            uploadedBy: $request->user(),
            expiresAt: $request->input('expires_on') ?: null,
        );

        // ⚠ The metadata is written onto the row StoreAttachment HANDED BACK,
        // never onto "the newest attachment" - two people filing at once would
        // otherwise stamp each other's document. StoreAttachment is shared by
        // every module that attaches a file to a record, so it has no business
        // knowing about archive fields; this is the right place for them.
        $attachment->update($request->only([
            'title', 'description', 'document_date', 'reference', 'document_category_id',
        ]));

        return back()->with('success', 'Filed in the archive.');
    }

    /**
     * Add a kind of document.
     *
     * ⚠ Categories are created from the archive screen rather than from a
     * settings page, because the moment somebody needs one is the moment they
     * are filing a document that does not fit any of the existing ones. Sending
     * them to another screen to come back is how documents end up uncategorised
     * forever.
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // ⚠ NULL means keep indefinitely. See DocumentCategory: silence in
            // an archive must never read as permission to destroy.
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = DocumentCategory::slugFor($data['name']);
        $businessId = app(BusinessContext::class)->id();

        if (DocumentCategory::query()->where('slug', $slug)->exists()) {
            return back()->withErrors(['name' => 'There is already a category with that name.']);
        }

        DocumentCategory::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'slug' => $slug,
            'retention_months' => $data['retention_months'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        return back()->with('success', "Added {$data['name']}.");
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
