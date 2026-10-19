<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Corrections\Checks\CourierParcelCheck;
use App\Modules\Sales\Corrections\Checks\DeliveryStockCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A challan: Reverse (corrections wave 1, FINAL-PLAN 3.2 "Challan").
 *
 *   Reverse   when nothing on it is billed or came back, and no courier holds
 *             it: the goods go back onto the same batches at the same cost, the
 *             GDNI journal is mirrored on its own date, and the quantity goes
 *             back on the order. Owner and Manager (sales.deliveries.reverse).
 *   Correct   not offered: a challan for the wrong goods is reversed and the
 *             right one raised from the order, which then shows what is due.
 *
 * Refused while billed (credit the invoice first: a full credit note makes the
 * challan billable, and so reversible, again) or while any of it came back.
 *
 * `effectProfile()` = Ledger + Stock + External, each covered (v2, C12, C14).
 */
class DeliveryActionPolicy extends SalesDocumentPolicy
{
    public const TYPE = 'sales.delivery';

    public const PERMISSION = 'sales.deliveries.reverse';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Delivery::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock, EffectKind::External],
            [SalesLedgerCheck::class, DeliveryStockCheck::class, CourierParcelCheck::class],
        );
    }

    protected function offered(Model $document): array
    {
        return [DocumentAction::Reverse];
    }

    protected function permissionFor(Model $document, User $user, DocumentAction $action): string
    {
        return self::PERMISSION;
    }

    public function documentDate(Model $document): Carbon
    {
        return $document->delivered_at->copy()->startOfDay();
    }

    /** What the goods on it cost: the figure the Tk 20,000 rule reads for a challan. */
    public function totalMinor(Model $document): int
    {
        return $document->loadMissing('lines.movements')->costValue()->minor;
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->delivery_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.deliveries.print', $document);
    }

    protected function ownBlockers(Model $document, User $user, DocumentAction $action): array
    {
        /** @var Delivery $document */
        $document->loadMissing('lines');
        $blockers = [];

        if ($document->lines->contains(fn ($line) => (float) $line->quantity_invoiced > 0.00005)) {
            $invoice = SalesInvoice::query()->live()
                ->whereIn('id', SalesInvoiceLine::query()->whereIn('delivery_line_id', $document->lines->modelKeys())->select('sales_invoice_id'))
                ->orderBy('id')
                ->value('invoice_number');

            $blockers[] = new Blocker(
                'invoiced',
                "Challan {$document->delivery_number} is billed on invoice ".($invoice ?? 'an invoice').'; credit that invoice first.',
                resolvableBy: 'accountant',
            );
        }

        if ($document->lines->contains(fn ($line) => (float) $line->quantity_returned > 0.00005)) {
            $blockers[] = new Blocker(
                'returned',
                "Goods from challan {$document->delivery_number} already came back on a sales return, so it cannot be reversed.",
            );
        }

        return $blockers;
    }
}
