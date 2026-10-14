<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\CounterpartyKind;
use App\Modules\Accounting\Support\CounterpartyIdentity;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A person or firm the business lends money to or borrows it from: a bank, an
 * NBFI, a company such as Tycoon Hi-Tech Park, a relative of the owner (loans
 * plan v2, phase 1, 14 Oct 2026).
 *
 * Every line on a loan head (1175 group, 1270, 2116, 2126 to 2128, 2210, 2220,
 * 2230) names one, the same contract 1170 keeps with the employee
 * (Support\PartyLedgers): the head is the ledger, the counterparty on each line
 * is the sub-ledger, and the parties add up to the head or the reconciliation
 * screen says so.
 *
 * ⚠⚠ TENANCY: GROUP-WIDE, NOT PER BUSINESS (decided 14 Oct 2026). Customers and
 * suppliers are per business (BelongsToBusiness); a counterparty is not, and
 * that difference is deliberate:
 *
 *  - Bor de Guna, LaLaDia, Bionic Garden and Azmion are brands of ONE legal
 *    company. A bank or a lender deals with that company, so it is one legal
 *    identity for the whole group, like an employee (also central) and like
 *    the chart of accounts.
 *  - The BALANCE is still per business: a journal line carries its business,
 *    so each business's statement, list and reconciliation read only their own
 *    lines. One row, four separate ledgers, no copies that drift apart.
 *  - Money between two of our own businesses is never a loan. It moves through
 *    Finance & Accounts > Inter-business transfers (1195/2190), and a
 *    counterparty named like one of our businesses is refused outright
 *    (CounterpartyIdentity::ownBusinessNamed), so the two routes cannot mix.
 *
 * ⚠ ONE IDENTITY (ChatGPT review, point 4). A customer, supplier or employee
 * who also lends or borrows is LINKED here (customer_id, supplier_id,
 * employee_id), not typed in a second time. Each link is unique.
 *
 * ⚠ Deactivated, never deleted: a loan repaid in 2026 must still be nameable in
 * 2030. A counterparty with any balance cannot be deactivated
 * (Actions\SaveCounterparty::deactivate).
 */
class Counterparty extends Model
{
    use LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'legal_name',
        'kind',
        'related_party',
        'relationship',
        'tin',
        'bin',
        'registration_no',
        'phone',
        'email',
        'address',
        'bank_details',
        'notes',
        'customer_id',
        'supplier_id',
        'employee_id',
        'is_active',
    ];

    /**
     * ⚠ Mirrors the database defaults in memory (the Account model's lesson): a
     * freshly created row would otherwise read `is_active` as null until it is
     * reloaded, and a guard reading it would refuse a perfectly good party.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'company',
        'related_party' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => CounterpartyKind::class,
            'related_party' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The matching forms, rewritten from what was typed on every save, so
        // they can never fall behind the columns they are made from.
        static::saving(function (self $counterparty) {
            $counterparty->name_key = CounterpartyIdentity::nameKey($counterparty->legal_name);
            $counterparty->phone_key = CounterpartyIdentity::phoneKey($counterparty->phone);
            $counterparty->tin_key = CounterpartyIdentity::tinKey($counterparty->tin);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * ⚠ Across businesses: a counterparty is group-wide and may be linked to
     * another business's customer. The link is shown, never the list.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withoutGlobalScopes();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** How it reads on a picker, a ledger line or a statement. */
    public function label(): string
    {
        return $this->legal_name;
    }
}
