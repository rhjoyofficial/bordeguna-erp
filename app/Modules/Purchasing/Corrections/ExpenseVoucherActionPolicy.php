<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Corrections\StockPolicySupport;
use App\Modules\Purchasing\Actions\ReverseExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): an expense voucher's
 * existing Reverse (PUR-003) run through the corrections engine, so it has a
 * preview, a History line and the shared date rules. The posting is the old
 * one (ReverseExpenseVoucher): the mirror journal, a standing cost's month due
 * again.
 *
 *   Who        purchasing.expense.record, as the old Reverse (who records an
 *              expense may reverse one); a closed month is the Owner's
 *              one-document exception; above Tk 20,000 a second person
 *   Blockers   already reversed; a correction already waiting; it pays a
 *              staff expense claim (reversed from the claim); not exactly one
 *              live journal (LedgerEntryCheck); matched on the bank statement
 *              (BankMatchBlocker)
 *
 * Correct is not offered: a wrong voucher is reversed and recorded again.
 */
class ExpenseVoucherActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'purchasing.expense_voucher';

    public const PERMISSION = 'purchasing.expense.record';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return ExpenseVoucher::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.reports.view') || $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch], [LedgerEntryCheck::class, BankMatchBlocker::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var ExpenseVoucher $document */
        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [StockPolicySupport::notOffered($action, 'an expense voucher')], true, null);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_reversed', "Expense voucher {$document->voucher_number} was already reversed.");
        } elseif (($why = ReverseExpenseVoucher::refusal($document)) !== null) {
            $blockers[] = new Blocker('pays_a_claim', $why);
        }

        return $this->support->correction(
            document: $document,
            user: $user,
            action: $action,
            blockers: $blockers,
            profile: $this->effectProfile(),
            originalDate: $this->documentDate($document),
            basisMinor: $this->totalMinor($document),
            permission: self::PERMISSION,
            number: (string) $document->voucher_number,
            runChecks: $blockers === [],
        );
    }

    public function documentDate(ExpenseVoucher $document): Carbon
    {
        return Carbon::parse($document->spent_on->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var ExpenseVoucher $document */
        return Money::ofTaka((string) $document->total)->minor;
    }

    public function numberOf(Model $document): string
    {
        /** @var ExpenseVoucher $document */
        return (string) $document->voucher_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.expenses.index');
    }
}
