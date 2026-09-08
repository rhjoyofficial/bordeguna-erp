<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Sections\SectionPayloadValidator;
use App\Modules\Commerce\Sections\SectionReferences;
use App\Modules\Commerce\Sections\SectionType;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One page on one store.
 *
 * ⭐ A page is an ADDRESS plus an ORDERED LIST OF SECTIONS. It holds no markup,
 * no colours and no layout, because the moment a page holds those a theme stops
 * being a skin and a theme switch becomes a rewrite.
 *
 * ⛔ `published_at` is NOT fillable. Publishing puts words in front of the
 * public under the company's name, so it moves through `publish()` and
 * `unpublish()` and no request path can mass-assign it. Same rule `Store::status`
 * already follows for the same reason.
 */
class Page extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'store_id',
        'type',
        'slug',
        'title',
        'seo_title',
        'seo_description',
    ];

    protected function casts(): array
    {
        return [
            'type' => PageType::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * ⚠ Mirrors the database default in the model, as the standing rule in this
     * repository requires: a column default only applies once the row is
     * written, so a freshly made Page would report a null type until it was
     * reloaded.
     */
    protected $attributes = [
        'type' => 'custom',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * ⚠ Ordered here, once, rather than at each call site. An ordering that some
     * screens apply and others forget is not an ordering.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('position');
    }

    public function visibleSections(): HasMany
    {
        return $this->sections()->where('is_hidden', false);
    }

    /**
     * ⚠ A future `published_at` is scheduled, not live. Asking "is it published"
     * and meaning "is the column filled" is how a post-dated page goes public
     * early.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null && ! $this->published_at->isFuture();
    }

    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    /**
     * ⛔ Has this page's address been seen by the outside world?
     *
     * ⚠ Deliberately asks whether it has EVER been published, not whether it is
     * published now. Taking a page down does not un-crawl it, un-link it or
     * un-advertise it, so a slug that was live for an hour is still a slug
     * somebody holds a link to.
     */
    public function slugIsFrozen(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Where the storefront serves this page.
     *
     * ⚠ Home answers at `/`, never at `/home`. Serving the front page at two
     * addresses is exactly the duplicate-canonical defect that produced 59
     * "alternate page with proper canonical" exclusions on laladia.com.
     */
    public function path(): string
    {
        return $this->type === PageType::Home ? '/' : '/'.ltrim($this->slug, '/');
    }

    /**
     * Every reason this page is not fit to be published.
     *
     * ⭐ Completeness is enforced HERE, at publish, and not on every save. An
     * author composing a page over an afternoon legitimately has a half-filled
     * hero; a page in front of the public does not. So a save refuses anything
     * malformed and tolerates anything merely unfinished, and this is the gate
     * that will not let unfinished reach a visitor.
     *
     * @return array<int, string>
     */
    public function publishProblems(?Theme $theme = null): array
    {
        $problems = [];
        $validator = app(SectionPayloadValidator::class);

        if ($this->sections()->count() === 0) {
            $problems[] = 'The page has no sections, so there would be nothing on it.';
        }

        foreach ($this->sections as $section) {
            $type = $section->type();

            if ($type === null) {
                $problems[] = "Section {$section->position}: [{$section->identity()}] is not a section type this ERP knows.";

                continue;
            }

            // ⚠ A hidden section is not on the page, so an unfinished hidden
            // section must not block publishing the rest of it.
            if ($section->is_hidden) {
                continue;
            }

            foreach ($validator->validate($type, $section->payload ?? []) as $problem) {
                $problems[] = "Section {$section->position} ({$type->label}): {$problem}";
            }

            // ⛔ The guard that stops a live page going blank. A theme that can
            // neither render a section nor declare a fallback for it is saying
            // the section would show nothing, and finding that out from a
            // visitor is the whole failure mode this contract exists to prevent.
            if ($theme !== null && ! $theme->canRender($type->key, $type->version)) {
                $problems[] = "Section {$section->position} ({$type->label}): the store's theme cannot render [{$type->identity()}] and declares no fallback, so it would be blank.";
            }

            foreach ($this->mediaProblems($section, $type) as $problem) {
                $problems[] = "Section {$section->position} ({$type->label}): {$problem}";
            }
        }

        return $problems;
    }

    /**
     * What is wrong with the images this section points at.
     *
     * ⛔⛔ TWO SEPARATE REFUSALS, AND BOTH ARE MEASURED DEFECTS FROM THE LIVE
     * SHOPS RATHER THAN THEORY.
     *
     * The first is a DANGLING or FOREIGN reference. `laladia.com` carried a
     * landing page whose hero image 404'd on production for weeks, and it went
     * unnoticed because nothing ever asked whether the file behind the
     * reference existed. Here the reference is an id in a table, so it can be
     * asked, and it is asked before the page goes out rather than after. The
     * same check catches a section on one store pointing at another store's
     * picture, which is the fork-inherits-the-other-shop's-identifier defect
     * wearing a different hat.
     *
     * The second is UNDECIDED ALT TEXT. The storefront audit found six alt
     * attributes in a thousand-line template. Alt text is not a nice-to-have on
     * a shop: it is what a blind customer is sold by, and what a search engine
     * reads a photograph as. So an image may sit in the library undecided, and
     * may not reach a published page that way. ⚠ Note it asks whether somebody
     * DECIDED, not whether text is present: an empty alt is correct for a
     * decorative flourish, and demanding a sentence there would only teach
     * people to type "image".
     *
     * @return array<int, string>
     */
    private function mediaProblems(PageSection $section, SectionType $type): array
    {
        $ids = app(SectionReferences::class)->media($type, $section->payload ?? []);

        if ($ids === []) {
            return [];
        }

        $problems = [];

        $found = StoreMedia::query()
            ->where('store_id', $this->store_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $media = $found->get($id);

            if ($media === null) {
                $problems[] = "it points at an image (#{$id}) that is not in this store's library.";

                continue;
            }

            if (! $media->altTextIsDecided()) {
                $problems[] = "the image \"{$media->original_name}\" has no alt text yet. Write what it shows, or mark it decorative.";
            }
        }

        return $problems;
    }

    public function publish(): void
    {
        $this->published_at = now();
        $this->save();
    }

    public function unpublish(): void
    {
        $this->published_at = null;
        $this->save();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'title', 'type', 'seo_title', 'seo_description', 'published_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
