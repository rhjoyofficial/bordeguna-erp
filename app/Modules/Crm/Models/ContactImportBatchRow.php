<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ⭐ One contact an import created or changed, with its values before and
 * after the file (corrections wave 3, 24 Oct 2026). `outcome` is written once,
 * when the import is reversed: removed, restored or kept.
 */
class ContactImportBatchRow extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('An import row is history and is never deleted.');
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ContactImportBatch::class, 'contact_import_batch_id');
    }
}
