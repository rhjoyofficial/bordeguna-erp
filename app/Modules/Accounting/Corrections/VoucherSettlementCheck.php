<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 and 4.2): a voucher
 * that settled invoices or bills, refunded a party, made a write-off or moved
 * a register loan is not Corrected in one step. Those links would change, and
 * the plan says: "Then: Reverse and copy". Reverse stays open: ReverseVoucher
 * marks every one of those documents reversed with it and releases what they
 * settled.
 */
class VoucherSettlementCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if ($action !== DocumentAction::Correct || ! $document instanceof Voucher) {
            return null;
        }

        return self::settlesSomething($document)
            ? new Blocker('voucher_settles', 'Correct: this voucher settled invoices, bills, a refund or a loan. Reverse it, then Copy.')
            : null;
    }

    public static function settlesSomething(Voucher $voucher): bool
    {
        foreach ([CustomerReceipt::class, SupplierPayment::class, SupplierRefund::class, SupplierAdvanceRefund::class, CustomerRefund::class, PartyAdjustment::class] as $class) {
            if ($class::query()->where('voucher_id', $voucher->id)->exists()) {
                return true;
            }
        }

        return DB::table('voucher_lines')->where('voucher_id', $voucher->id)->whereNotNull('loan_contract_id')->exists();
    }
}
