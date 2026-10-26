<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroupBranch;
use App\Modules\Sales\Support\CustomerImportPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a plan that has already been reviewed.
 *
 * ⚠⚠ REFUSES A PLAN WITH ERRORS rather than trusting the caller to have
 * checked, and refuses to write into no tenant at all. Both rules belong here
 * and not in the screen, because the screen is not the only caller a system
 * like this ends up with.
 *
 * ⚠ One transaction. A half-imported customer list is worse than none: nobody
 * would know which rows to run again.
 */
class ApplyCustomerImport
{
    public function handle(CustomerImportPlan $plan): void
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('This plan has errors and must never be applied.');
        }

        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            throw new RuntimeException('No business is in context; refusing to import customers into nothing.');
        }

        DB::transaction(function () use ($plan, $businessId) {
            foreach ($plan->creates as $create) {
                Customer::create(array_merge($this->withBranch($create['attributes'], $businessId), ['business_id' => $businessId]));
            }

            foreach ($plan->updates as $update) {
                $customer = Customer::findOrFail($update['id']);

                // ⚠ `code` is NOT among the attributes and cannot be. It is the
                // identity the plan matched on, and renaming it through a
                // spreadsheet would orphan every invoice behind it.
                $customer->fill($this->withBranch($update['attributes'], $businessId))->save();
            }
        });
    }

    /**
     * ⭐ 26 Oct 2026: a branch the plan found new is added here, once, inside
     * the import's transaction; every later row naming it finds it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withBranch(array $attributes, int $businessId): array
    {
        $branch = $attributes['price_group_branch_id'] ?? null;

        if (is_array($branch)) {
            $existing = PriceGroupBranch::query()
                ->where('price_group_id', $branch['price_group_id'])
                ->get()
                ->first(fn (PriceGroupBranch $b) => mb_strtolower($b->name) === mb_strtolower($branch['name']));

            $attributes['price_group_branch_id'] = ($existing ?? PriceGroupBranch::create([
                'business_id' => $businessId,
                'price_group_id' => $branch['price_group_id'],
                'name' => $branch['name'],
                'is_active' => true,
            ]))->id;
        }

        return $attributes;
    }
}
