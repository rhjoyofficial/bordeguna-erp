<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\CalendarEvent;
use App\Modules\Foundation\Models\Note;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\CarbonImmutable;

/**
 * Everything happening in a window of days, gathered from the modules that
 * already know about it.
 *
 * ⚠⚠ THE CALENDAR OWNS ALMOST NOTHING. Holidays belong to HR, follow-ups to
 * CRM, expected deliveries to Sales and Purchasing, due dates to the ledger.
 * This class READS them. The alternative - a calendar people copy things into -
 * is wrong the first time somebody changes a delivery date on the order and not
 * on the calendar, and after that nobody trusts either screen.
 *
 * ⚠⚠ EVERY SOURCE IS GATED BY THE PERMISSION THAT GUARDS ITS OWN MODULE, and
 * that is the single most important line in this file. A calendar is the
 * easiest place in an ERP to leak: gather everything, render it prettily, and a
 * warehouse clerk who may not open the invoice list now reads every customer's
 * due amount on a month grid. The gate is applied HERE, per source, not on the
 * page, because the page is not the only thing that will ever call this.
 *
 * ⚠ A source the reader may not see is ABSENT, not empty and not greyed out.
 * Telling somebody there are three things here they may not look at is its own
 * small leak.
 */
class CalendarFeed
{
    /**
     * @return list<array{date:string, type:string, label:string, title:string, detail:string|null, url:string|null, id:int|null}>
     */
    public function between(User $user, string $from, string $to): array
    {
        return array_merge(
            $this->events($user, $from, $to),
            $this->noteReminders($user, $from, $to),
            $this->holidays($from, $to),
            $this->leave($user, $from, $to),
            $this->followUps($user, $from, $to),
            $this->salesOrders($user, $from, $to),
            $this->purchaseOrders($user, $from, $to),
            $this->invoicesDue($user, $from, $to),
            $this->billsDue($user, $from, $to),
        );
    }

    /**
     * Every day an entry covers, clipped to the window being drawn.
     *
     * ⚠⚠ WITHOUT THIS, A MULTI-DAY ENTRY IS SILENTLY DROPPED. Filing an entry
     * under its START date alone means a trade fair running from 20 August to
     * 25 September is fetched by the overlapping query, given the date
     * 2026-08-20, and then discarded because September's grid has no cell for
     * that day. The query looked right, the screen showed nothing, and nothing
     * anywhere reported a problem. Found by the test that says an entry appears
     * on every day it spans.
     *
     * ⚠ An entry with no end date covers exactly one day.
     *
     * @return list<string>
     */
    private function daysCovered(string $start, ?string $end, string $from, string $to): array
    {
        $first = max($start, $from);
        $last = min($end ?? $start, $to);

        if ($first > $last) {
            return [];
        }

        $days = [];

        for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $last; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * The only rows the calendar itself owns.
     */
    private function events(User $user, string $from, string $to): array
    {
        $items = [];

        foreach (CalendarEvent::query()->visibleTo($user)->overlapping($from, $to)->with('creator:id,name')->orderBy('starts_at')->get() as $e) {
            $detail = $e->all_day
                ? ($e->location ?: $e->creator?->name)
                : $e->starts_at->format('H:i').($e->location ? ' - '.$e->location : '');

            foreach ($this->daysCovered($e->starts_at->toDateString(), $e->ends_at?->toDateString(), $from, $to) as $date) {
                $items[] = [
                    'date' => $date,
                    'type' => 'event',
                    'label' => $e->is_private ? 'Private' : 'Event',
                    'title' => $e->title,
                    'detail' => $detail,
                    'url' => null,
                    'id' => $e->id,
                ];
            }
        }

        return $items;
    }

    /**
     * Notes asking to be brought back on a day.
     *
     * ⚠⚠ THIS IS THE REASON THE NOTES BOARD IS NOT A DRAWER. A note nobody
     * rereads is worthless, and the cheapest thing that makes one resurface is
     * for it to appear, on the day it asked for, on the screen people already
     * open. Without this the notes module is exactly the feature it was once
     * advised against being.
     *
     * ⚠ Gated by `notes.view` AND by the note's own privacy, because a reminder
     * shows the note's TITLE and a private note's title is private.
     *
     * ⚠ Archived notes never remind. Archiving is how somebody says they are
     * done with it, and a reminder afterwards would make archiving useless.
     */
    private function noteReminders(User $user, string $from, string $to): array
    {
        if (! $user->can('notes.view')) {
            return [];
        }

        return Note::query()
            ->visibleTo($user)
            ->active()
            ->whereNotNull('remind_on')
            ->whereBetween('remind_on', [$from, $to])
            ->orderBy('remind_on')
            ->get()
            ->map(fn (Note $n) => [
                'date' => $n->remind_on->toDateString(),
                'type' => 'note',
                'label' => $n->is_private ? 'Private note' : 'Note',
                'title' => $n->title,
                'detail' => null,
                'url' => '/notes',
                'id' => null,
            ])
            ->all();
    }

    /**
     * ⚠ Ungated on purpose. A public holiday is not confidential, and everybody
     * needs to know the office is shut.
     */
    private function holidays(string $from, string $to): array
    {
        $calendarIds = HolidayCalendar::query()->pluck('id');

        if ($calendarIds->isEmpty()) {
            return [];
        }

        return Holiday::query()
            ->whereIn('holiday_calendar_id', $calendarIds)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => [
                'date' => $h->date->toDateString(),
                'type' => 'holiday',
                'label' => 'Holiday',
                'title' => $h->name,
                'detail' => null,
                'url' => null,
                'id' => null,
            ])
            ->all();
    }

