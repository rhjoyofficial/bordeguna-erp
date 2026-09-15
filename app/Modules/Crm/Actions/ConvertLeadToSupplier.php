<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\IdentityDocuments;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A supplier lead becomes a supplier (CRM lead slice 2e, plan D12).
 *
 * ⚠⚠ THE ONLY PATH THAT MAY SET `converted_supplier_id`, the mirror of
 * ConvertLead for customers: the column is unfillable, this forceFill is the
 * single writer, and every rule a customer conversion keeps is kept here too -
 * refused twice, refused while lost, the lead and its history never touched.
 *
 * ⚠ Supplier leads ONLY, and a supplier lead can never become a customer
 * (ConvertLead refuses it). A mustard trader in the customer list, or a shop in
 * the supplier list, would each be one wrong record every report reads.
 *
 * ⭐ Answer 13: suppliers are often informal, so only a code and a name are
 * required, and no document is ever demanded. The code is typed, never made.
 *
 * ⚠ NOTHING FINANCIAL HAPPENS HERE. No bill, no payable, no bank detail: those
 * are Purchasing's, entered on the supplier screen by whoever manages suppliers.
 */
class ConvertLeadToSupplier
{
    /**
     * @param  array<string, mixed>  $newSupplier  Used only when $existing is null.
     * @param  array<string, list<UploadedFile>>  $documents  trade licence and NID scans (plan D11)
     *
     * @throws ValidationException
     */
    public function handle(
        Lead $lead,
        User $convertedBy,
        ?Supplier $existing = null,
        array $newSupplier = [],
        array $documents = [],
    ): Supplier {
        $businessId = app(BusinessContext::class)->id();

        if ($lead->business_id !== $businessId) {
            throw ValidationException::withMessages(['lead' => 'That lead belongs to a different business.']);
        }

        if ($lead->lead_type !== LeadType::Supplier) {
            throw ValidationException::withMessages(['lead' => 'Only a supplier lead becomes a supplier.']);
        }

        if ($lead->converted_supplier_id !== null || $lead->converted_customer_id !== null) {
            throw ValidationException::withMessages(['lead' => 'That lead has already been converted.']);
        }

        if ($lead->status === LeadStatus::Lost) {
            throw ValidationException::withMessages(['lead' => 'That lead is marked lost. Reopen it before converting.']);
        }

        return DB::transaction(function () use ($lead, $existing, $newSupplier, $businessId, $documents, $convertedBy) {
            $supplier = $existing !== null
                ? $this->useExisting($existing, $businessId)
                : $this->create($newSupplier, $lead, $businessId);

            IdentityDocuments::store($supplier, $documents, $convertedBy);

            // ⚠⚠ forceFill: the column is unfillable by design, as for customers.
            $lead->forceFill([
                'converted_supplier_id' => $supplier->id,
                'converted_at' => now(),
                'status' => LeadStatus::Converted,
            ])->save();

            return $supplier;
        });
    }

    private function useExisting(Supplier $supplier, int $businessId): Supplier
    {
        if ($supplier->business_id !== $businessId) {
            throw ValidationException::withMessages(['supplier_id' => 'That supplier belongs to a different business.']);
        }

        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'That supplier is no longer active.']);
        }

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes, Lead $lead, int $businessId): Supplier
    {
        $code = trim((string) ($attributes['code'] ?? ''));

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'A new supplier needs a permanent code.']);
        }

        // Scoped by the tenant scope: two businesses may both have an SMT.
        if (Supplier::where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => "A supplier with code {$code} already exists. Link to them instead of creating a second record.",
            ]);
        }

        $name = trim((string) ($attributes['name'] ?? $lead->name ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A supplier needs a name.']);
        }

        $supplier = [
            'business_id' => $businessId,
            'code' => $code,
            'name' => $name,
            // ⭐ The proprietor and the place carry across, as for a customer.
            'contact_name' => $attributes['contact_name'] ?? $lead->contact_name,
            'phone' => $attributes['phone'] ?? $lead->phone,
            'email' => $attributes['email'] ?? $lead->email,
            'address' => $attributes['address'] ?? $lead->fullAddress(),
            'is_active' => true,
        ];

        foreach (['payment_terms', 'bin', 'tin', 'notes'] as $optional) {
            if (filled($attributes[$optional] ?? null)) {
                $supplier[$optional] = $attributes[$optional];
            }
        }

        return Supplier::create($supplier);
    }
}
