<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\CrmTask;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * The customer_dormant cadence: a customer whose LAST invoice is older than
 * the standing delay gets ONE follow-up task - never a second while the
 * first stays open, and never for a customer who has never bought (that is
 * a lead problem, not dormancy).
 */
class SweepDormantCustomers
{
    public function handle(): int
    {
        $created = 0;

        $cadences = DB::table('crm_cadences')
            ->where('trigger', 'customer_dormant')
            ->where('is_active', true)
            ->get();

        foreach ($cadences as $cadence) {
            $cutoff = now()->subDays((int) $cadence->delay_days)->toDateString();

            $lastInvoiceByCustomer = DB::table('sales_invoices')
                ->where('business_id', $cadence->business_id)
                // An opening invoice is an old debt, not a recent purchase.
                ->where('is_opening', false)
                ->selectRaw('customer_id, MAX(invoice_date) as last_invoice')
                ->groupBy('customer_id')
                ->havingRaw('MAX(invoice_date) < ?', [$cutoff])
                ->pluck('last_invoice', 'customer_id');

            foreach ($lastInvoiceByCustomer as $customerId => $lastInvoice) {
                $customer = Customer::withoutGlobalScopes()->find($customerId);
                if ($customer === null || ! $customer->is_active) {
                    continue;
                }

                // One open nudge at a time - the sweep never nags twice.
                $alreadyOpen = CrmTask::withoutGlobalScopes()
                    ->where('business_id', $cadence->business_id)
                    ->where('subject_type', Customer::class)
                    ->where('subject_id', $customerId)
                    ->where('status', 'open')
                    ->exists();
                if ($alreadyOpen) {
                    continue;
                }

                CrmTask::create([
                    'business_id' => $cadence->business_id,
                    'subject_type' => Customer::class,
                    'subject_id' => $customerId,
                    'title' => $cadence->task_title." (last order {$lastInvoice})",
                    'due_at' => now()->addDay(),
                    'assigned_to_user_id' => $cadence->created_by,
                    'status' => 'open',
                    'created_by' => $cadence->created_by,
                ]);
                $created++;
            }
        }

        return $created;
    }
}
