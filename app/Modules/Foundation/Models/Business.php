<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class Business extends Model
{
    use HasAttachments, HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'address',
        'phone',
        'email',
        'website',
        'tin',
        'trade_licence_no',
        'trade_licence_valid_until',
        'signatory_name',
        'signatory_title',
        'document_font',
        'ai_assistant_enabled',
        'assistant_languages',
        'ai_monthly_message_cap',
        'ai_assistant_name',
        'ai_assistant_avatar_path',
        'ai_message_char_limit',
        'currency',
        'timezone',
        'fiscal_year_start_month',
        'payroll_cycle_start_day',
        'wfh_monthly_cap',
        'slug',
        'is_active',
        // ⚠ `is_default` is deliberately absent. Which tenant a person lands in
        // is not a settings field, and leaving it unfillable means no request
        // path can ever mass-assign it. The migration and the seeder set it
        // explicitly, which works regardless of $fillable.
        //
        // ⚠⚠ `vat_enabled`, `vat_effective_from`, `vat_regime` and
        // `prices_include_tax` are ALSO deliberately absent, for a stronger
        // version of the same reason. Turning VAT on changes what every future
        // invoice charges a customer, and whether a price includes tax decides
        // what every past one MEANT. Neither may ever be set by a stray mass
        // assignment from a settings form; C-VAT-7 makes switching VAT on an
        // Owner-only, audited act with its own explicit path.
    ];

    /**
     * ⚠⚠ MIRRORS THE DATABASE DEFAULTS IN THE MODEL, and this is not decoration.
     * A column default only applies once the row is WRITTEN, so a freshly
     * created Business carries null for these in memory while the stored row is
     * true/false. Code asking `$business->prices_include_tax` immediately after
     * creating it would get null - reading as "prices exclude tax", the exact
     * opposite of Joy's C-VAT-1 decision, until somebody happened to reload.
     *
     * ⚠ Caught by this slice's own test rather than by review. The project has
     * now met this bug three times: money_accounts.is_active, LeadSource, and
     * here.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'prices_include_tax' => true,
        'vat_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trade_licence_valid_until' => 'date:Y-m-d',
            'is_default' => 'boolean',
            // ⚠ Without this the column reads back as integer 0/1, and a
            // `=== false` check against it silently never matches.
            'ai_assistant_enabled' => 'boolean',
            'assistant_languages' => 'array',
            'ai_monthly_message_cap' => 'integer',
            'ai_message_char_limit' => 'integer',
            'fiscal_year_start_month' => 'integer',
            'payroll_cycle_start_day' => 'integer',
            'prices_include_tax' => 'boolean',
            'vat_enabled' => 'boolean',
            'vat_effective_from' => 'date:Y-m-d',
        ];
    }

    /**
     * Whether VAT applies to something happening on a given date.
     *
     * ⚠⚠ THE ONE PLACE THIS QUESTION IS EVER ANSWERED. C-VAT-5: VAT starts FROM
     * A DATE and never retrospectively - the ledger is immutable and closed
     * periods are locked, so switching VAT on must not change a single invoice
     * already posted. A caller checking `vat_enabled` alone would do exactly
     * that, which is why the flag is not public knowledge and this method is.
     *
     * ⛔ RETURNS FALSE EVERYWHERE TODAY. The engine is dormant (C-VAT-9) and no
     * posting path calls this yet. It exists so that when the engine is built,
     * the date rule is already written and tested rather than invented in a
     * hurry alongside the calculation.
     */
    public function vatAppliesOn(Carbon $date): bool
    {
        if (! $this->vat_enabled || $this->vat_effective_from === null) {
            return false;
        }

        return $date->startOfDay()->greaterThanOrEqualTo($this->vat_effective_from->startOfDay());
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_user')->withTimestamps();
    }

    /**
     * The order in which a landing business is chosen when the session carries
     * none. W2: this replaced `orderBy('name')`, which let a display string
     * decide whose data somebody saw, and let a rename move every user silently.
     *
     * Total by design. The flag is the instruction, the id is the floor:
     * one row flagged wins; none flagged falls back to the lowest id; several
     * flagged resolves to the lowest flagged id. No state of this table can
     * produce a name-dependent answer.
     *
     * ⚠ Columns are qualified because this scope is also applied to
     * `$user->businesses()`, and the `business_user` pivot has its own `id`.
     * Unqualified, the ORDER BY is ambiguous across that join.
     *
     * ⚠ One caller only, on purpose: `ResolveCurrentBusiness`. Both the
     * super-admin and the member branch go through it so they cannot drift,
     * which is the same failure W1 was.
     */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('businesses.is_default')
            ->orderBy('businesses.id');
    }
}
