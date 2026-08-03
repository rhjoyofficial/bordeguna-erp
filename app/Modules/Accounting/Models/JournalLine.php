<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Exceptions\ImmutableJournalException;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One side of one journal.
 *
 * ⚠ `amount_minor` is signed whole paisa: positive debits, negative credits.
 * Debit and credit are presentation, and `amount()` hands back the value object
 * so no caller has to remember that.
 */
class JournalLine extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw ImmutableJournalException::forUpdate();
        });

        static::deleting(function (): never {
            throw ImmutableJournalException::forDelete();
        });
    }

    /**
     * ⚠ See Journal::update. An empty `$fillable` makes `update()` a silent
     * no-op that reports success, so the refusal has to be explicit.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ImmutableJournalException::forUpdate();
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The supplier owed, the employee paid, the customer who owes. What turns a
     * single Accounts Payable balance into a subsidiary ledger somebody can act
     * on.
     */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }

    public function isDebit(): bool
    {
        return $this->amount_minor > 0;
    }
}
