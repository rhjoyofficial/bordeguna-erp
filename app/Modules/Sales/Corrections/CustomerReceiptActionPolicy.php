<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Corrections\Checks\ReceiptAllocationCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A customer receipt: which buttons, which blockers (corrections wave 1,
 * FINAL-PLAN 3.2 "Customer receipt", 4.1 and 4.2).
 *
 *   Correct   amount, date, money account, customer, which invoices: the
 *             receipt is reversed and the right one recorded, in one step
 *   Reverse   the receipt is kept and marked; the invoices it paid owe again
 *   (Re-allocate, to another invoice of the same customer, moves no money and
 *   is SalesCorrectionController's, not the corrections process's.)
 *
 * Refused, each with its sentence:
 *   - made by a Receive voucher: the voucher is reversed, this goes with it;
 *   - a staff purchase settled through payroll: the payroll run owns it;
 *   - money that came through a courier or SSLCommerz: their own path (a parcel
 *     outcome, a payout statement, the gateway) moves it, never this screen;
 *   - matched on the bank statement; an invoice it pays is in a set-off; an
 *     order advance already paid back (the dependency checks).
 *
 * `effectProfile()` = Ledger + BankMatch + Allocation, each covered (v2, C14).
 */
class CustomerReceiptActionPolicy extends SalesDocumentPolicy
{
    public const TYPE = 'sales.customer_receipt';

    public const PERMISSION = 'sales.receipts.correct';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CustomerReceipt::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Allocation],
            [SalesLedgerCheck::class, BankMatchBlocker::class, ReceiptAllocationCheck::class],
        );
    }

    protected function offered(Model $document): array
    {
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        return self::PERMISSION;
    }

    public function documentDate(Model $document): Carbon
    {
        return $document->received_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->amount)->minor;
    }

    public function numberOf(Model $document): string
    {
        return self::label($document);
    }

    /** Receipts carry no number of their own: "Receipt #41". */
    public static function label(Model $receipt): string
    {
        return "Receipt #{$receipt->getKey()}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.receipts.print', $document);
    }

    protected function ownBlockers(Model $document, User $user, DocumentAction $action): array
    {
        /** @var CustomerReceipt $document */
        $blockers = [];
        $label = self::label($document);

        if ($document->voucher_id !== null) {
            $voucher = SalesDependencies::voucherNumber((int) $document->voucher_id) ?? 'its voucher';
            $blockers[] = new Blocker('made_by_voucher', "{$label} was made by voucher {$voucher}; reverse the voucher under Accounting, Vouchers and this goes with it.");
        }

        if ($document->isPayrollOffset()) {
            $blockers[] = new Blocker('payroll_offset', "{$label} settled a staff purchase through payroll; reopen that payroll run instead.");
        }

        $document->loadMissing('moneyAccount');
        $account = $document->moneyAccount;

        if ($account !== null && ($account->isCourier() || $account->isGateway())) {
            $blockers[] = new Blocker('courier_or_gateway', "{$label} came in through {$account->name}, so its parcel, payout statement or gateway payment is what corrects it.");
        }

        return $blockers;
    }
}
