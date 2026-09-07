<?php

namespace App\Modules\Commerce\Sections;

/**
 * The section catalogue, version 1.
 *
 * ⭐ TWENTY TYPES, and that number is the budget rather than a coincidence. Joy
 * shared a 120-area reference list; roughly twenty of them serve these
 * businesses this year. A twenty-first type is added when a real page needs it
 * and genuinely cannot be expressed, never because a template happened to look
 * slightly different. Every addition is a version and a theme obligation
 * forever.
 *
 * ⭐⭐ These are not invented. They are derived from what the two shops actually
 * render today: 18 storefront partials and 16 bespoke landing templates totalling
 * 7,044 lines. `story.split` alone replaces most of what the hilsa, ghee and
 * beef pickle templates hand-built.
 *
 * ⛔ Nothing here stores a price, a stock figure, a delivery charge or an
 * availability word. There is no primitive for any of them, deliberately. Those
 * are asked of the ERP at render time.
 */
final class SectionCatalogue
{
    /**
     * @return array<int, SectionType>
     */
    public static function all(): array
    {
        return [
            ...self::hero(),
            ...self::product(),
            ...self::story(),
            ...self::trust(),
            ...self::media(),
            ...self::commerce(),
        ];
    }

    /**
     * @return array<int, SectionType>
     */
    private static function hero(): array
    {
        return [
            new SectionType('hero.banner', 1, 'Hero banner', 'Hero',
                purpose: 'The home page hero, one or more slides.',
                fields: [
                    Field::listOf('slides', [
                        Field::media('image', required: true),
                        Field::text('heading', 120, required: true),
                        Field::text('subheading', 240),
                        Field::link('link'),
                        Field::choice('align', ['left', 'centre', 'right']),
                    ], min: 1, max: 8,
                        // ⚠ At least one slide, because the home page emitted one
                        // h1 per slide and ZERO when no banners were configured.
                        // A hero that can be empty is a page with no h1 at all.
                        help: 'At least one slide. The first slide carries the page heading.'),
                ]),

            new SectionType('hero.product', 1, 'Product hero', 'Hero',
                purpose: 'The top of a landing page, for one product. Price and pack sizes come from the ERP.',
                fields: [
                    Field::product('product', required: true),
                    Field::media('image', required: true),
                    Field::text('heading', 160, required: true),
                    Field::text('subheading', 300),
                    Field::text('cta_label', 40, help: 'Defaults to the theme wording if left blank.'),
                ]),

            new SectionType('announcement.strip', 1, 'Announcement strip', 'Hero',
                purpose: 'The promo strip and the coupon strip above the header.',
                fields: [
                    Field::text('message', 160, required: true),
                    Field::link('link'),
                    Field::choice('tone', ['neutral', 'offer', 'urgent']),
                    // ⚠ A coupon is referenced by CODE, and its validity is asked
                    // of the ERP at render. Nothing is ever advertised that
                    // checkout would then refuse.
                    Field::text('coupon_code', 40, help: 'Optional. Validity is checked against the ERP; an expired code shows nothing.'),
                ]),
        ];
    }

