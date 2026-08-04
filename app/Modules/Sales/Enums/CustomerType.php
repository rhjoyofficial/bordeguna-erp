<?php

namespace App\Modules\Sales\Enums;

/**
 * What kind of customer this is.
 *
 * ⚠⚠ Present from the FIRST migration on purpose. Joy's constraint is that a
 * customer must be able to become a distributor, a dealer or a corporate account
 * without the record being redesigned - and a `customers` table that starts with
 * only a name is exactly how a second customer table appears six months later.
 *
 * ⚠ Nothing in the sales engine BRANCHES on this. It is a classification for
 * pricing, reporting and eventually credit terms, not a switch that changes how
 * a sale behaves. The moment a `match` on this enum decides how stock leaves or
 * how a journal posts, the one-engine promise is broken and the Distributor
 * module has become a second implementation.
 *
 * ⚠ Adding a case here is safe only while that stays true - the shared-enum
 * incident on this project broke every exhaustive `match` at once.
 */
enum CustomerType: string
{
    case WalkIn = 'walk_in';
    case Retail = 'retail';
    case Dealer = 'dealer';
    case Distributor = 'distributor';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Retail => 'Retail',
            self::Dealer => 'Dealer',
            self::Distributor => 'Distributor',
            self::Corporate => 'Corporate',
        };
    }
}
