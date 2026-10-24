<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 and 4): payment,
 * receive, contra and journal vouchers.
 *
 *   Draft      Edit, Delete (the voucher screens, unchanged; a draft has no number)
 *   Waiting    Withdraw, by whoever sent it: back to a draft, nothing posted
 *   Posted     Fix details (narration, line text, "Paid to" spelling, cheque
 *              bank): the maker or an accountant, any time, even in a closed
 *              month, no journal moves
 *              Correct: reverse on its own date and post the corrected voucher
 *              in one step, while not bank-matched, no cheque status, and it
 *              settled nothing (else Reverse, then Copy)
 *              Reverse: its own date by default, or Today in an open month
 *
 * Correct and Reverse are the accountant's (`accounting.correct`), with the Tk
 * 20,000 second person and the Owner for a closed month, through the
 * corrections process. "The word Edit never appears on a done record."
 */
class VoucherActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'accounting.voucher';

    public const PERMISSION = 'accounting.correct';

    public const FIX_PERMISSIONS = ['accounting.voucher.prepare', 'accounting.correct'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Voucher::class;
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var Voucher $document */
        return match ($document->status) {
            VoucherStatus::Draft => DocumentStage::Draft,
            VoucherStatus::PendingApproval => DocumentStage::Waiting,
            default => DocumentStage::Done,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Allocation],
            [LedgerEntryCheck::class, BankMatchBlocker::class, VoucherSettlementCheck::class],
        );
    }

    protected function correctPermission(): string
    {
        return self::PERMISSION;
    }

    protected function correctionActions(Model $document): array
    {
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    protected function isPosted(Model $document): bool
    {
        /** @var Voucher $document */
        return in_array($document->status, [VoucherStatus::Posted, VoucherStatus::Reversed], true);
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->voucher_date->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        return (int) $document->total_minor;
    }

    protected function isReversed(Model $document): bool
    {
        return $document->status === VoucherStatus::Reversed;
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var Voucher $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence($this->numberOf($document), $document->reversed_at, $document->reversedBy?->name, CorrectionChain::linkAsOriginal($document)?->successor_number);
    }

    protected function extraBlockers(Model $document, User $user, DocumentAction $action): array
    {
        /** @var Voucher $document */
        if ($action !== DocumentAction::Correct || $document->instrument_type !== 'cheque') {
            return [];
        }

        $status = ChequeStatus::query()->current()->where('voucher_id', $document->id)->value('status');

        return $status === null ? [] : [new Blocker('cheque_status', "Correct: the cheque is marked {$status}. Undo the status first, or reverse the voucher.", url: route('accounting.cheques.index'))];
    }

    protected function otherActions(Model $document, User $user): array
    {
        /** @var Voucher $document */
        return match ($document->status) {
            VoucherStatus::PendingApproval => [DocumentAction::Withdraw],
            VoucherStatus::Posted, VoucherStatus::Reversed => $user->canAny(self::FIX_PERMISSIONS) ? [DocumentAction::FixDetails] : [],
            default => [],
        };
    }

    protected function otherAvailability(Model $document, User $user, DocumentAction $action): ?ActionAvailability
    {
        /** @var Voucher $document */
        return match ($action) {
            DocumentAction::FixDetails => $this->plain($action, $this->fixBlockers($document, $user), 'accounting.voucher.prepare', false, 'fix_details'),
            DocumentAction::Withdraw => $this->plain($action, $this->withdrawBlockers($document, $user), 'accounting.voucher.prepare', true, 'withdrawal'),
            default => null,
        };
    }

    /** @return list<Blocker> */
    private function fixBlockers(Voucher $voucher, User $user): array
    {
        if (! $this->isPosted($voucher)) {
            return [new Blocker('not_posted', 'A draft is edited, not fixed.')];
        }

        $maker = (int) $voucher->created_by === $user->id && $user->can('accounting.voucher.prepare');

        return $maker || $user->can(self::PERMISSION)
            ? []
            : [new Blocker('not_maker', 'Only whoever made this voucher, or an accountant, can fix its details.')];
    }

    /** @return list<Blocker> */
    private function withdrawBlockers(Voucher $voucher, User $user): array
    {
        if (! $voucher->isPendingApproval()) {
            return [new Blocker('not_waiting', "{$voucher->title()} is not waiting for approval.")];
        }

        $sender = (int) ($voucher->approvalRequest?->requested_by ?? $voucher->created_by);

        return $sender === $user->id
            ? []
            : [new Blocker('not_sender', 'Only whoever sent it for approval can withdraw it.')];
    }

    public function numberOf(Model $document): string
    {
        /** @var Voucher $document */
        return $document->title();
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.vouchers.show', $document->getKey());
    }
}
