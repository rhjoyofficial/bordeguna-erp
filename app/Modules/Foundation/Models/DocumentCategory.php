<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * What kind of document this is.
 *
 * ⚠⚠ FLAT, AND THAT IS THE DESIGN. Nesting is the feature everybody asks for
 * and nobody maintains: a tree encodes one person's idea of the hierarchy on the
 * day they made it, and every document filed afterwards fits it slightly badly.
 * A category plus a document date plus a reference number answers the same
 * questions, survives being wrong, and can be re-filed with one field.
 */
class DocumentCategory extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'slug', 'retention_months', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'retention_months' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Attachment::class, 'document_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * ⚠ The slug is derived, never typed. It is what the unique index is on, so
     * letting somebody supply it means letting them collide two categories that
     * read identically on screen.
     */
    public static function slugFor(string $name): string
    {
        return Str::slug($name) ?: Str::random(8);
    }

    /**
     * How long a document of this kind is kept.
     *
     * ⚠⚠ NULL MEANS KEEP IT, not "no rule, delete freely". A retention nobody
     * has set must never read as permission to destroy a document; the safe
     * reading of silence in an archive is always to keep.
     */
    public function keepsIndefinitely(): bool
    {
        return $this->retention_months === null;
    }
}
