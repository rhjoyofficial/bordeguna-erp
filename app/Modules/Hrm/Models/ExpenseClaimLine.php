<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Hrm\Support\ExpenseCategories;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One spend inside a claim: a rickshaw, a courier, a box of tape.
 *
 * ⚠⚠ THE LINE CARRIES ITS OWN RECEIPT. Four spends on one market trip are four
 * separate pieces of paper, and a single collection on the claim could not say
 * which proof belonged to which spend - the approver would be handed four
 * photographs and left to guess.
 *
 * ⚠ A DECLINED LINE IS NOT DELETED. It stays on the claim with the approver's
 * reason attached, because the employee asked for that money and is entitled to
 * see what happened to the request. It simply does not post and is not paid.
 */
class ExpenseClaimLine extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    public const EVIDENCE_COLLECTION = 'evidence';

    protected $fillable = [
        'business_id',
        'expense_claim_id',
        'spent_on',
        'expense_category_id',
        'expense_account_id',
        'amount',
        'description',
        'declined_at',
        'declined_reason',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'declined_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['spent_on', 'amount', 'expense_category_id', 'declined_at', 'declined_reason'])
            ->dontSubmitEmptyLogs();
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * The category as humans read it, or null on a legacy line that predates
     * categories entirely.
     */
    public function category(): ?array
    {
        return ExpenseCategories::describe($this->expense_category_id);
    }

    /**
     * The account this line will post to: the one already stamped on it, or
     * the one its category currently maps to. Null means nothing here can post,
     * which is exactly the legacy claim's situation.
     */
    public function postingAccountId(): ?int
    {
        return $this->expense_account_id ?? ($this->category()['account_id'] ?? null);
    }
}
