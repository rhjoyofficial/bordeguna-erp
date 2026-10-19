<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The cash-flow FORECAST (Module 2, Slice 6; spec §24) - forward-looking,
 * built ONLY from documents that already exist. The statement (CashFlow)
 * explains where money went; this projects where it is about to go:
 *
 *   in:  unpaid sales invoices, by due date (overdue counts as week one -
 *        money late is money expected NOW, not never)
 *   out: unpaid supplier bills by due date, standing costs by due date, and
 *        the last real payroll as a clearly-labelled ESTIMATE on the 1st
 *
 * ⚠ Nothing is invented: a week with no documents forecasts zero movement.
 * That honesty is the feature - a forecast that smooths or guesses teaches
 * people to ignore it.
 */
class CashFlowForecast
{
    /**
     * @return array{opening_cash: float, weeks: list<array<string, mixed>>}
     */
    public static function build(int $weeks = 8): array
    {
        $today = Carbon::today();

        // ⚠⚠ Every query below is RAW, and the business scope is an Eloquent
        // scope, so none of them was filtered: the forecast of one business added
        // up the cash, invoices, bills and payroll of all four (found 21 Sep 2026).
        $businessId = app(BusinessContext::class)->id();
        $own = fn ($query, string $table) => $query->where("{$table}.business_id", $businessId);

        // Opening cash: the cash, bank and MFS heads, right now. ⚠ Not courier or
        // gateway money (1145/1146): that is a receivable until it is paid out.
        $openingCash = MoneyLedgers::cashMinor((int) $businessId) / 100;

        // ── Money in: unpaid invoices by due date. Outstanding = total minus
        // receipts and credit allocations - the ageing identity, restated.
        $receiptAllocations = DB::table('customer_receipt_allocations')
            ->selectRaw('sales_invoice_id, SUM(amount) as paid')
            ->groupBy('sales_invoice_id')
            ->pluck('paid', 'sales_invoice_id');

        $creditAllocations = DB::table('sales_return_allocations')
            ->selectRaw('sales_invoice_id, SUM(amount) as credited')
            ->groupBy('sales_invoice_id')
            ->pluck('credited', 'sales_invoice_id');

        $invoiceAdjustments = DB::table('party_adjustment_allocations')->whereNotNull('sales_invoice_id')
            ->selectRaw('sales_invoice_id, SUM(amount) as adjusted')
            ->groupBy('sales_invoice_id')
            ->pluck('adjusted', 'sales_invoice_id');

        $invoicesIn = $own(DB::table('sales_invoices'), 'sales_invoices')
            ->whereNull('sales_invoices.reversed_at')
            ->get(['id', 'due_date', 'invoice_date', 'total'])
            ->map(function ($invoice) use ($receiptAllocations, $creditAllocations, $invoiceAdjustments) {
                $outstanding = (float) $invoice->total
                    - (float) ($receiptAllocations[$invoice->id] ?? 0)
                    - (float) ($creditAllocations[$invoice->id] ?? 0)
                    - (float) ($invoiceAdjustments[$invoice->id] ?? 0);

                return [
                    'due' => $invoice->due_date ?? $invoice->invoice_date,
                    'amount' => round($outstanding, 2),
                ];
            })
            ->filter(fn ($row) => $row['amount'] > 0.009);

        // ── Money out: unpaid supplier bills the same way.
        $billPayments = DB::table('supplier_payment_allocations')
            ->selectRaw('supplier_bill_id, SUM(amount) as paid')
            ->groupBy('supplier_bill_id')
            ->pluck('paid', 'supplier_bill_id');

        $billCredits = DB::table('supplier_credit_note_allocations')
            ->selectRaw('supplier_bill_id, SUM(amount) as credited')
            ->groupBy('supplier_bill_id')
            ->pluck('credited', 'supplier_bill_id');

        $billAdjustments = DB::table('party_adjustment_allocations')->whereNotNull('supplier_bill_id')
            ->selectRaw('supplier_bill_id, SUM(amount) as adjusted')
            ->groupBy('supplier_bill_id')
            ->pluck('adjusted', 'supplier_bill_id');

        $billsOut = $own(DB::table('supplier_bills'), 'supplier_bills')
            ->whereNull('reversed_at')
            ->get(['id', 'due_date', 'bill_date', 'total'])
            ->map(function ($bill) use ($billPayments, $billCredits, $billAdjustments) {
                $outstanding = (float) $bill->total
                    - (float) ($billPayments[$bill->id] ?? 0)
                    - (float) ($billCredits[$bill->id] ?? 0)
                    - (float) ($billAdjustments[$bill->id] ?? 0);

                return [
                    'due' => $bill->due_date ?? $bill->bill_date,
                    'amount' => round($outstanding, 2),
                ];
            })
            ->filter(fn ($row) => $row['amount'] > 0.009);

        // ── Standing costs still due.
        $recurringOut = $own(DB::table('recurring_expense_occurrences'), 'recurring_expense_occurrences')
            ->where('status', 'due')
            ->get(['due_on', 'expected_amount'])
            ->map(fn ($occurrence) => [
                'due' => $occurrence->due_on,
                'amount' => (float) $occurrence->expected_amount,
            ]);

        // ── Payroll: the last run that reached approval or beyond, placed on
        // the 1st of each coming month. An ESTIMATE and labelled as one.
        $payrollEstimate = (float) ($own(DB::table('payroll_runs'), 'payroll_runs')
            ->whereIn('status', ['approved', 'disbursed', 'locked'])
            ->orderByDesc('id')
            ->value('total_net') ?? 0);

        // ── The weekly buckets.
        $result = [];
        $running = $openingCash;

        for ($index = 0; $index < $weeks; $index++) {
            $weekStart = $today->copy()->addWeeks($index)->startOfDay();
            $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

            // Week one swallows everything already overdue.
            $inWindow = fn ($due) => $index === 0
                ? Carbon::parse($due)->lessThanOrEqualTo($weekEnd)
                : Carbon::parse($due)->between($weekStart, $weekEnd);

            $in = round($invoicesIn->filter(fn ($row) => $inWindow($row['due']))->sum('amount'), 2);
            $overdueIn = $index === 0
                ? round($invoicesIn->filter(fn ($row) => Carbon::parse($row['due'])->lessThan($today))->sum('amount'), 2)
                : 0.0;
            $outBills = round($billsOut->filter(fn ($row) => $inWindow($row['due']))->sum('amount'), 2);
            $outRecurring = round($recurringOut->filter(fn ($row) => $inWindow($row['due']))->sum('amount'), 2);

            $outPayroll = 0.0;
            $firstOfMonth = $weekStart->copy()->startOfMonth();
            // A "1st" inside this week that is still ahead of today carries
            // the payroll estimate.
            foreach ([$firstOfMonth, $firstOfMonth->copy()->addMonthNoOverflow()] as $first) {
                if ($first->betweenIncluded($weekStart, $weekEnd) && $first->greaterThan($today)) {
                    $outPayroll = $payrollEstimate;
                }
            }

            $net = round($in - $outBills - $outRecurring - $outPayroll, 2);
            $running = round($running + $net, 2);

            $result[] = [
                'week_start' => $weekStart->toDateString(),
                'in_invoices' => $in,
                'overdue_in' => $overdueIn,
                'out_bills' => $outBills,
                'out_recurring' => $outRecurring,
                'out_payroll_estimate' => $outPayroll,
                'net' => $net,
                'running' => $running,
            ];
        }

        return [
            'opening_cash' => round($openingCash, 2),
            'weeks' => $result,
        ];
    }
}
