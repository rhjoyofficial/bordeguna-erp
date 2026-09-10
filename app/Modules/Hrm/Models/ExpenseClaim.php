<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An employee asking for money back that they spent on the company.
 *
 * ⚠⚠ THE RECEIPT IS THE POINT. A claim carries its own attachments, and the
 * screens refuse to submit one without at least one file. Joy's requirement was
 * that the office keeps the evidence, and evidence attached later is evidence
 * that often never arrives.
 */
class ExpenseClaim extends Model
{
    use Approvable, BelongsToBusiness, HasAttachments, LogsActivity;

    public const EVIDENCE_COLLECTION = 'evidence';

    public const SETTLE_PAYMENT = 'payment';

    public const SETTLE_PAYROLL = 'payroll';

    protected $fillable = [
        'business_id',
        'employee_id',
        'claim_number',
        'submitted_at',
        'spent_on',
        'amount',
        'purpose',
        'expense_category_id',
        'expense_account_id',
        'settlement_method',
        'settled_at',
        'settled_by',
        'expense_voucher_id',
        'payroll_adjustment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:4',
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['claim_number', 'spent_on', 'amount', 'settlement_method', 'settled_at', 'expense_account_id'])
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * The lines that will actually cost the business money: everything the
     * approver did not decline.
     *
     * @return Collection<int, ExpenseClaimLine>
     */
    public function payableLines(): Collection
    {
        return $this->lines->reject(fn (ExpenseClaimLine $line) => $line->isDeclined())->values();
    }

    /**
     * ⚠⚠ THE ONE PLACE THE HEADER IS DERIVED FROM ITS LINES. `amount`,
     * `spent_on`, `expense_category_id` and `expense_account_id` are kept on the
     * header because a dozen readers - the settle screen, the payroll
     * adjustment, the register, the printed voucher - ask the claim what it is
     * worth without wanting to know it has lines at all. They are DERIVED, never
     * typed, and this method is the only writer.
     *
     * ⚠ The total is the PAYABLE total, not the claimed one. A declined line is
     * still on the claim and still visible to the employee, but it is not money
     * the business owes, so it must not reach a journal or a payslip.
     *
     * ⚠ `spent_on` is the EARLIEST line. A claim titled "Dhaka market trip" is
     * dated the day the trip started, which is what somebody looking for it in a
     * list will remember. The date that decides accounting periods is the
     * journal's, not this one.
     *
     * ⚠ The category is set only when every payable line agrees on one. A
     * mixed claim has no single category and saying it does would put a wrong
     * name on the printed voucher; the lines carry the truth.
     */
    public function syncFromLines(): void
    {
        $lines = $this->payableLines();

        if ($lines->isEmpty()) {
            // Nothing payable left: keep the header readable rather than
            // blanking a date the screens sort on.
            $this->forceFill(['amount' => '0'])->save();

            return;
        }

        $categories = $lines->pluck('expense_category_id')->unique();
        $accounts = $lines->pluck('expense_account_id')->filter()->unique();

        $this->forceFill([
            'amount' => (string) $lines->sum(fn (ExpenseClaimLine $line) => (float) $line->amount),
            'spent_on' => $lines->min(fn (ExpenseClaimLine $line) => $line->spent_on?->toDateString()),
            'expense_category_id' => $categories->count() === 1 ? $categories->first() : null,
            'expense_account_id' => $accounts->count() === 1 ? $accounts->first() : null,
        ])->save();
    }

