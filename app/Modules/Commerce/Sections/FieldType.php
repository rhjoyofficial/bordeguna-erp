<?php

namespace App\Modules\Commerce\Sections;

/**
 * The complete set of things a section field may hold.
 *
 * ⛔⛔ THE ABSENCE IS THE ENFORCEMENT. There is no `price`, no `stock`, no
 * `availability` and no `delivery_charge` primitive here, and there never will
 * be. A section must not store business truth: every one of those is asked of
 * the ERP at render time, for this store, and for this customer's district where
 * it matters.
 *
 * The reason is measured rather than theoretical. On 6 September the shops' FAQ
 * was rebuilt so four of its answers derive at render time from the store's own
 * shipping zones and payment methods. That is why Bionic's FAQ automatically
 * says Cash on Delivery only and never mentions bKash, and why it cannot go
 * stale. A typed answer would have been wrong the day the payment methods
 * changed, and nobody would have noticed.
 *
 * ⚠ `Reference` types store an ID, never a copy. A section that copied a product
 * title would show the old title forever after somebody renamed the product.
 */
enum FieldType: string
{
    /** Plain text, no markup. Always carries a max length. */
    case Text = 'text';

    /**
     * A constrained subset: bold, italic, links, lists, h2 and h3.
     * ⛔ No raw HTML, no script, no inline styles. A content field that accepts
     * arbitrary HTML is a cross-site scripting hole with a friendly name.
     */
    case RichText = 'richtext';

    /** A reference to a Media row. ⚠ Never a URL typed by hand. */
    case Media = 'media';

    /** A reference to an ERP product or variant. */
    case ProductRef = 'product_ref';

    /** A reference to an ERP category. */
    case CategoryRef = 'category_ref';

    /** An internal route reference or an external URL, validated. */
    case Link = 'link';

    /** One of a declared set: an alignment, a layout hint. */
    case Enumeration = 'enum';

    case Boolean = 'bool';

    case Integer = 'int';

    case Decimal = 'decimal';

    /**
     * A repeatable group of fields, with declared minimum and maximum
     * cardinality. Slides, steps, questions and answers.
     */
    case ListOf = 'list';

    public function isReference(): bool
    {
        return in_array($this, [self::Media, self::ProductRef, self::CategoryRef], true);
    }
}
