<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Sales\Exceptions\CreditRefused;
use App\Modules\Sales\Models\CreditOverride;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐⭐ THE CREDIT LIMIT OVERRIDE ON THE SAME SCREEN (Joy, 25 Oct 2026, Q11 = A,
 * R87): "a manager's override password". Not a shared password: the Manager
 * or Owner picks THEIR OWN name and types THEIR OWN login password, which
 * re-authenticates that one person for this one decision, plus a reason.
 *
 * - The password is checked against the user's own hash and NEVER stored,
 *   logged or sent back; a wrong one is refused with a plain sentence.
 * - Rate-limited: five wrong passwords for one authoriser from one person
 *   lock that pair for a minute, like the login screen.
 * - The authoriser must belong to this business, be active and hold
 *   `sales.credit.override` here (Owner and Manager, migration 280100).
 * - What is kept: one immutable credit_overrides row (who allowed it, who was
 *   at the screen, when, the reason, the figures) and a line in the record's
 *   History (activity log).
 *
 * The three screens (order, counter on account, invoice) call `authorise()`
 * only when the sale was refused, so a person who never goes over a limit is
 * never asked for anything.
 */
final class CreditOverrideAuthority
{
    public const PERMISSION = 'sales.credit.override';

    private const MAX_ATTEMPTS = 5;

    /**
     * Who may be chosen in the Override box: active members of this business
     * holding the override permission here.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function options(int $businessId): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($businessId);

        try {
            return User::permission(self::PERMISSION)
                ->where('is_active', true)
                ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $businessId))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => (string) $user->name])
                ->values()
                ->all();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Whether the request carries an override at all (any of the three boxes).
     *
     * @param  array<string, mixed>  $input
     */
    public static function asked(array $input): bool
    {
        return filled($input['override_by'] ?? null) || filled($input['override_password'] ?? null) || filled($input['override_reason'] ?? null);
    }

    /**
     * The person who allows it, re-authenticated, and their reason.
     *
     * @param  array<string, mixed>  $input  override_by (user id), override_password, override_reason
     * @return array{0: User, 1: string}
     *
     * @throws ValidationException
     */
    public static function authorise(array $input, int $businessId, User $atScreen): array
    {
        $reason = trim((string) ($input['override_reason'] ?? ''));
        $errors = [];

        if (! filled($input['override_by'] ?? null)) {
            $errors['override_by'] = 'Choose the Manager or Owner who allows it.';
        }

        if (! filled($input['override_password'] ?? null)) {
            $errors['override_password'] = 'They type their own password.';
        }

        if (mb_strlen($reason) < 5) {
            $errors['override_reason'] = 'Say why, in at least 5 letters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $key = 'credit-override:'.$atScreen->id.':'.(int) $input['override_by'];

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'override_password' => 'Too many wrong passwords. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $by = User::query()
            ->whereKey((int) $input['override_by'])
            ->where('is_active', true)
            ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $businessId))
            ->first();

        if ($by === null || ! Hash::check((string) $input['override_password'], (string) $by->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['override_password' => 'That password is not right for the person chosen.']);
        }

        // ⚠ Asked in THIS business's team (roles are per business).
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($businessId);
        $by->unsetRelation('roles')->unsetRelation('permissions');

        try {
            $allowed = $by->can(self::PERMISSION);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $by->unsetRelation('roles')->unsetRelation('permissions');
        }

        if (! $allowed) {
            throw ValidationException::withMessages(['override_by' => "{$by->name} may not override a credit limit. Ask a Manager or the Owner."]);
        }

        RateLimiter::clear($key);

        return [$by, $reason];
    }

    /**
     * The row and the History line. Called inside the caller's transaction,
     * so a sale that fails afterwards takes its override with it.
     */
    public static function record(
        CreditRefused $refusal,
        Customer $customer,
        User $by,
        User $atScreen,
        string $reason,
        Model $subject,
        ?SalesOrder $order = null,
        ?SalesInvoice $invoice = null,
    ): CreditOverride {
        $override = CreditOverride::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'sales_order_id' => $order?->id,
            'sales_invoice_id' => $invoice?->id,
            'rule' => $refusal->rule,
            'method' => CreditOverride::METHOD_PASSWORD,
            'credit_limit' => $refusal->creditLimit?->toTaka(),
            'owed_before' => $refusal->owedBefore->toTaka(),
            'amount' => $refusal->amount->toTaka(),
            'owed_after' => $refusal->owedAfter->toTaka(),
            'reason' => $reason,
            'overridden_by' => $by->id,
            'entered_by' => $atScreen->id,
        ]);

        activity()
            ->performedOn($subject)
            ->causedBy($by)
            ->withProperties([
                'credit_override_id' => $override->id,
                'rule' => $refusal->rule,
                'owed_after' => $refusal->owedAfter->toTaka(),
                'credit_limit' => $refusal->creditLimit?->toTaka(),
                'reason' => $reason,
                'entered_by' => $atScreen->name,
            ])
            ->log("Credit limit overridden by {$by->name}: {$reason}");

        return $override;
    }

    /**
     * The overrides on one order or invoice, for its page.
     *
     * @param  'sales_order_id'|'sales_invoice_id'  $column
     * @return list<array{id: int, at: ?string, by: string, entered_by: ?string, reason: string, method: string}>
     */
    public static function rowsFor(string $column, int $id): array
    {
        return CreditOverride::query()
            ->where($column, $id)
            ->with(['overriddenBy:id,name', 'enteredBy:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (CreditOverride $row) => [
                'id' => (int) $row->id,
                'at' => $row->created_at?->timezone('Asia/Dhaka')->toDateString(),
                'by' => (string) $row->overriddenBy?->name,
                'entered_by' => $row->enteredBy?->name,
                'reason' => (string) $row->reason,
                'method' => (string) $row->method,
            ])
            ->values()
            ->all();
    }

    /**
     * A screen override already given for this order still covers it: the
     * approval then needs no second reason, unless the order now leaves MORE
     * unpaid than was allowed.
     */
    public static function coversOrder(SalesOrder $order, CreditRefused $refusal): bool
    {
        return CreditOverride::query()
            ->where('sales_order_id', $order->id)
            ->where('method', CreditOverride::METHOD_PASSWORD)
            ->where('amount', '>=', $refusal->amount->toTaka())
            ->exists();
    }
}
