<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * ⭐ One applied phone book file (corrections wave 3, 24 Oct 2026): what it
 * created and changed, so it can be reversed (ReverseContactImport).
 *
 * ⚠ History: never deleted. Only the reversed_* columns ever change, once.
 */
class ContactImportBatch extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('An import batch is history and is never deleted.');
        });
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ContactImportBatchRow::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
