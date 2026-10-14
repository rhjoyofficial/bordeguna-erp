<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer service tickets, phase 1 (Module 4, Slice 4).
 *
 * Phone and counter complaints, honestly scoped. The resolve-by is stamped
 * at creation from the priority's SLA hours - stamped, so changing the rule
 * later never rewrites history. A breach is COMPUTED (resolved late, or
 * open past the stamp), never typed. Resolving requires saying what was
 * actually done.
 */
class TicketController extends Controller
{
    /** Priority -> hours to resolve. Phase 1 keeps these fixed and visible. */
    public const SLA_HOURS = ['high' => 24, 'normal' => 72, 'low' => 168];

    public function index(): Response
    {
        $businessId = app(BusinessContext::class)->id();

        $tickets = DB::table('crm_tickets')
            ->where('business_id', $businessId)
            // Open-and-overdue first, then open, then history.
            ->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")
            ->orderBy('resolve_by')
            ->paginate(25);

        $customerNames = Customer::query()
            ->whereIn('id', collect($tickets->items())->pluck('customer_id')->filter())
            ->pluck('name', 'id');
        // CRM-010 (audit 14 Oct 2026): replies and every name on the page in
        // two queries, not one per ticket plus one per reply.
        $replies = DB::table('crm_ticket_replies')
            ->whereIn('ticket_id', collect($tickets->items())->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('ticket_id');
        $userNames = DB::table('users')
            ->whereIn('id', collect($tickets->items())->pluck('assigned_to_user_id')
                ->merge($replies->flatten(1)->pluck('created_by'))
                ->filter()->unique()->values())
            ->pluck('name', 'id');

        $tickets->through(fn ($ticket) => [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'party' => $ticket->customer_id
                ? ($customerNames[$ticket->customer_id] ?? '?')
                : trim(($ticket->caller_name ?? '').' '.($ticket->caller_phone ?? '')),
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'resolve_by' => $ticket->resolve_by,
            'resolved_at' => $ticket->resolved_at,
            'assigned_to' => $userNames[$ticket->assigned_to_user_id] ?? null,
            // ⚠ Computed, never stored: late is late whether or not anyone
            // updated a flag. Resolved late stays breached forever.
            'breached' => $ticket->resolved_at !== null
                ? Carbon::parse($ticket->resolved_at)->greaterThan(Carbon::parse($ticket->resolve_by))
                : Carbon::now()->greaterThan(Carbon::parse($ticket->resolve_by)),
            'replies' => ($replies[$ticket->id] ?? collect())
                ->map(fn ($reply) => [
                    'id' => $reply->id,
                    'body' => $reply->body,
                    'is_internal' => (bool) $reply->is_internal,
                    'by' => $userNames[$reply->created_by] ?? null,
                    'at' => $reply->created_at,
                ])->values(),
            'resolution' => $ticket->resolution,
        ]);

        return Inertia::render('crm/tickets', [
            'tickets' => $tickets,
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
            'users' => DB::table('users')
                ->join('business_user', 'users.id', '=', 'business_user.user_id')
                ->where('business_user.business_id', $businessId)
                ->orderBy('users.name')->get(['users.id', 'users.name']),
            'slaHours' => self::SLA_HOURS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            // A complaint belongs to somebody: a customer on file, or at
            // least a name to call back.
            'customer_id' => ['nullable', 'integer', 'required_without:caller_name', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'caller_name' => ['nullable', 'string', 'max:120', 'required_without:customer_id'],
            'caller_phone' => ['nullable', 'string', 'max:30'],
            'category' => ['required', Rule::in(['complaint', 'question', 'delivery', 'quality', 'other'])],
            'priority' => ['required', Rule::in(array_keys(self::SLA_HOURS))],
            // CRM-009 (audit 14 Oct 2026): a member of this business, never
            // any login in the ERP (another business's staff could never open it).
            'assigned_to_user_id' => ['nullable', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $businessId)],
        ]);

        DB::table('crm_tickets')->insert($data + [
            'business_id' => $businessId,
            'ticket_number' => $this->nextNumber($businessId),
            'status' => 'open',
            'resolve_by' => now()->addHours(self::SLA_HOURS[$data['priority']]),
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Ticket opened; the clock is running.');
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $row = $this->ticket($ticket);

        $data = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        DB::table('crm_ticket_replies')->insert([
            'business_id' => $row->business_id,
            'ticket_id' => $row->id,
            'body' => $data['body'],
            'is_internal' => (bool) ($data['is_internal'] ?? false),
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($row->status === 'open') {
            DB::table('crm_tickets')->where('id', $row->id)->update(['status' => 'in_progress', 'updated_at' => now()]);
        }

        return back()->with('success', 'Reply kept.');
    }

    public function resolve(Request $request, int $ticket): RedirectResponse
    {
        $row = $this->ticket($ticket);

        // "Resolved" without saying what was done is a closed complaint,
        // not a solved one.
        $data = $request->validate([
            'resolution' => ['required', 'string'],
        ]);

        DB::table('crm_tickets')->where('id', $row->id)->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution' => $data['resolution'],
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Ticket resolved.');
    }

    public function assign(Request $request, int $ticket): RedirectResponse
    {
        $row = $this->ticket($ticket);

        $data = $request->validate([
            // CRM-009 (14 Oct 2026): as on opening, this business's members only.
            'assigned_to_user_id' => ['required', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $row->business_id)],
        ]);

        DB::table('crm_tickets')->where('id', $row->id)->update([
            'assigned_to_user_id' => $data['assigned_to_user_id'], 'updated_at' => now(),
        ]);

        return back();
    }

    private function ticket(int $id): object
    {
        $row = DB::table('crm_tickets')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    /** Public so the HQ API opens tickets with the same numbering as the screen. */
    public function nextNumber(int $businessId): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'TK-'.FiscalYear::containing(Carbon::now(), $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(DB::table('crm_tickets'), 'ticket_number', $prefix);
    }
}
