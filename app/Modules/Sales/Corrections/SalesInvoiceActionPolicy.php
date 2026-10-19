<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Corrections\Checks\CourierParcelCheck;
use App\Modules\Sales\Corrections\Checks\DeliveryStockCheck;
use App\Modules\Sales\Corrections\Checks\InvoiceDependencyCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\CounterSaleBundle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A sales invoice: which buttons, which blockers (corrections wave 1,
 * FINAL-PLAN 3.2 "Sales invoice" and "Counter sale", 4.2, 4.4).
 *
 * The issued invoice NEVER changes (FINAL-PLAN 6.6 rule 1). On the engine's
 * two verbs:
 *
 *   Reverse   = "Credit note": a full credit note with its own CN number,
 *               citing the invoice; its journal mirrored, its challans billable
 *               again. On a COUNTER SALE the same verb is "Void sale": the
 *               receipt, the invoice and the challan are reversed together
 *               (the goods back on the shelf, the cash out of the till).
 *   Correct   = credit note + the right invoice, in one step; payments typed
 *               against it move to the new invoice. Not on a counter sale (void
 *               it and ring it up again).
 *
 * Price credit (goods kept) and Extra charge (a debit note) are new documents
 * of their own, not corrections: SalesCorrectionController.
 *
 * Who: Owner, Manager, Accountant (sales.correct). A counter sale: the cashier
 * herself while her till shift is open (sales.sell), otherwise the Owner or a
 * Manager (sales.counter.void_any); a closed month, the Owner (the engine).
 */
class SalesInvoiceActionPolicy extends SalesDocumentPolicy
{
    public const TYPE = 'sales.invoice';

    public const PERMISSION = 'sales.correct';

    public const VOID_ANY = 'sales.counter.void_any';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SalesInvoice::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Allocation, EffectKind::Stock, EffectKind::External],
            [SalesLedgerCheck::class, InvoiceDependencyCheck::class, DeliveryStockCheck::class, CourierParcelCheck::class],
        );
    }

    protected function offered(Model $document): array
    {
        /** @var SalesInvoice $document */
        return self::isCounterSale($document)
            ? [DocumentAction::Reverse]
            : [DocumentAction::Correct, DocumentAction::Reverse];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        /** @var SalesInvoice $document */
        $bundle = self::voidBundle($document);

        if ($bundle === null) {
            return self::PERMISSION;
        }

        // ⚠ The cashier's own sale while her till is open: the plan's "Staff:
        // own sale while the till shift is open". Anybody else, or later: a
        // Manager or the Owner.
        return ! $user->can(self::VOID_ANY) && $bundle->voidableByCashier($user) ? 'sales.sell' : self::VOID_ANY;
    }

    public function documentDate(Model $document): Carbon
    {
        return $document->invoice_date->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->total)->minor;
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->invoice_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.invoices.show', $document);
    }

    public static function isCounterSale(SalesInvoice $invoice): bool
    {
        return self::voidBundle($invoice) !== null;
    }

    /**
     * The counter sale to void with this invoice, or null.
     *
     * ⚠ Null in a CLOSED month, deliberately: the Owner's one-document pass
     * lets through journals of ONE document and its successor (PHASE-0-CONTRACT
     * 1.10), and a void posts three (receipt, invoice, challan). There the
     * Owner takes the three steps one by one: reverse the receipt, give the
     * credit note, reverse the challan, each its own exception.
     */
    public static function voidBundle(SalesInvoice $invoice): ?CounterSaleBundle
    {
        if (AccountingPeriod::isClosedOn($invoice->invoice_date->copy()->startOfDay())) {
            return null;
        }

        return CounterSaleBundle::of($invoice);
    }

    protected function doneWord(): string
    {
        return 'credited';
    }

    protected function ownBlockers(Model $document, User $user, DocumentAction $action): array
    {
        /** @var SalesInvoice $document */
        $blockers = [];

        if ($document->is_opening) {
            $blockers[] = new Blocker('opening', "Invoice {$document->invoice_number} is an opening balance; correct it under Accounting, Opening balances.");
        }

        if ($document->is_staff_sale) {
            $blockers[] = new Blocker('staff_sale', "Invoice {$document->invoice_number} is a staff purchase, which Staff purchases cancels or returns.");
        }

        return $blockers;
    }
}
