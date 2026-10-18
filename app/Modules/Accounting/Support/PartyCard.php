<?php

namespace App\Modules\Accounting\Support;

use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;

/**
 * ⭐ The "Also our customer / Also our supplier" card (decision D-F, 18 Oct
 * 2026), the same props for the supplier page and the customer page so the
 * two can never describe one firm differently.
 *
 *   linked     the other side, or null
 *   position   "We owe them X · They owe us Y · Net Z" (PartyPosition), only
 *              for a reader of both sides
 *   options    what may be linked, only for a person who may link
 */
final class PartyCard
{
    /** @return array<string, mixed> */
    public static function forSupplier(Supplier $supplier, ?User $user): array
    {
        $customer = Customer::query()->where('supplier_id', $supplier->id)->first();

        return self::card($supplier, $customer, $user, fn () => Customer::query()->listable($user)
            ->whereNull('supplier_id')->where('type', '<>', CustomerType::WalkIn->value)->where('type', '<>', CustomerType::Staff->value)
            ->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'label' => $c->phone ? "{$c->name} ({$c->phone})" : $c->name])->values()->all());
    }

    /** @return array<string, mixed> */
    public static function forCustomer(Customer $customer, ?User $user): array
    {
        $supplier = $customer->supplier_id === null ? null : Supplier::query()->find($customer->supplier_id);
        $taken = Customer::query()->whereNotNull('supplier_id')->pluck('supplier_id');

        return self::card($supplier, $supplier === null ? null : $customer, $user, fn () => Supplier::query()
            ->whereNotIn('id', $taken)->orderBy('name')->get(['id', 'name', 'code'])
            ->map(fn (Supplier $s) => ['id' => $s->id, 'label' => $s->code ? "{$s->name} ({$s->code})" : $s->name])->values()->all(),
            // ⚠ A staff purchase account or the walk-in row is never a trading partner.
            eligible: ! $customer->isStaff() && ! $customer->isWalkIn());
    }

    /** @return array<string, mixed> */
    private static function card(?Supplier $supplier, ?Customer $customer, ?User $user, callable $options, bool $eligible = true): array
    {
        $canView = (bool) $user?->can('purchasing.reports.view') && (bool) $user?->can('sales.reports.view');
        $canLink = (bool) $user?->can('purchasing.suppliers.manage') && (bool) $user?->can('sales.customers.manage');
        $linked = $supplier !== null && $customer !== null;

        return [
            'linked' => $linked ? [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ] : null,
            'position' => $linked && $canView ? PartyPosition::of($supplier, $customer)->toArray() : null,
            'can_view' => $canView,
            'can_link' => $canLink,
            'can_raise' => (bool) $user?->can('accounting.set_off.manage'),
            'options' => $canLink && ! $linked && $eligible ? $options() : [],
            'eligible' => $eligible,
        ];
    }
}
