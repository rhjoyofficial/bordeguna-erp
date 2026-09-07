<?php

use App\Modules\Commerce\Sections\Field;
use App\Modules\Commerce\Sections\SectionCatalogue;
use App\Modules\Commerce\Sections\SectionPayloadValidator;
use App\Modules\Commerce\Sections\SectionType;
use App\Modules\Commerce\Sections\SectionTypeRegistry;

/**
 * Phase 1, slice 1.3: the section contract.
 *
 * ⭐⭐ The programme rests on this file being right. A page is stored as typed
 * meaning rather than markup, which is what lets a theme be a skin instead of
 * being the page. The two things worth guarding hardest are that no section can
 * store business truth, and that validation cannot be loosened by a theme.
 */
function registry(): SectionTypeRegistry
{
    return app(SectionTypeRegistry::class);
}

function catalogueType(string $key): SectionType
{
    return registry()->get($key, 1);
}

it('registers the whole catalogue at boot', function () {
    // ⭐ Twenty is the budget, not a coincidence. A twenty-first type is added
    // when a real page cannot be expressed, never because a template happened to
    // look slightly different.
    expect(registry()->all())->toHaveCount(20);
});

it('gives every section type a unique identity', function () {
    $identities = array_map(fn (SectionType $t) => $t->identity(), registry()->all());

    expect($identities)->toHaveCount(count(array_unique($identities)));
});

it('refuses to register the same contract twice', function () {
    $registry = new SectionTypeRegistry;
    $type = new SectionType('story.prose', 1, 'Passage', 'Story', [Field::text('heading')]);

    $registry->register($type);

    expect(fn () => $registry->register($type))->toThrow(RuntimeException::class);
});

it('⛔ never lets a section store business truth', function () {
    // ⛔⛔ THE GUARD THAT MATTERS MOST IN THIS FILE. A price, a stock figure, a
    // delivery charge or an availability word typed into content will eventually
    // disagree with the ERP, and the customer finds out before we do. There is
    // no primitive for any of them, and this test fails the day somebody adds a
    // field that smells like one.
    $forbidden = ['price', 'stock', 'quantity', 'availability', 'in_stock', 'delivery', 'charge', 'discount', 'vat', 'tax'];

    foreach (registry()->all() as $type) {
        foreach ($type->fields as $field) {
            $names = [$field->name, ...array_map(fn (Field $f) => $f->name, $field->of)];

            foreach ($names as $name) {
                foreach ($forbidden as $word) {
                    expect(str_contains($name, $word))->toBeFalse(
                        "Section [{$type->identity()}] declares field [{$name}], which stores business truth. Ask the ERP at render time instead."
                    );
                }
            }
        }
    }
});

it('offers only the newest version of a type for authoring', function () {
    $registry = new SectionTypeRegistry;
    $registry->register(new SectionType('story.split', 1, 'Split', 'Story', [Field::text('heading')]));
    $registry->register(new SectionType('story.split', 2, 'Split', 'Story', [Field::text('heading'), Field::text('kicker')]));

    // ⚠ Both stay REGISTERED, because a page holding version 1 must keep
    // rendering on the day version 2 appears. Only version 2 is offered to
    // somebody composing a new page.
    expect($registry->has('story.split', 1))->toBeTrue()
        ->and($registry->latestVersion('story.split'))->toBe(2)
        ->and($registry->forAuthoring()['Story'])->toHaveCount(1)
        ->and($registry->forAuthoring()['Story'][0]->version)->toBe(2);
});

it('explains itself rather than failing silently on an unknown type', function () {
    expect(fn () => registry()->get('story.nonexistent', 1))->toThrow(RuntimeException::class);
});

it('will not let a list field be declared without a shape', function () {
    // A list whose entries have no declared shape could never be validated, so
    // the contract refuses to exist rather than accepting anything.
    expect(fn () => Field::listOf('slides', []))->toThrow(InvalidArgumentException::class);
    expect(fn () => Field::choice('align', []))->toThrow(InvalidArgumentException::class);
});

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

it('accepts a well formed section', function () {
    $problems = (new SectionPayloadValidator)->validate(catalogueType('story.split'), [
        'heading' => 'Where the fish comes from',
        'body' => '<p>The southern rivers.</p>',
        'media_side' => 'right',
    ]);

    expect($problems)->toBe([]);
});

