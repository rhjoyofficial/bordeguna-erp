<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 "Cheque status:
 * Undo status. A wrong Bounced re-posts the voucher properly"). Owner only.
 *
 *   Cleared    the status is undone: the cheque is pending again. No journal
 *              moved when it was marked, so none moves now.
 *   Bounced,   marking it reversed the voucher. Undoing it re-posts the
 *   Cancelled  payment properly: a correcting copy of the voucher (its own
 *              date, cheque number and bank) is posted as a new voucher, and
 *              the old one stays Reversed with its reversal. Its cheque is
 *              pending on the new voucher.
 *
 * ⚠ The status row is KEPT and marked undone (who, when, why), never deleted;
 * the History keeps both steps. A re-post into a closed month is refused: it
 * would be a new voucher in a month the Owner closed.
 */
class UndoChequeStatus
{
    public function __construct(
        private readonly ReverseVoucher $reverse,
        private readonly PostVoucher $post,
    ) {}

    /**
     * @return Voucher|null the re-posted voucher, when a bounced or cancelled cheque was undone
     *
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, ActionReason $reason, User $by): ?Voucher
    {
        if (! Owners::is($by)) {
            throw ValidationException::withMessages(['reason_text' => 'Only the Owner can undo a cheque status.']);
        }

        return DB::transaction(function () use ($voucher, $reason, $by) {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $status = ChequeStatus::query()->current()->where('voucher_id', $voucher->id)->lockForUpdate()->first();

            if ($status === null) {
                throw ValidationException::withMessages(['reason_text' => 'This cheque has no status to undo.']);
            }

            $copy = null;

            if ($status->status !== 'cleared') {
                if ($voucher->status !== VoucherStatus::Reversed) {
                    throw ValidationException::withMessages(['reason_text' => "{$voucher->title()} is not reversed, so there is nothing to re-post."]);
                }

                if ($voucher->copies()->whereIn('status', [VoucherStatus::Posted->value, VoucherStatus::PendingApproval->value])->exists()) {
                    throw ValidationException::withMessages(['reason_text' => "{$voucher->title()} was already copied into a new voucher. Undo is not needed."]);
                }

                try {
                    AccountingPeriod::assertOpenFor($voucher->voucher_date->copy()->startOfDay());
                } catch (PeriodClosedException) {
                    throw ValidationException::withMessages(['reason_text' => $voucher->voucher_date->format('F Y').' is closed. Record the payment again in an open month.']);
                }

                $draft = $this->reverse->copy($voucher, $by, correcting: true);
                $copy = $this->post->handle($draft, $by);
            }

            $status->forceFill(['undone_at' => now(), 'undone_by' => $by->id, 'undo_reason' => $reason->display()])->save();

            CorrectionLog::event('cheque_status_undone', $by, $voucher, array_filter([
                'number' => $voucher->title(),
                'before' => ['cheque' => $status->status],
                'after' => ['cheque' => 'pending'],
                'reposted_as' => $copy?->voucher_number,
                'reason' => $reason->display(),
            ]));

            return $copy;
        });
    }
}
