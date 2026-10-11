<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Facades\DB;

/**
 * Which courier a courier money account is, and how that courier writes its
 * statement.
 *
 * ⭐ THE COURIER COMES FROM SETTINGS > COURIERS FIRST. A money account linked to
 * a courier account (`courier_account_business.money_account_id`) is that
 * account's provider, whatever its free-text `provider` says. Only an unlinked
 * money account falls back to the typed text, normalised, so "Pathao Courier",
 * "pathao " and "PATHAO" are one courier and can never split a parcel's claim
 * key in two (audit gap: free-text provider).
 */
final class CourierStatementFormats
{
    /** Spelling a person might type => the provider code. */
    private const ALIASES = [
        'pathao' => 'pathao',
        'carrybee' => 'carrybee',
        'steadfast' => 'steadfast',
        'redx' => 'redx',
        'paperfly' => 'paperfly',
    ];

    public static function for(MoneyAccount $courier): ?CourierStatementFormat
    {
        return self::forProvider(self::provider($courier));
    }

    public static function forProvider(string $provider): ?CourierStatementFormat
    {
        return match ($provider) {
            'pathao' => new PathaoStatementFormat,
            'carrybee' => new CarryBeeStatementFormat,
            default => null,
        };
    }

    /**
     * The provider code of a courier money account, from its courier settings
     * link when it has one, else from its typed provider.
     */
    public static function provider(MoneyAccount $courier): string
    {
        $linked = self::linkedProviders([$courier->id]);

        return $linked[$courier->id] ?? self::normalise((string) $courier->provider);
    }

    /**
     * "Pathao Courier" => pathao, "Carry Bee" => carrybee. Anything unknown
     * becomes its letters and digits, lower case.
     */
    public static function normalise(string $provider): string
    {
        $squashed = preg_replace('/[^a-z0-9]/', '', strtolower($provider)) ?? '';

        foreach (self::ALIASES as $needle => $code) {
            if (str_contains($squashed, $needle)) {
                return $code;
            }
        }

        return $squashed;
    }

    /**
     * Every courier money account of every business that is this provider.
     * The table is small and not business-scoped here on purpose: it is how a
     * parcel of the other brand on a shared account is recognised.
     *
     * @return list<int>
     */
    public static function moneyAccountIdsFor(string $provider): array
    {
        $accounts = MoneyAccount::withoutGlobalScopes()
            ->where('type', MoneyAccountType::Courier->value)
            ->get(['id', 'provider']);

        $linked = self::linkedProviders($accounts->pluck('id')->all());

        return $accounts
            ->filter(fn (MoneyAccount $account) => ($linked[$account->id] ?? self::normalise((string) $account->provider)) === $provider)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $moneyAccountIds
     * @return array<int, string> money account id => provider code
     */
    private static function linkedProviders(array $moneyAccountIds): array
    {
        if ($moneyAccountIds === []) {
            return [];
        }

        // ⚠ A raw query on purpose: the link is business-scoped by its model,
        // and the other brand's links are exactly what has to be read here.
        return DB::table('courier_account_business')
            ->join('courier_accounts', 'courier_accounts.id', '=', 'courier_account_business.courier_account_id')
            ->join('courier_providers', 'courier_providers.id', '=', 'courier_accounts.courier_provider_id')
            ->whereIn('courier_account_business.money_account_id', $moneyAccountIds)
            ->pluck('courier_providers.code', 'courier_account_business.money_account_id')
            ->mapWithKeys(fn ($code, $id) => [(int) $id => (string) $code])
            ->all();
    }
}