it('names every problem at once rather than the first one', function () {
    $problems = (new SectionPayloadValidator)->validate(catalogueType('story.split'), [
        'media_side' => 'diagonal',
    ]);

    // ⚠ An author fixing a section one error at a time is an author who gives
    // up. Same rule the payroll validation follows.
    // Two, because story.split requires body and media_side and nothing else.
    expect($problems)->toHaveCount(2)
        ->and(implode(' ', $problems))->toContain('[body] is required')
        ->and(implode(' ', $problems))->toContain('[media_side] must be one of');
});

it('rejects a field the contract does not have', function () {
    $problems = (new SectionPayloadValidator)->validate(catalogueType('story.prose'), [
        'body' => 'Copy.',
        'background_colour' => '#fff',
    ]);

    // ⚠ Silently dropping an unknown key is how a page loses content nobody
    // notices. It usually means a theme wrote a field the contract does not have.
    expect(implode(' ', $problems))->toContain("[background_colour] is not part of this section's contract");
});

it('⛔ refuses script markup inside rich text', function () {
    $problems = (new SectionPayloadValidator)->validate(catalogueType('story.prose'), [
        'body' => '<p>Fine</p><script>fetch("/steal")</script>',
    ]);

    // A content field that accepts arbitrary HTML is a cross-site scripting hole
    // with a friendly name, and this one renders on a public shop.
    expect(implode(' ', $problems))->toContain('markup that is not allowed');
});

it('insists a reference is an id and never a copy of the record', function () {
    $validator = new SectionPayloadValidator;

    expect($validator->validate(catalogueType('hero.product'), [
        'product' => 'Royal Beef Pickle',
        'image' => 12,
        'heading' => 'Royal Beef Pickle',
    ]))->toContain('[product] must reference a record by id.');

    // A section that copied the product's title would show the old title forever
    // after somebody renamed the product.
    expect($validator->passes(catalogueType('hero.product'), [
        'product' => 7,
        'image' => 12,
        'heading' => 'Royal Beef Pickle',
    ]))->toBeTrue();
});

it('checks a link is an internal path or a real URL', function () {
    $validator = new SectionPayloadValidator;
    $type = catalogueType('announcement.strip');

    expect($validator->passes($type, ['message' => 'Free delivery', 'link' => '/products']))->toBeTrue()
        ->and($validator->passes($type, ['message' => 'Free delivery', 'link' => 'https://laladia.com/products']))->toBeTrue()
        ->and($validator->validate($type, ['message' => 'Free delivery', 'link' => 'products']))
        ->toContain('[link] must be an internal path starting with / or a full URL.');
});

it('enforces how many entries a list may hold', function () {
    $validator = new SectionPayloadValidator;

    // ⚠ A hero with no slides is a page with no h1 at all, which was a real
    // defect on the live home page.
    expect($validator->validate(catalogueType('hero.banner'), ['slides' => []]))
        ->toContain('[slides] is required.');

    $problems = $validator->validate(catalogueType('story.steps'), [
        'steps' => [['title' => 'Only one']],
    ]);

    expect(implode(' ', $problems))->toContain('[steps] needs at least 2 entries');
});

it('validates inside a list entry and says which entry is wrong', function () {
    $problems = (new SectionPayloadValidator)->validate(catalogueType('hero.banner'), [
        'slides' => [
            ['image' => 4, 'heading' => 'Good'],
            ['image' => 5],
        ],
    ]);

    expect(implode(' ', $problems))->toContain('[slides.1.heading] is required.');
});

it('requires a poster on a film block', function () {
    // Without one the block is a black rectangle while the film loads, and the
    // shops have already shipped a video block whose play control was dead.
    expect((new SectionPayloadValidator)->validate(catalogueType('media.video'), [
        'video_id' => 'abc123',
    ]))->toContain('[poster] is required.');
});

it('lets the FAQ ask the ERP for its own derived answers', function () {
    // ⭐ The pattern worth copying everywhere: facts computed at render from the
    // store's real settings cannot go stale. It is why Bionic's FAQ says Cash on
    // Delivery only and never mentions bKash.
    expect(catalogueType('faq.list')->field('include_derived'))->not->toBeNull();

    expect((new SectionPayloadValidator)->passes(catalogueType('faq.list'), [
        'questions' => [['question' => 'How long does delivery take?', 'answer' => '<p>24 to 72 hours.</p>']],
        'include_derived' => true,
    ]))->toBeTrue();
});

it('describes the whole catalogue as data a screen can render', function () {
    $described = array_map(fn (SectionType $t) => $t->toArray(), SectionCatalogue::all());

    expect($described)->toHaveCount(20)
        ->and($described[0])->toHaveKeys(['key', 'version', 'label', 'group', 'fields']);
});
