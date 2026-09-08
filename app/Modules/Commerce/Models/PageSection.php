<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Commerce\Sections\SectionType;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed block on a page.
 *
 * ⚠ Deliberately NOT business-scoped itself. It reaches its tenant through its
 * page, and every route that touches one resolves the page first, so a second
 * copy of `business_id` here would be a third place for the same fact to be
 * wrong. The scope that matters is enforced one level up, where the row that
 * carries the tenant lives.
 */
class PageSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'type_key',
        'type_version',
        'position',
        'is_hidden',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_hidden' => 'boolean',
            'type_version' => 'integer',
            'position' => 'integer',
        ];
    }

    protected $attributes = [
        'type_version' => 1,
        'position' => 0,
        'is_hidden' => false,
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function identity(): string
    {
        return "{$this->type_key}@{$this->type_version}";
    }

    /**
     * The contract this section was authored against.
     *
     * ⚠ Returns null rather than throwing when the type is not registered. A
     * deploy that retires a section type leaves rows like this behind, and the
     * composer has to be able to SHOW one in order to let somebody remove it.
     * The registry's own `get()` throws, correctly, because a STOREFRONT hitting
     * an unknown type is a deployment mistake; a back-office screen listing one
     * is just Tuesday.
     */
    public function type(): ?SectionType
    {
        $registry = app(SectionTypeRegistry::class);

        return $registry->has($this->type_key, $this->type_version)
            ? $registry->get($this->type_key, $this->type_version)
            : null;
    }
}
