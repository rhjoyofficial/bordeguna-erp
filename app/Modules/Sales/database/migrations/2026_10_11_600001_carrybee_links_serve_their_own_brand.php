<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision, Joy, 11 Oct 2026: the CarryBee "LaLaDia" account carries
 * LaLaDia-brand parcels, the CarryBee "Bionic" account Bionic-brand ones, and
 * Pathao both. Both CarryBee links in Bor de Guna are `is_default` today, which
 * left the booking form guessing.
 *
 * ⚠⚠ ONLY THE EXACT SHAPE IS TOUCHED. In a business that has exactly two
 * CarryBee links, still serving any brand, one account labelled with
 * "LaLaDia" and the other with "Bionic", each link gets the brand of that
 * business whose code is LLD / BNC (or, failing a code, whose name says
 * LaLaDia / Bionic, one brand each). Pathao and every other link stay
 * any-brand. Anything else is skipped silently: this must be harmless on a
 * database that looks nothing like production.
 *
 * ⚠ Raw queries with integer ids, cast explicitly (MySQL strict mode). Labels
 * are compared lower-cased in PHP, never with a collation-dependent LIKE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $links = DB::table('courier_account_business')
            ->join('courier_accounts', 'courier_accounts.id', '=', 'courier_account_business.courier_account_id')
            ->join('courier_providers', 'courier_providers.id', '=', 'courier_accounts.courier_provider_id')
            ->where('courier_providers.code', 'carrybee')
            ->get([
                'courier_account_business.id', 'courier_account_business.business_id',
                'courier_account_business.brand_id', 'courier_accounts.label',
            ])
            ->groupBy(fn ($link) => (int) $link->business_id);

        foreach ($links as $businessId => $pair) {
            if ($pair->count() !== 2 || $pair->contains(fn ($link) => $link->brand_id !== null)) {
                continue;
            }

            $laladia = $pair->filter(fn ($link) => self::says($link->label, 'laladia') && ! self::says($link->label, 'bionic'));
            $bionic = $pair->filter(fn ($link) => self::says($link->label, 'bionic') && ! self::says($link->label, 'laladia'));

            if ($laladia->count() !== 1 || $bionic->count() !== 1) {
                continue;
            }

            $brands = DB::table('brands')->where('business_id', (int) $businessId)->get(['id', 'code', 'name']);
            $lld = self::brand($brands, 'LLD', 'laladia');
            $bnc = self::brand($brands, 'BNC', 'bionic');

            if ($lld === null || $bnc === null || $lld === $bnc) {
                continue;
            }

            DB::table('courier_account_business')->where('id', (int) $laladia->first()->id)->update(['brand_id' => $lld, 'updated_at' => now()]);
            DB::table('courier_account_business')->where('id', (int) $bionic->first()->id)->update(['brand_id' => $bnc, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Nothing to undo that the schema migration's down() does not: the
        // column goes with it. Rolled back alone this leaves the brands set,
        // which is still correct data.
    }

    private static function says(?string $label, string $word): bool
    {
        return str_contains(mb_strtolower((string) $label), $word);
    }

    /** The brand with this code, else the one brand whose name says the word; null when unclear. */
    private static function brand($brands, string $code, string $word): ?int
    {
        $byCode = $brands->filter(fn ($b) => strtoupper((string) $b->code) === $code);

        if ($byCode->count() === 1) {
            return (int) $byCode->first()->id;
        }

        $byName = $brands->filter(fn ($b) => self::says($b->name, $word));

        return $byName->count() === 1 ? (int) $byName->first()->id : null;
    }
};
