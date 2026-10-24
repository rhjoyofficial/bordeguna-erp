<?php

namespace App\Modules\Sales\Actions\Sellers;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Support\RetailSellers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B21 (Joy's Q3 = A, 24 Oct 2026): make an HR employee a
 * retail seller by giving him his own "seller stock", a warehouse linked to
 * him (`seller_employee_id`). Named "Seller stock - Rakib", code
 * SELLER-<employee code>. Never the default warehouse.
 *
 * ⚠ Once only: asking again returns the same stock (switched back on if it
 * had been switched off). The link is never moved to another employee: the
 * stock's history is his.
 */
class OpenSellerStock
{
    public function handle(Employee $employee): Warehouse
    {
        return DB::transaction(function () use ($employee) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $existing = RetailSellers::stockOf($employee);

            if ($existing !== null) {
                if (! $existing->is_active) {
                    $existing->update(['is_active' => true]);
                }

                return $existing;
            }

            $code = 'SELLER-'.($employee->employee_code ?: $employee->id);

            if (Warehouse::query()->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['employee_id' => "A warehouse with the code {$code} already exists. Rename it first."]);
            }

            return Warehouse::create([
                'business_id' => app(BusinessContext::class)->id(),
                'name' => 'Seller stock - '.$employee->fullName(),
                'code' => $code,
                'is_default' => false,
                'is_active' => true,
                'seller_employee_id' => $employee->id,
            ]);
        });
    }
}