    /**
     * @return array<int, SectionType>
     */
    private static function product(): array
    {
        return [
            new SectionType('product.grid', 1, 'Product grid', 'Catalogue',
                purpose: 'Trending, a category listing, or a hand-picked rail.',
                fields: [
                    Field::text('heading', 120),
                    Field::choice('source', ['trending', 'category', 'manual'], required: true),
                    Field::category('category', help: 'Used when the source is a category.'),
                    Field::listOf('products', [Field::product('product', required: true)], max: 24,
                        help: 'Used when the source is manual.'),
                    Field::integer('limit', min: 1, max: 24),
                ]),

            new SectionType('product.categories', 1, 'Category row', 'Catalogue',
                purpose: 'The category circles on the home page.',
                fields: [
                    Field::text('heading', 120),
                    Field::listOf('categories', [Field::category('category', required: true)], min: 1, max: 16),
                ],
            ),

            new SectionType('product.combo', 1, 'Bundle', 'Catalogue',
                // ⚠ D25: a combo is a store-level bundle of finished, already
                // sellable products. Nothing is manufactured for it, it explodes
                // into component order lines and stock moves against each
                // component. ⚠ Its PRICE is not here: it is custom or the sum of
                // the components, and either way the ERP decides it at render.
                // When the priced bundle entity is built in Phase 2 this type
                // bumps to version 2 and references it directly.
                purpose: 'A bundle of finished products sold together. The price comes from the ERP, never from this section.',
                fields: [
                    Field::text('heading', 120),
                    Field::listOf('products', [Field::product('product', required: true)], min: 2, max: 8),
                ]),

            new SectionType('product.specification', 1, 'Specification', 'Catalogue',
                purpose: 'Weight, origin, shelf life, nutrition. Every value read from the ERP.',
                fields: [
                    Field::product('product', required: true),
                    Field::text('heading', 120),
                ]),

            new SectionType('product.certification', 1, 'Certifications', 'Catalogue',
                // ⛔ Never free text. A certification the ERP does not hold is not
                // displayed, because a claimed certification is a legal statement.
                purpose: 'ISO 22000, Halal, HACCP, GMP, read from the product record.',
                fields: [
                    Field::product('product', required: true),
                    Field::text('heading', 120),
                ]),
        ];
    }

    /**
     * @return array<int, SectionType>
     */
    private static function story(): array
    {
        return [
            new SectionType('story.prose', 1, 'Passage', 'Story',
                purpose: 'A headed passage of copy, optionally with one image.',
                fields: [
                    Field::text('heading', 160),
                    Field::richText('body', required: true),
                    Field::media('image'),
                    Field::choice('image_position', ['above', 'below', 'none']),
                ]),

            new SectionType('story.split', 1, 'Split panel', 'Story',
                // ⭐ The single most valuable type in the catalogue. This one
                // replaces most of what the hilsa, ghee and beef pickle templates
                // hand-built line by line.
                purpose: 'Copy on one side, an image or a film on the other.',
                fields: [
                    Field::text('heading', 160),
                    Field::richText('body', required: true),
                    Field::media('image'),
                    Field::text('video_id', 60, help: 'Optional. A film may take the place of the image.'),
                    Field::choice('media_side', ['left', 'right'], required: true),
                ]),

            new SectionType('story.steps', 1, 'Process steps', 'Story',
                purpose: 'How something is made or done, in order.',
                fields: [
                    Field::text('heading', 160),
                    Field::listOf('steps', [
                        Field::text('title', 120, required: true),
                        Field::richText('body'),
                        Field::media('image'),
                    ], min: 2, max: 12),
                ]),

            new SectionType('story.figure', 1, 'Figure', 'Story',
                purpose: 'A full-width captioned panel. The numbered picture blocks on the landing pages.',
                fields: [
                    Field::media('image', required: true),
                    Field::text('caption', 200),
                    Field::richText('notes'),
                ]),

            new SectionType('story.notes', 1, 'Short notes', 'Story',
                purpose: 'Paired short notes, for example serving suggestions.',
                fields: [
                    Field::text('heading', 160),
                    Field::listOf('notes', [
                        Field::text('title', 120, required: true),
                        Field::text('body', 400, required: true),
                    ], min: 2, max: 12),
                ]),
        ];
    }

