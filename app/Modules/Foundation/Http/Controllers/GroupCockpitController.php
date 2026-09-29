<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The group cockpit (Module 6, Slice 4): one screen where the owner sees
 * the group breathe. Everything is read from each company's own books AT
 * VIEW TIME; nothing consolidated is ever stored. Until intercompany pairs
 * exist (slice 3, waiting on Joy's answers), the totals are plain sums and
 * the screen says so plainly.
 */
class GroupCockpitController extends Controller
{
    public function index(Request $request): Response
    {
        $businessIds = $request->user()->businesses()->pluck('businesses.id');
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();

        $companies = DB::table('businesses')->whereIn('id', $businessIds)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($business) => [
                'id' => $business->id,
                'name' => $business->name,
            ] + $this->companyFacts($business->id, $monthStart, $today));

        return Inertia::render('group/cockpit', [
            'companies' => $companies,
            'totals' => [
                'cash' => round($companies->sum('cash'), 2),
                'receivables' => round($companies->sum('receivables'), 2),
                'payables' => round($companies->sum('payables'), 2),
                'month_income' => round($companies->sum('month_income'), 2),
                'month_expense' => round($companies->sum('month_expense'), 2),
            ],
            'month' => Carbon::now()->format('F Y'),
        ]);
    }

    /**
     * One company's vital signs, from its own books, raw (no scopes to
     * fight a cross-company read).
     *
     * @return array<string, float>
     */
    private function companyFacts(int $businessId, string $monthStart, string $today): array
    {
        // Cash: every money account's ledger balance, the forecast's rule.
        $cash = (float) DB::table('journal_lines')
            ->where('business_id', $businessId)
            ->whereNotNull('money_account_id')
            ->sum('amount_minor') / 100;

        // Receivables and payables: the ageing identity restated raw.
        $receivables = (float) DB::table('sales_invoices')->where('business_id', $businessId)->sum('total')
            - (float) DB::table('customer_receipt_allocations')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'customer_receipt_allocations.sales_invoice_id')
                ->where('sales_invoices.business_id', $businessId)
                ->sum('customer_receipt_allocations.amount')
            - (float) DB::table('sales_return_allocations')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_return_allocations.sales_invoice_id')
                ->where('sales_invoices.business_id', $businessId)
                ->sum('sales_return_allocations.amount')
            - (float) DB::table('party_adjustment_allocations')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'party_adjustment_allocations.sales_invoice_id')
                ->where('sales_invoices.business_id', $businessId)
                ->sum('party_adjustment_allocations.amount');

        $payables = (float) DB::table('supplier_bills')->where('business_id', $businessId)->whereNull('reversed_at')->sum('total')
            - (float) DB::table('supplier_payment_allocations')
                ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_payment_allocations.supplier_bill_id')
                ->where('supplier_bills.business_id', $businessId)
                ->sum('supplier_payment_allocations.amount')
            - (float) DB::table('supplier_credit_note_allocations')
                ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_credit_note_allocations.supplier_bill_id')
                ->where('supplier_bills.business_id', $businessId)
                ->sum('supplier_credit_note_allocations.amount')
            - (float) DB::table('party_adjustment_allocations')
                ->join('supplier_bills', 'supplier_bills.id', '=', 'party_adjustment_allocations.supplier_bill_id')
                ->where('supplier_bills.business_id', $businessId)
                ->whereNull('supplier_bills.reversed_at')
                ->sum('party_adjustment_allocations.amount');

        // This month's P&L lines: income is credit-normal (negative minor),
        // so the sign flips to read as money earned.
        $monthLines = fn (string $type) => (float) DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.business_id', $businessId)
            ->where('accounts.type', $type)
            ->whereDate('journals.journal_date', '>=', $monthStart)
            ->whereDate('journals.journal_date', '<=', $today)
            ->sum('journal_lines.amount_minor') / 100;

        return [
            'cash' => round($cash, 2),
            'receivables' => round($receivables, 2),
            'payables' => round($payables, 2),
            'month_income' => round(-$monthLines('income'), 2),
            'month_expense' => round($monthLines('expense'), 2),
        ];
    }
}
