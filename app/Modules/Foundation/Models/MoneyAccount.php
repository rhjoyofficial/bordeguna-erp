<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use App\Modules\Foundation\Enums\MoneyAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A bank account, a cash box or a mobile money wallet.
 *
 * ⚠ Carries no balance on purpose. It answers "where did the money move", and
 * "how much is in it" is a ledger question that arrives with the General Ledger.
 */
class MoneyAccount extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, LogsActivity, RecyclableMaster;

    protected $fillable = [
        'business_id',
        'name',
        'type',
        // ⭐ Initial plan B3 (R60): the HR employee who holds an Employee cash
        // account. Null for every other kind. Kept as an id: Foundation
        // references no module above it (see ledgerAccountId below).
        'custodian_employee_id',
        // ⭐ Joy's answer 9 (25 Oct 2026): 'ssl_clearing' marks SSL Commerz
        // Clearing, the one gateway account a typed "Paid online (SSL)" order
        // may be marked into (SslClearing).
        'system_key',
        // Phase 2.3: which Chart of Accounts entry this rolls up into. Nullable,
        // and it stays nullable - a cash box can exist before anybody has
        // decided what its balance is called in the books.
        'account_id',
        'account_number',
        'bank_name',
        'branch',
        'routing_number',
        // A bank's sanctioned overdraft, in paisa (finance policy B14). Ignored
        // for cash and wallets: they cannot pay money they do not hold.
        'overdraft_limit_minor',
        'provider',
        'currency',
        'note',
        'is_active',
    ];

    /**
     * ⚠ Mirrors the database defaults IN THE MODEL. Without this, a freshly
     * created account has `is_active` = null in memory while the stored row is
     * true - so a guard reading `! $account->is_active` refuses a perfectly
     * active account, and only until somebody reloads it. A default that lives
     * only in the schema makes the object and the row disagree.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'currency' => 'BDT',
    ];

    protected function casts(): array
    {
        return [
            'type' => MoneyAccountType::class,
            'is_active' => 'boolean',
            'overdraft_limit_minor' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'account_id', 'account_number', 'bank_name', 'branch', 'provider', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Accounts a person pays from or receives into by hand: everything except a
     * courier's or a payment gateway's. ⚠ Every screen that offers a money
     * account for a payment or a receipt uses this, so money a courier or a
     * gateway is holding can never be picked to pay a supplier or take a counter
     * sale.
     */
    public function scopeHandled(Builder $query): Builder
    {
        return $query->whereNotIn('type', [MoneyAccountType::Courier->value, MoneyAccountType::Gateway->value]);
    }

    /**
     * Accounts the ledger can book money into: those with a ledger head.
     *
     * ⚠ For pickers. An account with no head is refused by every posting path
     * (MappingNotFound), so offering it builds a form that fails on submit - and
     * before 21 Sep 2026 the customer receipt defaulted to it whenever it sorted
     * first, then answered with a blank 500. Money transfers and courier payouts
     * already filtered this way; the receipt and refund pickers did not.
     */
    public function scopeWithLedger(Builder $query): Builder
    {
        return $query->whereNotNull('account_id');
    }

    public function isCourier(): bool
    {
        return $this->type === MoneyAccountType::Courier;
    }

    public function isGateway(): bool
    {
        return $this->type === MoneyAccountType::Gateway;
    }

    /**
     * ⭐ Joy's answer 9 (25 Oct 2026): SSL Commerz Clearing, money SSL took for
     * us and has not paid out yet. A gateway account, so nobody pays from it
     * by hand; the one difference is that a typed "Paid online (SSL)" order is
     * marked into it (RecordCustomerReceipt), and an SSL settlement empties it.
     */
    public function isSslClearing(): bool
    {
        return $this->isGateway() && $this->system_key === 'ssl_clearing';
    }

    /** ⭐ B3: company cash held by an employee; may go below zero (the company then owes him). */
    public function isEmployeeCash(): bool
    {
        return $this->type === MoneyAccountType::EmployeeCash;
    }

    /**
     * Why money cannot be moved in or out of this account by hand, or null.
     *
     * ⚠⚠ The server-side half of scopeHandled(): the pickers hide courier and
     * gateway accounts, but a form can still send one. A courier's money moves
     * only through parcel outcomes and its payout statement, a gateway's only
     * through proven payments and a Contra voucher to the bank; typed in by
     * hand, neither statement would ever reconcile.
     */
    public function handRefusal(): ?string
    {
        return match (true) {
            $this->isCourier() => "{$this->name} is a courier's account. It is settled by its own statements, not by hand.",
            $this->isGateway() => "{$this->name} holds money from online sales. It moves to the bank only by a Contra voucher.",
            default => null,
        };
    }

    /**
     * ⚠⚠ THERE IS DELIBERATELY NO `account()` RELATION HERE, even though
     * `account_id` is a column on this table.
     *
     * Foundation is the base module and references no module above it - checked,
     * and this would have been the only exception in the codebase. A `belongsTo`
     * pointing at Accounting would invert that for the convenience of one eager
     * load, and the inversion is the kind that is never noticed until something
     * has to be extracted. Accounting resolves the label from its own side.
     *
     * ⚠ Since 21 Sep 2026 every cash box, bank account and wallet has a ledger
     * head of its own (Accounting's MoneyLedgers), so the ledger answers "how
     * much is in BRAC" and not only "how much is in the bank". Courier and
     * gateway accounts still share 1145 and 1146 by design.
     */
    public function ledgerAccountId(): ?int
    {
        return $this->account_id;
    }

    /**
     * How this account reads on a screen: "BRAC Bank - Current (1501...7001)".
     */
    public function label(): string
    {
        return $this->account_number
            ? "{$this->name} ({$this->account_number})"
            : $this->name;
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['money_account_id'];
    }

    public function binLabel(): string
    {
        return 'Money account '.$this->label();
    }

    /**
     * ⭐ B24: the account's OWN ledger head (never a shared courier or gateway
     * head) goes off with it into the bin and comes back on with it, so the
     * Chart of Accounts never offers a head whose money account is gone. It
     * had no postings (whyKept), so nothing reads differently.
     */
    protected static function booted(): void
    {
        $head = function (self $account, bool $on): void {
            if ($account->account_id !== null && ! $account->isCourier() && ! $account->isGateway()) {
                DB::table('accounts')->where('id', $account->account_id)->whereNull('system_slug')->update(['is_active' => $on]);
            }
        };

        static::softDeleted(fn (self $account) => $head($account, false));
        static::restored(fn (self $account) => $head($account, true));
    }

    public function whyKept(): ?string
    {
        // ⚠ Money posts to the account's own ledger head, and a posting does
        // not always name the money account; any posting on its own head keeps
        // it. Courier and gateway accounts share a head, so only their own
        // lines count (money_account_id, found by the scan).
        if ($this->account_id !== null && ! $this->isCourier() && ! $this->isGateway()
            && DB::table('journal_lines')->where('account_id', $this->account_id)->exists()) {
            return $this->binLabel().' has postings on its ledger, so it stays. Switch it off instead.';
        }

        return null;
    }
}
