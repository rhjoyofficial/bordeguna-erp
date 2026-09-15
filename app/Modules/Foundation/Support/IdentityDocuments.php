<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Concerns\HoldsIdentityDocuments;
use App\Modules\Foundation\Enums\IdentityDocument;
use App\Modules\Foundation\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Adding, listing and removing a customer's or supplier's trade licence and NID
 * scans (plan D11). One place, so the conversion form, the customer screen and
 * the supplier screen cannot disagree about what a scan may be or who sees it.
 *
 * ⚠ ADDING an NID is allowed to whoever manages the record, although they may
 * not open it afterwards: the ASM collects the copy in the field and hands it
 * over at conversion. SEEING or REMOVING one needs the identity permission.
 */
final class IdentityDocuments
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $field = 'documents'): array
    {
        return [
            // ⚠ `array:` names the only keys allowed: a kind nobody defined is
            // refused, never stored under a collection nothing gates.
            $field => ['nullable', 'array:'.implode(',', self::kinds())],
            "{$field}.*" => ['nullable', 'array', 'max:'.IdentityDocument::MAX_FILES],
            "{$field}.*.*" => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return collect(IdentityDocument::cases())->map(fn (IdentityDocument $kind) => $kind->value)->all();
    }

    /**
     * @param  Model&HoldsIdentityDocuments  $record
     * @param  array<string, list<UploadedFile>>  $files  keyed by IdentityDocument value
     */
    public static function store(Model $record, array $files, ?User $by): void
    {
        DB::transaction(function () use ($record, $files, $by) {
            foreach (IdentityDocument::cases() as $kind) {
                foreach ($files[$kind->value] ?? [] as $file) {
                    app(StoreAttachment::class)->handle($record, $file, $kind->value, $by);
                }
            }
        });
    }

    /**
     * What the record's screen may show this reader.
     *
     * ⚠ A restricted kind the reader may not open is sent as a COUNT only, never
     * as names or URLs: "an NID is on file" is useful, the file name is not theirs.
     *
     * @param  Model&HoldsIdentityDocuments  $record
     * @return array{kinds: list<array<string, mixed>>, can_add: bool}
     */
    public static function props(Model $record, ?User $reader): array
    {
        $files = $record->attachments()->whereIn('collection', self::kinds())->orderBy('id')->get();

        return [
            'can_add' => $reader?->can($record->documentManagePermission()) ?? false,
            'kinds' => collect(IdentityDocument::cases())->map(function (IdentityDocument $kind) use ($files, $record, $reader) {
                $ofKind = $files->where('collection', $kind->value)->values();
                $mayOpen = ! $kind->isRestricted() || ($reader?->can($record->identityPermission()) ?? false);

                return [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'restricted' => $kind->isRestricted(),
                    'count' => $ofKind->count(),
                    'may_open' => $mayOpen,
                    'may_remove' => $mayOpen && ($reader?->can($record->documentManagePermission()) ?? false),
                    'files' => $mayOpen
                        ? $ofKind->map(fn (Attachment $file) => ['id' => $file->id, 'name' => $file->original_name, 'url' => $file->url()])->all()
                        : [],
                ];
            })->all(),
        ];
    }

    /**
     * @param  Model&HoldsIdentityDocuments  $record
     */
    public static function remove(Model $record, Attachment $file, User $by): void
    {
        $kind = IdentityDocument::tryFrom((string) $file->collection);

        // ⚠ The file must be THIS record's, and one of its identity papers. A
        // route that deleted any attachment by id would delete anybody's.
        abort_unless(
            $kind !== null
            && $file->attachable_type === $record->getMorphClass()
            && (int) $file->attachable_id === (int) $record->getKey(),
            404,
        );

        abort_if($kind->isRestricted() && ! $by->can($record->identityPermission()), 403);

        app(DeleteAttachment::class)->handle($file);
    }

    /** The kind rule for a store request's `kind` field. */
    public static function kindRule(): array
    {
        return ['required', Rule::in(self::kinds())];
    }
}