    /**
     * ⚠⚠ APPROVED leave only, and only for somebody who may administer leave.
     * Who is off is ordinary planning information for a manager and private
     * information about a colleague to everybody else. Pending requests are
     * excluded because a request is not yet an absence, and showing it as one
     * makes people plan around leave that never happens.
     */
    private function leave(User $user, string $from, string $to): array
    {
        if (! $user->can('hrm.leave.manage')) {
            return [];
        }

        $items = [];

        $requests = LeaveRequest::query()
            ->with(['employee:id,first_name,last_name', 'leaveType:id,name'])
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved))
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get();

        foreach ($requests as $l) {
            foreach ($this->daysCovered($l->start_date->toDateString(), $l->end_date->toDateString(), $from, $to) as $date) {
                $items[] = [
                    'date' => $date,
                    'type' => 'leave',
                    'label' => 'Leave',
                    'title' => $l->employee?->fullName() ?? 'An employee',
                    'detail' => $l->leaveType?->name ?? 'Leave',
                    'url' => null,
                    'id' => null,
                ];
            }
        }

        return $items;
    }

    private function followUps(User $user, string $from, string $to): array
    {
        if (! $user->can('crm.leads.view')) {
            return [];
        }

        // ⚠⚠ Whose follow-ups, not just whether follow-ups. Until 15 Sep 2026 this
        // showed every open follow-up in the business to anybody who could open
        // Leads - the disclosure the CRM queue's own rule exists to prevent.
        return CrmTask::query()
            ->tap(fn ($q) => app(LeadVisibility::class)->restrictTasks($q, $user))
            ->where('status', TaskStatus::Open)
            ->whereBetween('due_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('due_at')
            ->get()
            ->map(fn (CrmTask $t) => [
                'date' => $t->due_at->toDateString(),
                'type' => 'follow_up',
                'label' => 'Follow-up',
                'title' => $t->title,
                'detail' => $t->due_at->format('H:i'),
                'url' => '/crm/queue',
                'id' => null,
            ])
            ->all();
    }

    /**
     * ⚠ Only orders still expecting to move. A delivered or cancelled order with
     * an expected date in the window is history, and a calendar full of history
     * is one nobody scans.
     */
    private function salesOrders(User $user, string $from, string $to): array
    {
        if (! $user->can('sales.reports.view')) {
            return [];
        }

        return SalesOrder::query()
            ->with('customer:id,name')
            ->whereNotNull('expected_date')
            ->whereBetween('expected_date', [$from, $to])
            ->whereIn('status', ['open', 'partially_delivered'])
            ->orderBy('expected_date')
            ->get()
            ->map(fn (SalesOrder $o) => [
                'date' => $o->expected_date->toDateString(),
                'type' => 'sales_order',
                'label' => 'Order out',
                'title' => $o->order_number,
                'detail' => $o->customer?->name,
                'url' => '/sales/orders/'.$o->id,
                'id' => null,
            ])
            ->all();
    }

    private function purchaseOrders(User $user, string $from, string $to): array
    {
        if (! $user->can('purchasing.reports.view')) {
            return [];
        }

        return PurchaseOrder::query()
            ->with('supplier:id,name')
            ->whereNotNull('expected_date')
            ->whereBetween('expected_date', [$from, $to])
            // CRM-013 (audit 14 Oct 2026): only goods still coming, the rule
            // salesOrders() already keeps. A received or cancelled PO is history.
            ->whereIn('status', ['open', 'partially_received'])
            ->orderBy('expected_date')
            ->get()
            ->map(fn (PurchaseOrder $o) => [
                'date' => $o->expected_date->toDateString(),
                'type' => 'purchase_order',
                'label' => 'Goods in',
                'title' => $o->po_number,
                'detail' => $o->supplier?->name,
                'url' => '/purchasing/purchase-orders/'.$o->id,
                'id' => null,
            ])
            ->all();
    }

    /**
     * ⚠⚠ The amount is deliberately NOT in the title. It is in the detail line,
     * behind the same permission as the invoice list, and a reader without that
     * permission never receives the row at all.
     */
    private function invoicesDue(User $user, string $from, string $to): array
    {
        if (! $user->can('sales.reports.view')) {
            return [];
        }

        return SalesInvoice::query()
            ->with('customer:id,name')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from, $to])
            // CRM-013 (14 Oct 2026): money still owed only. A paid invoice
            // showed as "Money in", and staff could chase a customer who paid.
            ->unsettled()
            ->orderBy('due_date')
            ->get()
            ->map(fn (SalesInvoice $i) => [
                'date' => $i->due_date->toDateString(),
                'type' => 'invoice_due',
                'label' => 'Money in',
                'title' => $i->invoice_number,
                'detail' => $i->customer?->name,
                'url' => '/sales/invoices',
                'id' => null,
            ])
            ->all();
    }

    private function billsDue(User $user, string $from, string $to): array
    {
        if (! $user->can('purchasing.reports.view')) {
            return [];
        }

        return SupplierBill::query()
            ->with('supplier:id,name')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from, $to])
            // CRM-013 (14 Oct 2026): bills still owing only (this scope also
            // leaves out reversed bills), so a paid bill is not "Money out".
            ->owingWithOutstanding()
            ->orderBy('due_date')
            ->get()
            ->map(fn (SupplierBill $b) => [
                'date' => $b->due_date->toDateString(),
                'type' => 'bill_due',
                'label' => 'Money out',
                'title' => $b->bill_number,
                'detail' => $b->supplier?->name,
                'url' => '/purchasing/bills',
                'id' => null,
            ])
            ->all();
    }
}
