<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Who gives the second approval on a large payment (finance decision B13).
 *
 * ⚠⚠ WITHOUT THIS ROW A VOUCHER SENT FOR APPROVAL WOULD REACH NOBODY. So the
 * submit refuses when it is missing (SubmitOrPostVoucher), and the Every
 * Approval Reaches Somebody guard checks this seeder exists.
 *
 * One stage: the Owner, or anyone allowed to post vouchers. The preparer is
 * refused by Voucher::refusesApprovalBy whatever their role.
 *
 * Idempotent: an existing workflow and its stages are left untouched.
 */
class VoucherWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $business->id, 'key' => 'voucher'],
                ['name' => 'Voucher above the approval limit', 'is_active' => true],
            );

            if ($workflow->stages()->exists()) {
                continue;
            }

            $workflow->stages()->createMany([
                [
                    'sequence' => 1,
                    'name' => 'Second approval',
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => ['accounting.voucher.post'],
                ],
            ]);
        }
    }
}
