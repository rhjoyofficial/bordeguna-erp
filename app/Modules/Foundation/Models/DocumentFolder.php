<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A folder in the document archive - the Google-Drive-style tree over the flat
 * `attachments` library (Joy, 28 Sep 2026).
 *
 * ⚠ Organisation only: a folder never decides who may see a file. See
 * FileVisibility and the create migration. Business-scoped, so the tree is one
 * business's; the root is the set of folders with a null parent.
 */
class DocumentFolder extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $table = 'document_folders';

    protected $fillable = [
        'business_id',
        'parent_id',
        'name',
        'created_by',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(Attachment::class, 'folder_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * This folder and every ancestor, root first - the breadcrumb. Walks up in
     * memory rather than one query per level; the tree is shallow (a person's
     * filing, not a filesystem) so a handful of hops is nothing.
     *
     * @return Collection<int, DocumentFolder>
     */
    public function trail(): Collection
    {
        $trail = collect([$this]);
        $node = $this;

        while ($node->parent_id !== null && ($parent = $node->parent) !== null) {
            $trail->prepend($parent);
            $node = $parent;
        }

        return $trail;
    }

    /**
     * Would moving this folder under $target put it inside its own subtree? A
     * folder cannot become its own descendant, which would strand the branch.
     */
    public function wouldCycleInto(?DocumentFolder $target): bool
    {
        if ($target === null) {
            return false;
        }

        for ($node = $target; $node !== null; $node = $node->parent) {
            if ($node->getKey() === $this->getKey()) {
                return true;
            }
        }

        return false;
    }
}
