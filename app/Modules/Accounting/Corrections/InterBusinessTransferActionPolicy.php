<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessesWithPermission;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 "Inter-business
 * transfer: Reverse in both businesses at once, then Copy").
 *
 *   Reverse   from the SENDER's books only: the sender's journal and the
 *             receiver's journal are reversed in one transaction, on the same
 *             date, so the Due-from and the Due-to keep cancelling across the
 *             group. Then Copy opens the record form filled in.
 *   Correct   not offered (the plan: Reverse + Copy, both months)
 *
 * ⚠ The receiver's side is checked here too, because no check of the sender's
 * books can see it: the receiver's month must be open on that date, the person
 * must be allowed to post journals there, and the receiver's money line must
 * not be matched on its bank statement. The Owner's closed-month pass covers
 * the SENDER's books only; a closed month in the receiver's books is a plain
 * refusal.
 */
class InterBusinessTransferActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'accounting.inter_business_transfer';

    public const PERMISSION = 'accounting.journal.post';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return InterBusinessTransfer::class;
    }

    public function canView(Model $document, User $user): bool
    {
        /** @var InterBusinessTransfer $document */
        $here = (int) app(BusinessContext::class)->id();

        return $user->can('accounting.view') && in_array($here, [(int) $document->from_business_id, (int) $document->to_business_id], true);
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch], [LedgerEntryCheck::class, BankMatchBlocker::class]);
    }

    protected function correctPermission(): string
    {
        return self::PERMISSION;
    }

    protected function correctionActions(Model $document): array
    {
        return [DocumentAction::Reverse];
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->transferred_on->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var InterBusinessTransfer $document */
        return $document->money()->minor;
    }

    protected function isReversed(Model $document): bool
    {
        return $document->isReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var InterBusinessTransfer $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence($this->numberOf($document), $document->reversed_at, $document->reversedBy?->name, CorrectionChain::linkAsOriginal($document)?->successor_number);
    }

    protected function extraBlockers(Model $document, User $user, DocumentAction $action): array
    {
        /** @var InterBusinessTransfer $document */
        $here = (int) app(BusinessContext::class)->id();

        if ($here !== (int) $document->from_business_id) {
            $sender = Business::withoutGlobalScopes()->find($document->from_business_id)?->name ?? 'the sending business';

            return [new Blocker('reverse_from_sender', "Reverse it from {$sender}'s books, where it was sent from.")];
        }

        $receiver = Business::withoutGlobalScopes()->find($document->to_business_id);
        $name = $receiver?->name ?? 'The receiving business';
        $blockers = [];

        if (! BusinessesWithPermission::of($user, self::PERMISSION)->contains((int) $document->to_business_id)) {
            $blockers[] = new Blocker('receiver_permission', "You may not post journals in {$name}'s books.");
        }

        if (self::receiverClosedOn($document, $this->dateOf($document))) {
            $month = $this->dateOf($document)->format('F Y');
            $blockers[] = new Blocker('receiver_closed', "{$month} is closed in {$name}'s books. Ask the Owner to open it there first.");
        }

        if (self::receiverMatched($document)) {
            $blockers[] = new Blocker('receiver_bank_matched', "{$action->label()}: matched on {$name}'s bank statement. Unmatch it there first.");
        }

        return $blockers;
    }

    public function numberOf(Model $document): string
    {
        /** @var InterBusinessTransfer $document */
        return 'IBT-'.str_pad((string) $document->id, 4, '0', STR_PAD_LEFT);
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.inter-business.index').'#transfer-'.$document->getKey();
    }

    /** Whether the receiver's books are closed on that date (asked inside the receiver's context). */
    public static function receiverClosedOn(InterBusinessTransfer $transfer, Carbon $date): bool
    {
        $here = (int) app(BusinessContext::class)->id();
        TenantScope::enter((int) $transfer->to_business_id);

        try {
            return AccountingPeriod::isClosedOn($date);
        } finally {
            TenantScope::enter($here);
        }
    }

    /** Whether the receiver's money line is claimed by a line of the receiver's bank statement. */
    public static function receiverMatched(InterBusinessTransfer $transfer): bool
    {
        $journals = DB::table('journals')
            ->where('business_id', $transfer->to_business_id)
            ->where('source_type', $transfer->getMorphClass())
            ->where('source_id', $transfer->getKey())
            ->pluck('id');

        return $journals->isNotEmpty() && DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $journals)->select('id'))
            ->exists();
    }
}
