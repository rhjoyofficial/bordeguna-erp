<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Database\Seeder;

/**
 * Seeds each business's default approval workflows. The two existing consumers
 * (stock requests, purchase orders) become explicit single-stage workflows that
 * authorise by the same approve-permission they always used — so they now run
 * through the configurable engine with zero behavior change. Multi-stage
 * defaults (payroll unlock, employee change requests, …) are seeded by their
 * own module slices as those consumers land.
 *
 * Idempotent: safe to re-run; existing workflows/stages are left untouched.
 */
class ApprovalWorkflowSeeder extends Seeder
{
    private const WORKFLOWS = [
        'stock_request' => [
            'name' => 'Stock request approval',
            'stages' => [
                ['name' => 'Approval', 'roles' => [], 'permissions' => ['inventory.stock.approve']],
            ],
        ],
        'inventory_adjustment' => [
            'name' => 'Inventory adjustment approval',
            'stages' => [
                ['name' => 'Approval', 'roles' => [], 'permissions' => ['inventory.stock.approve']],
            ],
        ],
        'purchase_order' => [
            'name' => 'Purchase order approval',
            'stages' => [
                ['name' => 'Approval', 'roles' => [], 'permissions' => ['purchasing.po.approve']],
            ],
        ],
        // ⚠⚠ Missing until 29 Aug 2026, and the omission was invisible.
        // CreateSalesOrder calls submitForApproval like every other consumer,
        // but with no workflow row the request was created with workflow_id
        // null: it never carried a stage, so the approval inbox could never
        // list it and the only way to approve an order was to already be
        // looking at that order's own screen. The sibling purchase order has
        // had a workflow since the beginning, which is why nobody noticed.
        'sales_order' => [
            'name' => 'Sales order approval',
            'stages' => [
                ['name' => 'Approval', 'roles' => [], 'permissions' => ['sales.orders.approve']],
            ],
        ],
        // ⭐ Credit limits and days come as a file and take effect only on the
        // authority's yes (Joy, 26 Sep 2026, Q16).
        'credit_terms_request' => [
            'name' => 'Credit terms approval',
            'stages' => [
                ['name' => 'Approval', 'roles' => [], 'permissions' => ['sales.credit.approve']],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (Business::all() as $business) {
            foreach (self::WORKFLOWS as $key => $definition) {
                $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->firstOrCreate(
                    ['business_id' => $business->id, 'key' => $key],
                    ['name' => $definition['name'], 'is_active' => true],
                );

                if ($workflow->stages()->count() > 0) {
                    continue;
                }

                foreach ($definition['stages'] as $index => $stage) {
                    $workflow->stages()->create([
                        'sequence' => $index + 1,
                        'name' => $stage['name'],
                        'allowed_roles' => $stage['roles'],
                        'allowed_permissions' => $stage['permissions'],
                    ]);
                }
            }
        }
    }
}
