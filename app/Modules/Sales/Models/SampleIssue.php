<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A sample given away (Joy, 25 Oct 2026): "the sample product we give many
 * people as to taste or gift purpose or marketing, etc. so we take no money,
 * but delivery charge also we bear".
 *
 * NOT a sale: no invoice, no revenue, no receivable. Once approved (at or
 * below the finance policy's second-person limit it approves itself)
 * PostSampleIssue takes the goods out at their batch cost, Dr Samples and
 * Marketing / Cr Stock on Hand, and the delivery charge we bear, Dr Sample
 * Delivery / Cr the money account it was paid from or Payable to Couriers.
 * Reversed through the corrections engine, kept and marked (Reversible),
 * never edited or deleted.
 *
 * The recipient is a customer, a lead, a phone book contact or an employee;
 * a new name and phone becomes a phone book contact first.
 */
class SampleIssue extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity, Reversible;

    public const WAITING = 'waiting';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const PURPOSES = [
        'tasting' => 'Tasting',
        'gift' => 'Gift',
        'marketing' => 'Marketing',
        'other' => 'Other',
    ];

    public const METHODS = [
        'hand' => 'By hand',
        'courier' => 'Courier',
        'rider' => 'Instant / rider',
    ];

    /** The kinds of recipient, by the word the screens send. */
    public const RECIPIENTS = [
        'customer' => Customer::class,
        'lead' => Lead::class,
        'contact' => Contact::class,
        'employee' => Employee::class,
    ];

    protected $fillable = [
        'business_id',
        'number',
        'given_on',
        'recipient_type',
        'recipient_id',
        'recipient_name',
        'recipient_phone',
        'purpose',
        'purpose_note',
        'warehouse_id',
        'delivery_method',
        'delivery_charge',
        'delivery_money_account_id',
        'courier_money_account_id',
        'delivery_district',
        'delivery_zone',
        'feedback_on',
        'crm_task_id',
        'status',
        'cost_total_paisa',
        'journal_id',
        'posted_at',
        'posted_by',
        'note',
        'given_by',
    ];

    /** ⚠ The database defaults repeated, so a fresh instance reads the same as the stored row. */
    protected $attributes = ['status' => self::WAITING, 'delivery_method' => 'hand', 'delivery_charge' => 0, 'cost_total_paisa' => 0];

    protected function casts(): array
    {
        return [
            'given_on' => 'date:Y-m-d',
            'feedback_on' => 'date:Y-m-d',
            'delivery_charge' => 'decimal:2',
            'cost_total_paisa' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'given_on', 'recipient_name', 'purpose', 'delivery_method', 'delivery_charge', 'cost_total_paisa'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SampleIssueLine::class)->orderBy('sort')->orderBy('id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function deliveryMoneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'delivery_money_account_id');
    }

    public function courierMoneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'courier_money_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function crmTask(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class);
    }

    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    public function deliveryMoney(): Money
    {
        return Money::ofTaka((string) $this->delivery_charge);
    }

    public function costMoney(): Money
    {
        return Money::ofMinor((int) $this->cost_total_paisa);
    }

    /** The morph type stored for a recipient kind ("customer" => its morph class). */
    public static function morphFor(string $kind): ?string
    {
        $class = self::RECIPIENTS[$kind] ?? null;

        return $class === null ? null : (new $class)->getMorphClass();
    }

    /** "customer", "lead", "contact", "employee", or null for none. */
    public function recipientKind(): ?string
    {
        foreach (array_keys(self::RECIPIENTS) as $kind) {
            if ($this->recipient_type === self::morphFor($kind)) {
                return $kind;
            }
        }

        return null;
    }

    /** The recipient's own page. */
    public function recipientUrl(): ?string
    {
        return match ($this->recipientKind()) {
            'customer' => "/sales/customers/{$this->recipient_id}",
            'lead' => '/crm/leads?search='.urlencode((string) ($this->recipient_phone ?? $this->recipient_name)),
            'contact' => '/crm/contacts?search='.urlencode((string) ($this->recipient_phone ?? $this->recipient_name)),
            'employee' => "/hrm/employees/{$this->recipient_id}",
            default => null,
        };
    }
}
