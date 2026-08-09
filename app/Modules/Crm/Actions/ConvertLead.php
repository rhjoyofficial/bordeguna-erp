<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A prospect becomes a customer.
 *
 * ⚠⚠ THE ONLY PATH THAT MAY SET `converted_customer_id` AND `converted_at`.
 * Both are unfillable on the model, so this action's forceFill is the single
 * writer. A conversion asserts a fact about the business - "this prospect became
 * that customer" - and every conversion rate, campaign ROI and funnel figure is
 * computed from it. A second path that could claim one would make all of them
 * unreliable, silently.
 *
 * ⚠ NOTHING FINANCIAL HAPPENS HERE. No journal, no stock, no invoice, no price.
 * Conversion is a CRM lifecycle statement; the first sale is a separate act.
 *
 * ⚠ THE LEAD IS NEVER DELETED AND ITS HISTORY IS NEVER COPIED. Activities and
 * attributions stay exactly where they are. The customer's story is read through
 * `leads.converted_customer_id`, which keeps "when did we first speak" and
 * "which campaign produced this" answerable exactly once.
 */
class ConvertLead
{
    /**
     * @param  array<string, mixed>  $newCustomer  Used only when $existing is null.
     *
     * @throws ValidationException
     */
    public function handle(
        Lead $lead,
        User $convertedBy,
        ?Customer $existing = null,
        array $newCustomer = [],
    ): Customer {
        $businessId = app(BusinessContext::class)->id();

        if ($lead->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'lead' => 'That lead belongs to a different business.',
            ]);
        }

        // ⚠⚠ IDEMPOTENCE IS A REFUSAL, NOT A SILENT NO-OP. Converting twice
        // would either create a second customer for one prospect or quietly
        // repoint the first, and the person clicking would believe both worked.
        if ($lead->converted_customer_id !== null) {
            throw ValidationException::withMessages([
                'lead' => 'That lead has already been converted.',
            ]);
        }

        // ⚠ A lost lead is not convertible without being reopened first. Going
        // straight from lost to converted loses the fact that it was ever lost,
        // which is exactly what a funnel report needs.
        if ($lead->status === LeadStatus::Lost) {
            throw ValidationException::withMessages([
                'lead' => 'That lead is marked lost. Reopen it before converting.',
            ]);
        }

        return DB::transaction(function () use ($lead, $existing, $newCustomer, $businessId) {
            $customer = $existing !== null
                ? $this->useExisting($existing, $businessId)
                : $this->create($newCustomer, $lead, $businessId);

            // ⚠⚠ forceFill, because both columns are unfillable by design. This
            // is the single writer, and it is deliberate that it reads oddly -
            // an ordinary `update()` here would mean the guard was decorative.
            $lead->forceFill([
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
                'status' => LeadStatus::Converted,
            ])->save();

            return $customer;
        });
    }

    private function useExisting(Customer $customer, int $businessId): Customer
    {
        if ($customer->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'customer_id' => 'That customer belongs to a different business.',
            ]);
        }

        // ⚠⚠ WALK-IN IS THE ANONYMOUS COUNTER CUSTOMER. Converting a named
        // prospect into it would attach one person's identity, campaign and call
        // history to every counter sale the business has ever made.
        if ($customer->is_system) {
            throw ValidationException::withMessages([
                'customer_id' => "{$customer->name} is a system customer and cannot be the result of a conversion.",
            ]);
        }

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => 'That customer is no longer active.',
            ]);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes, Lead $lead, int $businessId): Customer
    {
        $code = strtoupper(trim((string) ($attributes['code'] ?? '')));

        // ⚠ The code is PERMANENT and is never generated from a name. Supplier
        // codes settled this on 9 August and customers follow the same rule: a
        // machine-invented identifier is one nobody recognises out loud.
        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => 'A new customer needs a permanent code.',
            ]);
        }

        if (Customer::where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => "A customer with code {$code} already exists. Link to them instead of creating a second record.",
            ]);
        }

        $name = trim((string) ($attributes['name'] ?? $lead->name ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'A customer needs a name.',
            ]);
        }

        // ⚠⚠ A LAST-MOMENT DUPLICATE CHECK ON THE PHONE. The screen offers
        // matches before the click, but between offering and clicking somebody
        // else may have created the same customer. Without this the two paths
        // race and the business ends up with one person twice.
        $normalized = PhoneIdentity::normalize($attributes['phone'] ?? $lead->phone);

        if ($normalized !== null) {
            $clash = Customer::where('phone_normalized', $normalized)->first();

            if ($clash !== null) {
                throw ValidationException::withMessages([
                    'phone' => "{$clash->name} ({$clash->code}) already has that phone number. Link to them instead of creating a second record.",
                ]);
            }
        }

        // ⚠⚠ `type` is OMITTED when not supplied, never passed as null. The
        // Customer model carries `retail` as an $attributes default, and an
        // explicit null OVERRIDES that default and hits a NOT NULL constraint -
        // a 500 instead of a customer. Building the array and only adding what
        // was actually given is the difference between "no opinion" and "the
        // answer is nothing".
        $customer = [
            'business_id' => $businessId,
            'code' => $code,
            'name' => $name,
            'phone' => $attributes['phone'] ?? $lead->phone,
            'email' => $attributes['email'] ?? $lead->email,
            'address' => $attributes['address'] ?? $lead->address,
            // ⛔ NEVER a system customer. Only the seeder creates WALK-IN.
            'is_system' => false,
            'is_active' => true,
        ];

        foreach (['type', 'note'] as $optional) {
            if (! empty($attributes[$optional])) {
                $customer[$optional] = $attributes[$optional];
            }
        }

        return Customer::create($customer);
    }
}
