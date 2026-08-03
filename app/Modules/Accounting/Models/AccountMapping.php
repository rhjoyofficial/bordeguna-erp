<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a kind of business event lands in the Chart of Accounts, on a given
 * date.
 *
 * ⚠⚠ RESOLVED BY DATE, ALWAYS. Every caller passes the date of the event being
 * posted, never `today`. Posting a July payroll in August must use July's
 * mapping, or the ledger and the reports drift apart the first time an account
 * is re-filed.
 */
class AccountMapping extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'key',
        'account_id',
        'effective_from',
        'effective_to',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The account this key pointed at on `$on`.
     *
     * ⚠ THROWS when there is no mapping. A posting that cannot be mapped must
     * stop the business event, not land in a suspense account and wait to be
     * noticed - the entire value of an accrual is that it is complete. Same
     * reasoning as AccountingPeriod::assertOpenFor.
     *
     * ⚠ `$fallbackKey` is how a specific mapping degrades to a general one:
     * `payroll.earning.house_rent` falls back to `payroll.earning.default`. It
     * is a SECOND KEY, not a second row type - so the fallback is as dated and
     * as explicit as anything else, and it is visible in the same table.
     */
    public static function resolve(string $key, Carbon $on, ?string $fallbackKey = null): Account
    {
        $mapping = static::forKeyOn($key, $on);

        if ($mapping === null && $fallbackKey !== null) {
            $mapping = static::forKeyOn($fallbackKey, $on);
        }

        if ($mapping === null) {
            throw MappingNotFound::for($fallbackKey === null ? $key : "{$key} (or {$fallbackKey})", $on);
        }

        return $mapping->account;
    }

    public static function forKeyOn(string $key, Carbon $on): ?self
    {
        $date = $on->toDateString();

        return static::query()
            ->where('key', $key)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            // ⚠ Newest first. With rows written only through assign() there can
            // be no overlap, so this decides nothing - it is here so that a row
            // inserted by hand cannot make resolution depend on insertion order.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->with('account')
            ->first();
    }

    /**
     * Points a key at an account from a date onwards.
     *
     * ⚠⚠ YOU DO NOT EDIT A MAPPING, YOU SUPERSEDE IT. The open row is closed on
     * the day before the new one starts and a new row is written, so overlapping
     * periods are impossible BY CONSTRUCTION rather than by validation. An
     * overlap would otherwise resolve to whichever row the database happened to
     * return first, which is the kind of bug that is invisible until an audit.
     *
     * ℹ Re-pointing a key at the account it already has on that date is a no-op
     * rather than a new row, so a seeder can run repeatedly without shredding
     * the history into one row per run.
     */
    public static function assign(string $key, Account $account, Carbon $from, ?string $note = null): self
    {
        $current = static::forKeyOn($key, $from);

        if ($current !== null && $current->account_id === $account->id) {
            return $current;
        }

        if ($current !== null) {
            $current->update(['effective_to' => $from->copy()->subDay()]);
        }

        return static::create([
            'key' => $key,
            'account_id' => $account->id,
            'effective_from' => $from,
            'note' => $note,
        ]);
    }
}
