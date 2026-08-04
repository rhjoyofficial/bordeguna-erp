<?php

namespace App\Modules\Sales\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * One walk-in customer per business.
 *
 * ⚠⚠ A COUNTER SALE HAS A REAL CUSTOMER ROW, not a null. A null customer_id
 * forces every report, every receivable query and every screen to special-case
 * it, and it makes a genuine counter sale indistinguishable from a sale where
 * somebody forgot to pick the customer. One row costs nothing and removes that
 * ambiguity everywhere, permanently.
 *
 * ⚠ Idempotent per ROW, on the code. This project has twice shipped a seeder
 * that skipped its whole body on finding its first record already present, so a
 * later addition never reached an installation that had run the earlier version.
 *
 * ⚠ Walks every business, so it must run after the businesses exist - the same
 * ordering constraint every permission and mapping seeder here carries.
 */
class WalkInCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            Customer::firstOrCreate(
                ['code' => Customer::WALK_IN_CODE],
                [
                    'business_id' => $business->id,
                    'name' => 'Walk-in Customer',
                    'type' => CustomerType::WalkIn,
                    // ⚠ System, so nothing offers to delete the row that every
                    // counter sale in the business's history points at.
                    'is_system' => true,
                    'is_active' => true,
                    'note' => 'The default customer for counter sales. Created by the system.',
                ],
            );
        }

        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
