<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What pressing Post does (finance decision B13, 29 Sep 2026).
 *
 * A payment or contra above the finance-policy limit (default Tk 20,000) that
 * its own preparer is posting goes to a second person instead: it becomes
 * "awaiting approval", takes no number and touches no ledger, and posts the
 * moment somebody else approves it. Everything else posts at once, exactly as
 * before.
 *
 * ⚠ Two people already means a second pair of eyes: Finance prepares, the Owner
 * posts - that posts directly. The rule exists for the one person who both
 * writes and posts a large payment.
 *
 * ⚠ A limit of zero switches the rule off (Finance policy), never an inactive
 * workflow row: a request with no workflow reaches nobody and waits for ever.
 */
class SubmitOrPostVoucher
{
    public function __construct(
        private readonly PostVoucher $post,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /**
     * @return array{voucher: Voucher, pending: bool}
     *
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, User $by): array
    {
        if (! $this->needsSecondPerson($voucher, $by)) {
            return ['voucher' => $this->post->handle($voucher, $by), 'pending' => false];
        }

        $workflowExists = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $voucher->business_id)->where('key', 'voucher')->where('is_active', true)
            ->whereHas('stages')->exists();

        if (! $workflowExists) {
            throw ValidationException::withMessages([
                'voucher' => 'Nobody is set up to approve large vouchers yet. Ask the administrator to run the voucher approval setup.',
            ]);
        }

        $request = DB::transaction(function () use ($voucher, $by) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if (! $voucher->isDraft()) {
                throw ValidationException::withMessages(['voucher' => "{$voucher->title()} is not a draft."]);
            }

            $voucher->update(['status' => VoucherStatus::PendingApproval]);

            return $voucher->resubmitForApproval($by);
        });

        $voucher = $voucher->fresh();
        $this->notify->handle($request, "{$voucher->type->label()} of {$voucher->total()->format()} is waiting for your approval");

        return ['voucher' => $voucher, 'pending' => true];
    }

    public function needsSecondPerson(Voucher $voucher, User $by): bool
    {
        if (! in_array($voucher->type, [VoucherType::Payment, VoucherType::Contra], true)) {
            return false;
        }

        if ($by->id !== (int) $voucher->created_by) {
            return false;
        }

        $limit = FinancePolicy::for((int) $voucher->business_id)->money('payment_second_approval_above_minor');

        return $limit->minor > 0 && $voucher->total()->minor > $limit->minor;
    }
}
