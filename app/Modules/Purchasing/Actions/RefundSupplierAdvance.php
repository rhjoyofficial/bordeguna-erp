<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Support\PurchaseDraft;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The supplier gives an advance back, in full or in part (ruling P2):
 *
 *   Dr the money account   Cr 1160 Advances to Suppliers (party = supplier)
 *
 * ⚠ Capped at what is still open on the advance, re-read under the supplier's
 * lock inside the transaction, so two refunds at once cannot both take the
 * last of it. `$advance` null refunds out of the on-account balance.
 */
class RefundSupplierAdvance
{
    public function __construct(
        private readonly PostSupplierAdvanceJournals $posting,
        // ⭐ Purchasing approvals (ruling P7, 18 Oct 2026).
        private readonly PurchasingApprovalGate $approvals,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Supplier $supplier,
        ?SupplierAdvance $advance,
        MoneyAccount $moneyAccount,
        mixed $refundedOn,
        string $amount,
        User $by,
        ?string $reference = null,
        ?string $note = null,
        // ⭐ A Receipt voucher line on a supplier (party ledgers, 20 Oct 2026):
        // the refund belongs to the voucher, whose own journal carries the
        // credit to 1160, so no second journal is posted here.
        ?int $voucherId = null,
    ): SupplierAdvanceRefund {
        $on = Carbon::parse($refundedOn)->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['refunded_on' => 'A refund cannot be dated in the future.']);
        }

        AccountingPeriod::assertOpenFor($on);

        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount the supplier gave back.']);
        }

        RecordSupplierAdvance::assertMoneyAccount($moneyAccount, $supplier);

        return DB::transaction(function () use ($supplier, $advance, $moneyAccount, $on, $money, $by, $reference, $note, $voucherId) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if ($advance !== null) {
                $advance = SupplierAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();

                if ($advance->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['supplier_advance_id' => "Advance {$advance->number} belongs to a different supplier."]);
                }

                if ($advance->isReversed()) {
                    throw ValidationException::withMessages(['supplier_advance_id' => "Advance {$advance->number} is reversed."]);
                }

                if ($on->lt($advance->paid_on->copy()->startOfDay())) {
                    throw ValidationException::withMessages(['refunded_on' => "Advance {$advance->number} was paid after that date."]);
                }
            }

            $open = $advance === null ? SupplierAdvanceBook::onAccount($supplier) : $advance->open();

            if ($money->minor > $open->minor) {
                $name = $advance?->number ?? 'The on-account balance';

                throw ValidationException::withMessages(['amount' => "{$name} has only ".Money::ofMinor(max(0, $open->minor))->format().' left to refund.']);
            }

            // ⭐ Purchasing approvals (ruling P7): the amount rule applies to a
            // refund too; asked once the refund is known to be possible.
            $draft = new PurchaseDraft(
                kind: 'refund',
                supplier: $supplier,
                amount: $money,
                documentDate: $on,
                reference: $reference,
                summary: "Refund from {$supplier->name} into {$moneyAccount->name}".($advance ? " of advance {$advance->number}" : ''),
                payload: [
                    'supplier_id' => $supplier->id,
                    'supplier_advance_id' => $advance?->id,
                    'money_account_id' => $moneyAccount->id,
                    'refunded_on' => $on->toDateString(),
                    'amount' => $money->toTaka(),
                    'reference' => $reference,
                    'note' => $note,
                ],
                moneyAccount: $moneyAccount,
            );

            if ($voucherId === null) {
                $this->approvals->enforce($draft, $by);
            } else {
                $this->approvals->enforceForVoucher($draft, $by, (int) DB::table('vouchers')->where('id', $voucherId)->value('created_by'));
            }

            $prefix = RecordSupplierAdvance::prefix('SARF', $supplier, $on);

            $refund = DocumentNumber::claim(
                'number',
                fn () => DocumentNumber::next(SupplierAdvanceRefund::query(), 'number', $prefix),
                fn (string $number) => SupplierAdvanceRefund::create([
                    'business_id' => $supplier->business_id,
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'supplier_advance_id' => $advance?->id,
                    'money_account_id' => $moneyAccount->id,
                    'refunded_on' => $on->toDateString(),
                    'amount' => $money->toTaka(),
                    'reference' => $reference,
                    'note' => $note ?? ($advance === null ? SupplierAdvanceBook::onAccountSourceNote($supplier, $money) : null),
                    'created_by' => $by->id,
                    'voucher_id' => $voucherId,
                ]),
            );

            if ($voucherId === null) {
                $this->posting->forRefund($refund, $by);
            }

            return $refund->fresh(['supplier', 'advance', 'moneyAccount']);
        });
    }
}
