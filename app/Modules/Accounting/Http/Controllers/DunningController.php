<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Mail\PaymentReminderMail;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingDocument;
use App\Modules\Accounting\Support\AgeingRow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment reminders (Module 2, Slice 5).
 *
 * ⚠⚠ NOTHING SENDS ITSELF. A reminder goes to a CUSTOMER, outside the
 * building, so every one is a human's click, logged with who sent it and
 * what it covered. The screen reads the SAME Ageing engine as the
 * receivables report - one idea of who owes what, never two.
 *
 * Viewing needs accounting.view; sending needs sales.receipt.record - the
 * collections permission, because chasing money is the receipt-taker's job.
 */
class DunningController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        $lastReminders = DB::table('payment_reminders')
            ->selectRaw('customer_id, MAX(sent_at) as last_sent')
            ->groupBy('customer_id')
            ->pluck('last_sent', 'customer_id');

        $emails = Customer::query()->pluck('email', 'id');

        $customers = $this->overdueRows()
            ->map(function (AgeingRow $row) use ($lastReminders, $emails) {
                $overdue = $row->documents->filter(fn (AgeingDocument $document) => $document->daysOverdue > 0);

                return [
                    'id' => $row->partyId,
                    'name' => $row->partyName,
                    'email' => $emails[$row->partyId] ?? null,
                    'overdue_total' => round($overdue->sum(fn (AgeingDocument $document) => $document->outstanding->toTaka()), 2),
                    'oldest_days' => (int) $overdue->max(fn (AgeingDocument $document) => $document->daysOverdue),
                    'last_reminded' => $lastReminders[$row->partyId] ?? null,
                    'invoices' => $overdue->map(fn (AgeingDocument $document) => [
                        'number' => $document->number,
                        'date' => $document->documentDate->toDateString(),
                        'due' => $document->dueDate?->toDateString(),
                        'days_overdue' => $document->daysOverdue,
                        'outstanding' => $document->outstanding->toTaka(),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('oldest_days')
            ->values();

        return Inertia::render('accounting/dunning', [
            'customers' => $customers,
            'can_send' => $request->user()->can('sales.receipt.record'),
        ]);
    }

    public function remind(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        $row = $this->overdueRows()->first(fn (AgeingRow $candidate) => $candidate->partyId === $customer->id);
        $overdue = $row?->documents->filter(fn (AgeingDocument $document) => $document->daysOverdue > 0) ?? collect();

        if ($overdue->isEmpty()) {
            return back()->withErrors(['customer' => $customer->name.' has nothing overdue - no reminder to send.']);
        }

        if (! $customer->email) {
            return back()->withErrors(['email' => $customer->name.' has no email address on record. Add one on the customer page first.']);
        }

        $invoices = $overdue->map(fn (AgeingDocument $document) => [
            'number' => $document->number,
            'date' => $document->documentDate->toDateString(),
            'due' => $document->dueDate?->toDateString(),
            'days_overdue' => $document->daysOverdue,
            'outstanding' => $document->outstanding->toTaka(),
        ])->values()->all();

        $total = round(array_sum(array_column($invoices, 'outstanding')), 2);

        $businessName = Business::withoutGlobalScopes()
            ->find(app(BusinessContext::class)->id())?->name ?? 'Bor de Guna';

        Mail::to($customer->email)->queue(new PaymentReminderMail($customer, $invoices, $total, $businessName));

        DB::table('payment_reminders')->insert([
            'business_id' => app(BusinessContext::class)->id(),
            'customer_id' => $customer->id,
            'sent_to' => $customer->email,
            'invoice_count' => count($invoices),
            'total_amount' => $total,
            'sent_by' => $request->user()->id,
            'sent_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Reminder sent to '.$customer->email.' covering '.count($invoices).' invoice(s).');
    }

    /** @return \Illuminate\Support\Collection<int, AgeingRow> */
    private function overdueRows()
    {
        return Ageing::receivables(Carbon::today())->rows
            ->filter(fn (AgeingRow $row) => $row->documents
                ->contains(fn (AgeingDocument $document) => $document->daysOverdue > 0));
    }
}
