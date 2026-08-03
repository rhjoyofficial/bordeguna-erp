<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One line of the Chart of Accounts.
 *
 * ⚠⚠ NOT business-scoped, deliberately. One shared chart, four sets of balances
 * (C-GL-1). See the migration for why, and ChartOfAccountsTest for the test that
 * keeps it that way.
 *
 * ⚠ The chart is CONFIGURATION. It holds no amounts and never will: a balance is
 * a question asked of the journal lines, exactly as stock on hand is a question
 * asked of the movement ledger.
 *
 * ℹ Activity is logged into the actor's CURRENT business, since that is what
 * BelongsToBusiness stamps on a log entry. An installation-level change
 * therefore appears in one tenant's feed rather than all four. That is a known
 * asymmetry and the better half of the trade: a NULL business_id would be
 * invisible from inside every tenant, which W4 established the hard way.
 */
class Account extends Model
{
    use LogsActivity;

    /**
     * ⚠⚠ `system_slug` IS NOT FILLABLE, on purpose. It is how the software finds
     * the account it must post to, so a request that could mass-assign it could
     * point `stock_on_hand` at Owner's Drawings and every future inventory
     * journal would follow. Same reasoning as businesses.is_default in W2; the
     * seeder sets it explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_id',
        'is_postable',
        'is_active',
        'note',
    ];

    /**
     * ⚠ Mirrors the database defaults in the model. Without this a freshly
     * created account carries null for is_postable in memory while the stored
     * row is true, so a guard reading `! $account->is_postable` refuses a
     * perfectly postable account until somebody reloads it. Found for real on
     * money_accounts.is_active in Phase 1.1.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_postable' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'parent_id', 'is_postable', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The accounts a journal line may actually name.
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true)->where('is_active', true);
    }

    /**
     * ⚠ Ordering by CODE, not by name or id. A chart of accounts has exactly one
     * conventional order and every accountant reads it that way; ordering by
     * anything else makes a familiar document unfamiliar. The code is a string,
     * so this is lexicographic - which is why the seeded codes are all the same
     * width. See ChartOfAccountsSeeder.
     */
    public function scopeInChartOrder(Builder $query): Builder
    {
        return $query->orderBy('accounts.code');
    }

    /**
     * Finds one of the few accounts the software itself has to name.
     *
     * ⚠ Returns null rather than throwing: the caller knows whether a missing
     * mapping is fatal, and Phase 3 will want to say which mapping is missing
     * rather than that "an account" was not found.
     */
    public static function bySlug(string $slug): ?self
    {
        return static::query()->where('system_slug', $slug)->first();
    }

    public function normalBalance(): NormalBalance
    {
        return $this->type->normalBalance();
    }

    /**
     * "1110 - Cash in Hand", the way it reads on a dropdown and a report.
     */
    public function label(): string
    {
        return "{$this->code} - {$this->name}";
    }

    /**
     * ⚠⚠ A child must be the same TYPE as its parent, and this is enforced
     * rather than trusted. An expense filed under a current-asset group does not
     * announce itself: the trial balance still balances, and the balance sheet
     * is quietly wrong by the amount of it. It is the kind of mistake that is
     * made once, at setup, and found a year later.
     */
    public function assertConsistentWithParent(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        $parent = self::find($this->parent_id);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'That parent account does not exist.',
            ]);
        }

        if ($parent->type !== $this->type) {
            throw ValidationException::withMessages([
                'parent_id' => "A {$this->type->label()} account cannot sit under {$parent->label()}, which is a {$parent->type->label()} account.",
            ]);
        }

        // ⚠ Walks the whole ancestry rather than only checking self-parenting.
        // A two-step cycle (A under B, B under A) passes every other check here
        // and then hangs whatever renders the tree - a page that never responds
        // rather than an error somebody can act on.
        // ⚠ Bounded, because a cycle that somehow got in behind this guard
        // (a direct database edit) would otherwise make the walk itself the
        // hang it exists to prevent.
        $ancestor = $parent;

        for ($depth = 0; $ancestor !== null && $depth < 50; $depth++) {
            if ($this->exists && $ancestor->id === $this->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'An account cannot sit under itself.',
                ]);
            }

            $ancestor = $ancestor->parent;
        }
    }

    /**
     * ⚠ Deletion is the exception, deactivation is the rule.
     *
     * ⚠⚠ EXTENDED IN SLICE 2.2: once journal_lines exists, an account with any
     * posting behind it is refused here too, permanently. This method is the one
     * place that check belongs, so that the rule cannot be half-applied by a
     * second caller. Until then there are no postings in the system at all, so
     * the guards below are the complete set.
     */
    public function assertDeletable(): void
    {
        if ($this->system_slug !== null) {
            throw ValidationException::withMessages([
                'account' => "{$this->label()} is used by the system itself and cannot be deleted. Deactivate it instead.",
            ]);
        }

        if ($this->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => "{$this->label()} has accounts under it. Move or remove those first.",
            ]);
        }
    }
}