    /**
     * What to call this claim on a screen or a printed voucher.
     *
     * ⚠⚠ THE NAME IS EARNED, NOT ALWAYS DEMANDED (Joy, 10 Sep 2026). One spend
     * needs no name: asking for a claim title AND a spend note means writing the
     * same sentence twice, which is what made the previous screen tiring. So a
     * one-spend claim describes itself from the line it holds.
     *
     * ⭐ A claim with SEVERAL spends is a different animal. Whatever put them
     * together - a journey, a repair job, a delivery run, a day of errands - the
     * company cannot read four unrelated lines and work out what they were, and
     * one line of the employee's own words can. `needsAName()` is what makes the
     * screen ask; this is only the fallback for anything raised before it did.
     */
    public function displayName(): string
    {
        $typed = trim((string) $this->purpose);

        if ($typed !== '') {
            return $typed;
        }

        $lines = $this->lines;
        $first = $lines->first();
        $said = trim((string) ($first?->description ?? ''));

        if ($said === '') {
            $said = $first?->category()['name'] ?? 'Expense';
        }

        $others = $lines->count() - 1;

        return $others > 0 ? $said.' and '.$others.' more' : $said;
    }

    /**
     * ⛔ SEVERAL SPENDS AT ONCE HAVE TO SAY WHAT THEY WERE. The moment a second
     * spend joins, the claim stops being one purchase and starts being something
     * the company has to account for as a whole - and four unrelated lines do
     * not explain themselves. A single spend already describes itself and is
     * never asked.
     */
    public function needsAName(): bool
    {
        return $this->lines->count() > 1 && trim((string) $this->purpose) === '';
    }

    /**
     * Whether anything on this claim can be posted to the ledger. A claim whose
     * lines carry no category - the two raised before categories existed - can
     * only be settled the old way, through an expense voucher typed by finance.
     */
    public function hasPostableLines(): bool
    {
        return $this->payableLines()->contains(fn (ExpenseClaimLine $line) => $line->postingAccountId() !== null);
    }

    /**
     * Every receipt on the claim, whichever line it hangs off.
     *
     * ⚠ Receipts moved from the claim to its lines on 10 Sep 2026, so this
     * reads the lines. Anything still asking the claim for `attachments`
     * directly will find an empty set, which is the loud failure rather than
     * the quiet one.
     *
     * @return Collection<int, \App\Modules\Foundation\Models\Attachment>
     */
    public function receipts(): Collection
    {
        return $this->lines->flatMap(fn (ExpenseClaimLine $line) => $line->attachments)->values();
    }

    /**
     * A claim the employee is still building. Only a draft can be edited.
     */
    public function isDraft(): bool
    {
        return $this->submitted_at === null;
    }

    public function wasSentBack(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Returned;
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class, 'payroll_adjustment_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * ⚠ Approved means the approval request says so, not that money moved.
     * A claim can sit approved for a week before anyone pays it, and the two
     * states must stay separate or "approved" starts meaning "paid" to whoever
     * reads the screen.
     */
    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    /**
     * What a human should see as the state of this claim.
     */
    public function state(): string
    {
        if ($this->isSettled()) {
            return $this->settlement_method === self::SETTLE_PAYROLL ? 'Added to salary' : 'Paid';
        }

        // ⚠ A returned claim reads as a draft again to the employee, because
        // that is what it is: theirs to fix and send back. The difference is
        // that somebody has already looked at it and said why, which the screen
        // shows beside this word.
        if ($this->isDraft()) {
            return $this->wasSentBack() ? 'Sent back for correction' : 'Draft, not sent yet';
        }

        return match ($this->approvalRequest?->status) {
            ApprovalStatus::Approved => 'Approved, awaiting payment',
            ApprovalStatus::Rejected => 'Rejected',
            ApprovalStatus::Returned => 'Sent back for correction',
            default => 'Waiting for approval',
        };
    }

    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->whereNull('settled_at')
            ->whereHas('approvalRequest', fn (Builder $inner) => $inner->where('status', ApprovalStatus::Approved));
    }

    /**
     * The next claim number for this business.
     *
     * ⚠ Derived from the highest existing number rather than a count: a deleted
     * row would otherwise hand the next claim a number already used, and the
     * unique index would refuse it at the worst possible moment.
     */
    public static function nextNumber(int $businessId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->value('claim_number');

        $sequence = $last === null ? 0 : (int) substr((string) $last, strrpos((string) $last, '-') + 1);

        return 'EC-'.str_pad((string) ($sequence + 1), 5, '0', STR_PAD_LEFT);
    }
}
