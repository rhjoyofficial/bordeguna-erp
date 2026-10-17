<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\ApproveCreditTermsRequest;
use App\Modules\Sales\Actions\PlanCreditTermsRequest;
use App\Modules\Sales\Actions\SubmitCreditTermsRequest;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Models\CreditTermsRequestLine;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\CreditTermsCsv;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Credit terms by file, applied after approval (Joy, 26 Sep 2026, Q16).
 *
 * Download the customers with their terms, fill the two `new_` columns, upload,
 * read the review, submit. Nothing changes until somebody with
 * `sales.credit.approve` approves the request, from here or from the approvals
 * inbox; then every line lands in one transaction.
 *
 * ⚠ Preparing needs `sales.customers.manage` (the person who deals with the
 * customer list), approving needs `sales.credit.approve` (the authority: the
 * Owner by default). The two are separate keys so the same person is not
 * both by accident.
 */
class CreditTermsController extends Controller
{
    private const SESSION_KEY = 'credit_terms_import.rows';

    public function index(Request $request): Response
    {
        $requests = CreditTermsRequest::query()
            ->with(['requestedBy:id,name', 'approvalRequest', 'appliedBy:id,name'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CreditTermsRequest $r) => $this->row($r));

        return Inertia::render('sales/credit-terms/index', [
            'requests' => $requests,
            'creditCustomers' => Customer::query()->whereIn('type', PlanCreditTermsRequest::CREDIT_TYPES)->whereNotNull('code')->count(),
            'mayPrepare' => $request->user()->can('sales.customers.manage'),
            'mayApprove' => $request->user()->can('sales.credit.approve'),
            'descriptions' => CreditTermsCsv::DESCRIPTIONS,
        ]);
    }

    /** The customers who may carry terms, with what they have now, ready to fill. */
    public function template(): StreamedResponse
    {
        $customers = Customer::query()->listable()
            ->whereIn('type', PlanCreditTermsRequest::CREDIT_TYPES)
            ->whereNotNull('code')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->streamDownload(
            fn () => print CreditTermsCsv::template($customers),
            'credit-terms-'.now()->format('Ymd').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** Step one: read the file against the customers as they are now, change nothing. */
    public function review(Request $request, PlanCreditTermsRequest $planner): RedirectResponse
    {
        $this->authorize('sales.customers.manage');

        $request->validate(['file' => ['required', 'file', 'max:4096', 'mimes:csv,txt']]);

        try {
            $handle = fopen($request->file('file')->getRealPath(), 'r');
            $rows = CreditTermsCsv::readHandle($handle);
            fclose($handle);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'That file has a valid header and no rows.']);
        }

        $plan = $planner->handle($rows);

        // The ROWS are kept, not the plan: submission re-plans against the
        // customers as they are then (the customer import's rule).
        // CRM-014 follow-up (audit 14 Oct 2026): kept WITH the business it
        // was reviewed in. Applying after a business switch used to write the
        // file into a list whose diff nobody had read.
        $request->session()->put(self::SESSION_KEY, ['business_id' => app(BusinessContext::class)->id(), 'rows' => $rows, 'file' => $request->file('file')->getClientOriginalName()]);

        return back()->with('creditTermsPlan', [
            'rowCount' => count($rows),
            'changes' => array_map(fn (array $c) => [
                'line' => $c['line'], 'code' => $c['code'], 'name' => $c['name'],
                'limit' => $c['limit_changes'] ? $this->limit($c['current_limit']).' -> '.$this->limit($c['new_limit']) : null,
                'days' => $c['days_changes'] ? $this->days($c['current_days']).' -> '.$this->days($c['new_days']) : null,
            ], $plan['changes']),
            'unchanged' => count($plan['unchanged']),
            'errors' => array_map(fn (array $e) => "Line {$e['line']}".($e['code'] !== '' ? " ({$e['code']})" : '').': '.$e['message'], $plan['errors']),
        ]);
    }

