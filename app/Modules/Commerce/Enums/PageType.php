<?php

namespace App\Modules\Commerce\Enums;

/**
 * What a page IS, which decides where it is served and what may be true of it.
 *
 * ⚠ A type is not a template and not a theme. It is the page's ROLE, and the
 * storefront routes on it: `Home` answers at `/`, `Legal` at its slug, `Article`
 * under the journal. Two stores on two themes with the same page types serve the
 * same URL shapes, which is the whole reason a cutover can keep every URL.
 *
 * ⛔ There is deliberately no `Checkout` or `Cart` type. Those are application
 * screens in Phase 2, not composed content, and offering them here would invite
 * somebody to try to build a checkout out of story sections.
 */
enum PageType: string
{
    case Home = 'home';
    case Landing = 'landing';
    case Listing = 'listing';
    case Article = 'article';
    case Legal = 'legal';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::Landing => 'Landing page',
            self::Listing => 'Listing',
            self::Article => 'Article',
            self::Legal => 'Legal',
            self::Custom => 'Custom page',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::Home => 'The page served at the root of the domain. One per store.',
            self::Landing => 'A single product or campaign told at length. The page an advert points at.',
            self::Listing => 'A set of products: a category, a season, a collection.',
            self::Article => 'Editorial under a named author.',
            self::Legal => 'Terms, privacy, returns. The pages a customer is entitled to read.',
            self::Custom => 'Anything else with its own address.',
        };
    }

    /**
     * ⚠ Exactly one per store, and the storefront serves it at `/`. A second one
     * would make the front page depend on row order.
     */
    public function isSingleton(): bool
    {
        return $this === self::Home;
    }

    /**
     * @return array<int, array{value: string, label: string, purpose: string, singleton: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'purpose' => $type->purpose(),
            'singleton' => $type->isSingleton(),
        ], self::cases());
    }
}
