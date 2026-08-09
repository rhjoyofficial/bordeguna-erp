<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\SupplierImportPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a plan that has already been reviewed.
 *
 * ⚠⚠ REFUSES A PLAN WITH ERRORS, rather than trusting the caller to have
 * checked. There are two callers already (the command and the screen) and the
 * rule must not depend on which one is running.
 *
 * ⚠ One transaction. A half-imported supplier list is worse than none: the
 * operator would not know which rows to re-run.
 */
class ApplySupplierImport
{
    public function handle(SupplierImportPlan $plan): void
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('This plan has errors and must never be applied.');
        }

        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            // ⚠⚠ Without a business every write lands in no tenant at all, and
            // the global scope then hides the rows for ever. The catalogue
            // importer met this exact failure running cold from the console.
            throw new RuntimeException('No business is in context; refusing to import suppliers into nothing.');
        }

        DB::transaction(function () use ($plan, $businessId) {
            foreach ($plan->creates as $create) {
                Supplier::create(array_merge($create['attributes'], ['business_id' => $businessId]));
            }

            foreach ($plan->updates as $update) {
                $supplier = Supplier::findOrFail($update['id']);

                // ⚠ `code` is NOT in the attributes and cannot be. It is the
                // identity the plan matched on, and renaming it through a
                // spreadsheet would orphan every purchase order behind it.
                $supplier->fill($update['attributes'])->save();
            }
        });
    }
}