    /**
     * @return array<int, SectionType>
     */
    private static function trust(): array
    {
        return [
            new SectionType('trust.badges', 1, 'Trust badges', 'Trust',
                purpose: 'The badge row: no preservative, lab tested, and so on.',
                fields: [
                    Field::listOf('badges', [
                        Field::media('icon'),
                        Field::text('label', 60, required: true),
                        Field::text('body', 200),
                    ], min: 1, max: 8),
                ]),

            new SectionType('trust.testimonials', 1, 'Testimonials', 'Trust',
                // ⛔⛔ Rows only, and empty renders NOTHING rather than a
                // placeholder quote. Never invented. A fabricated testimonial
                // attributed to a real person is the exact signal that gets a
                // brand discounted as a source, and this group has already
                // shipped invented authorship once.
                purpose: 'Real testimonials the shop actually holds. Empty renders nothing.',
                fields: [
                    Field::text('heading', 120),
                    Field::integer('limit', min: 1, max: 12),
                ]),

            new SectionType('trust.gallery', 1, 'Gallery', 'Trust',
                // ⛔ Real photographs of real work only, per the 6 September
                // decision. The gallery previously held six stock photos captioned
                // as the company's own work.
                purpose: 'Real photographs of the company\'s own work.',
                fields: [
                    Field::text('heading', 120),
                    Field::integer('limit', min: 1, max: 48),
                ]),
        ];
    }

    /**
     * @return array<int, SectionType>
     */
    private static function media(): array
    {
        return [
            new SectionType('media.video', 1, 'Film', 'Media',
                // ⚠ Four real defects live behind this one block: an unmuted
                // autoplay the browser refuses, a YouTube state the map did not
                // handle leaving the play button permanently dead, no
                // `playsinline` so iOS refused inline playback, and hover-only
                // controls that no phone can reach. The theme owns those, and the
                // poster is required so the block is never a black rectangle.
                purpose: 'A self-hosted or embedded film with its own poster.',
                fields: [
                    Field::text('heading', 160),
                    Field::text('video_id', 120, help: 'A hosted file reference or a video id.'),
                    Field::media('poster', required: true, help: 'Required. Without it the block is a black rectangle while the film loads.'),
                    Field::richText('body'),
                ]),

            new SectionType('faq.list', 1, 'Questions and answers', 'Media',
                // ⭐ `include_derived` is the pattern worth copying everywhere: the
                // ERP appends answers computed at render from the store's own
                // payment methods and shipping zones, so they cannot go stale.
                // It is why Bionic's FAQ says Cash on Delivery only and never
                // mentions bKash.
                purpose: 'Editorial questions, optionally followed by answers the ERP derives from this store\'s own settings.',
                fields: [
                    Field::text('heading', 160),
                    Field::listOf('questions', [
                        Field::text('question', 200, required: true),
                        Field::richText('answer', required: true),
                    ], max: 30),
                    Field::boolean('include_derived', help: 'Append delivery and payment answers computed from this store\'s settings.'),
                ]),
        ];
    }

    /**
     * @return array<int, SectionType>
     */
    private static function commerce(): array
    {
        return [
            new SectionType('commerce.checkout', 1, 'Order block', 'Commerce',
                // ⛔ Renders the ONE shared checkout engine. The main checkout and
                // the landing checkout have drifted apart four separate times
                // (phone validation, GA4 events, free delivery by weight, the
                // district requirement), and Joy agreed on 7 September to merge
                // them. ⚠ Every checkout must ask for a district: a live Bionic
                // page could not take an order at all because it did not.
                purpose: 'The order block on a landing page. Always the shared checkout engine.',
                fields: [
                    Field::product('product', required: true),
                    Field::choice('mode', ['single', 'pack_picker', 'cart'], required: true),
                    Field::text('heading', 160),
                ]),

            new SectionType('commerce.notify', 1, 'Notify me', 'Commerce',
                // ⭐ Under the made-to-order availability policy this is what keeps
                // a seasonal product's URL alive instead of 404ing every year and
                // throwing away the authority it accumulated.
                purpose: 'Out of season or awaiting stock: keep the URL and collect interest.',
                fields: [
                    Field::product('product', required: true),
                    Field::text('heading', 160),
                    Field::richText('body'),
                ]),
        ];
    }
}
