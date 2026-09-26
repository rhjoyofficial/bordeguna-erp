<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Models\CreditTermsRequestLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The authority's yes: every line of the request lands on its customer in one
 * transaction, or none does.
 *
 * ⚠⚠ THE APPROVAL AND THE CHANGE ARE ONE TRANSACTION, the loan rule. Recorded
 * apart, a refusal on line 7 would leave an approved request whose first six
 * customers changed and whose last four did not, with no screen to finish it.
 *
 * ⚠ A customer edited by hand between submission and approval is applied on
 * the request's figures - the authority read and approved THOSE - and the line
 * keeps what it overwrote (`applied_over_*`) so the trail says so.
 */
class ApproveCreditTermsRequest
{
    public function handle(CreditTermsRequest $request, User $reviewedBy, ?string $note = null): CreditTermsRequest
    {
        if ($request->approvalRequest === null || ! $request->approvalRequest->isPending()) {
            throw new RuntimeException("Credit terms {$request->request_number} has already been reviewed.");
        }

        if (! $reviewedBy->can('sales.credit.approve')) {
            throw new RuntimeException('Approving credit terms needs the sales.credit.approve permission.');
        }

        DB::transaction(function () use ($request, $reviewedBy, $note) {
            $complete = $request->approvalRequest->approve($reviewedBy, $note);

            if (! $complete) {
                return;
            }

            foreach ($request->lines()->with('customer')->lockForUpdate()->get() as $line) {
                /** @var CreditTermsRequestLine $line */
                $customer = $line->customer;

                if ($customer === null) {
                    throw new RuntimeException("Line {$line->id} names a customer that no longer exists; the request cannot be applied.");
                }

                $line->update([
                    'applied_over_credit_limit' => $customer->credit_limit,
                    'applied_over_credit_days' => $customer->credit_days,
                ]);

                $customer->update([
                    'credit_limit' => $line->proposed_credit_limit,
                    'credit_days' => $line->proposed_credit_days,
                ]);

                activity()
                    ->performedOn($customer)
                    ->causedBy($reviewedBy)
                    ->withProperties([
                        'credit_terms_request' => $request->request_number,
                        'credit_limit' => [$line->applied_over_credit_limit, $line->proposed_credit_limit],
                        'credit_days' => [$line->applied_over_credit_days, $line->proposed_credit_days],
                    ])
                    ->log('credit terms applied');
            }

            $request->update(['applied_at' => now(), 'applied_by' => $reviewedBy->id]);
        });

        return $request->fresh(['lines.customer', 'approvalRequest']);
    }
}
