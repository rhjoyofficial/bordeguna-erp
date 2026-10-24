<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierDiscount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): an adjustment another document owns
 * (a set-off, an advance application, a supplier discount, a price credit
 * note, a journal voucher) is undone only through that document, whose own
 * journal it carries. The same refusals RecordPartyAdjustment::remove always
 * made, now as blockers on the button.
 */
class PartyAdjustmentOwnerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof PartyAdjustment) {
            return null;
        }

        $id = $document->getKey();

        if (($number = SetOff::query()->where('party_adjustment_id', $id)->value('number')) !== null) {
            return new Blocker('owned_by_set_off', "This adjustment belongs to set-off {$number}. Reverse the set-off instead.", url: route('accounting.set-offs.index'));
        }

        if (($number = SupplierAdvanceApplication::query()->where('party_adjustment_id', $id)->value('number')) !== null) {
            return new Blocker('owned_by_advance', "This adjustment belongs to advance application {$number}. Reverse that application on its bill instead.");
        }

        if (($number = SupplierDiscount::query()->where('party_adjustment_id', $id)->value('number')) !== null) {
            return new Blocker('owned_by_discount', "This adjustment belongs to discount {$number}. Reverse the discount on its bill instead.");
        }

        if (($number = DB::table('sales_credit_notes')->where('party_adjustment_id', $id)->value('credit_note_number')) !== null) {
            return new Blocker('owned_by_credit_note', "This adjustment belongs to credit note {$number}. Put the credit note right instead.");
        }

        if ($document->voucher_id !== null) {
            $number = DB::table('vouchers')->where('id', $document->voucher_id)->value('voucher_number');

            return new Blocker('owned_by_voucher', "Journal voucher {$number} made this adjustment. Reverse the voucher instead.", url: route('accounting.vouchers.show', $document->voucher_id));
        }

        return null;
    }
}
