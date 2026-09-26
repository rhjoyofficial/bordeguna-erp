<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Models\CreditTermsRequestLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a planned credit terms file into a document waiting for approval.
 *
 * ⚠ Re-planned at submission, never taken from the review shown minutes ago:
 * a customer may have been edited in between, and the lines must record what
 * was true when the request was made. Nothing on any customer changes here;
 * that is ApproveCreditTermsRequest's job, after the authority says yes.
 */
class SubmitCreditTermsRequest
{
    public function __construct(private readonly PlanCreditTermsRequest $planner) {}

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows, User $requestedBy, ?string $note = null, ?string $sourceFile = null): CreditTermsRequest
    {
        $plan = $this->planner->handle($rows);

        if ($plan['errors'] !== []) {
            throw ValidationException::withMessages(['file' => 'The file no longer reads cleanly: '.$plan['errors'][0]['message']]);
        }

        if ($plan['changes'] === []) {
            throw ValidationException::withMessages(['file' => 'Nothing in the file changes a limit or the days. There is nothing to send for approval.']);
        }

        $request = DB::transaction(function () use ($plan, $requestedBy, $note, $sourceFile) {
            $businessId = app(BusinessContext::class)->id();

            $request = CreditTermsRequest::create([
                'business_id' => $businessId,
                'request_number' => $this->nextNumber((int) $businessId),
                'requested_by' => $requestedBy->id,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'source_file' => $sourceFile,
                'line_count' => count($plan['changes']),
            ]);

            foreach ($plan['changes'] as $change) {
                CreditTermsRequestLine::create([
                    'credit_terms_request_id' => $request->id,
                    'customer_id' => $change['customer_id'],
                    'current_credit_limit' => $change['current_limit'],
                    'current_credit_days' => $change['current_days'],
                    'proposed_credit_limit' => $change['new_limit'],
                    'proposed_credit_days' => $change['new_days'],
                ]);
            }

            $request->submitForApproval($requestedBy);

            return $request->fresh(['lines', 'approvalRequest']);
        });

        app(NotifyApprovers::class)->handle(
            businessId: $request->business_id,
            permission: 'sales.credit.approve',
            summary: "Credit terms {$request->request_number}: {$request->line_count} customer(s) from {$requestedBy->name}",
            excludeUserId: $requestedBy->id,
        );

        return $request;
    }

    /** CT-0001 per business, locked against a second submission in the same instant. */
    private function nextNumber(int $businessId): string
    {
        $last = CreditTermsRequest::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('request_number');

        $n = $last === null ? 0 : (int) substr((string) $last, 3);

        return sprintf('CT-%04d', $n + 1);
    }
}