    /** Step two: make the request. Still changes no customer. */
    public function submit(Request $request, SubmitCreditTermsRequest $submitter): RedirectResponse
    {
        $this->authorize('sales.customers.manage');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $kept = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($kept) || ($kept['rows'] ?? []) === []) {
            throw ValidationException::withMessages(['file' => 'There is nothing waiting to send. Upload the file again.']);
        }

        // CRM-014 follow-up (14 Oct 2026): only in the business it was reviewed in.
        if (($kept['business_id'] ?? null) !== app(BusinessContext::class)->id()) {
            throw ValidationException::withMessages([
                'file' => 'That file was reviewed in another business. Upload it again here to see what it would change.',
            ]);
        }

        $created = $submitter->handle($kept['rows'], $request->user(), $data['note'] ?? null, $kept['file'] ?? null);

        return redirect()->route('sales.credit-terms.show', $created)
            ->with('success', "Credit terms {$created->request_number} sent for approval: {$created->line_count} customer(s). Nothing changes until it is approved.");
    }

    public function show(Request $request, CreditTermsRequest $creditTerms): Response
    {
        $creditTerms->load(['lines.customer:id,code,name,type,credit_limit,credit_days', 'requestedBy:id,name', 'approvalRequest.decisions', 'appliedBy:id,name']);

        return Inertia::render('sales/credit-terms/show', [
            'request' => $this->row($creditTerms) + [
                'note' => $creditTerms->note,
                'source_file' => $creditTerms->source_file,
                'review_note' => $creditTerms->approvalRequest?->review_note,
                'lines' => $creditTerms->lines->map(fn (CreditTermsRequestLine $line) => [
                    'id' => $line->id,
                    'customer_id' => $line->customer_id,
                    'code' => $line->customer?->code,
                    'name' => $line->customer?->name,
                    'type' => $line->customer?->type?->label(),
                    'current_limit' => $this->limit($line->current_credit_limit),
                    'proposed_limit' => $this->limit($line->proposed_credit_limit),
                    'limit_changes' => $line->changesLimit(),
                    'current_days' => $this->days($line->current_credit_days),
                    'proposed_days' => $this->days($line->proposed_credit_days),
                    'days_changes' => $line->changesDays(),
                    // What the customer has RIGHT NOW, which the approver is about to overwrite.
                    'now_limit' => $this->limit($line->customer?->credit_limit),
                    'now_days' => $this->days($line->customer?->credit_days),
                    'drifted' => $line->customer !== null && (
                        $this->limit($line->customer->credit_limit) !== $this->limit($line->current_credit_limit)
                        || (int) ($line->customer->credit_days ?? -1) !== (int) ($line->current_credit_days ?? -1)
                    ),
                ])->values(),
            ],
            'mayApprove' => $request->user()->can('sales.credit.approve'),
        ]);
    }

    public function approve(Request $request, CreditTermsRequest $creditTerms, ApproveCreditTermsRequest $action): RedirectResponse
    {
        $this->authorize('sales.credit.approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $applied = $action->handle($creditTerms, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $applied->isApplied()
            ? "Credit terms {$applied->request_number} approved and applied to {$applied->line_count} customer(s)."
            : 'Approved. Sent to the next stage.');
    }

    public function reject(Request $request, CreditTermsRequest $creditTerms, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('sales.credit.approve');
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        try {
            $action->handle($creditTerms, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Credit terms {$creditTerms->request_number} rejected. No customer changed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CreditTermsRequest $r): array
    {
        return [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'requested_by' => $r->requestedBy?->name,
            'requested_at' => $r->created_at?->toDateTimeString(),
            'line_count' => $r->line_count,
            'status' => $r->status()->value,
            'status_label' => $r->statusLabel(),
            'applied_at' => $r->applied_at?->toDateTimeString(),
            'applied_by' => $r->appliedBy?->name,
            'url' => route('sales.credit-terms.show', $r),
        ];
    }

    private function limit(mixed $value): string
    {
        return $value === null ? 'no limit recorded' : number_format((float) $value, 2);
    }

    private function days(mixed $value): string
    {
        return $value === null ? 'default (10)' : ((int) $value).' days';
    }
}
