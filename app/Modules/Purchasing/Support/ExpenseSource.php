<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\SupplierBill;

/**
 * Which document put this cost in the books, said in words a human uses.
 *
 * ⚠⚠ THE REGISTER READS THE LEDGER NOW, SO IT MEETS EVERY KIND OF DOCUMENT
 * (Joy, 10 Sep 2026). Before, it read expense vouchers and nothing else, which
 * is why an employee claim - booked at approval, settled by clearing a payable,
 * writing no voucher at all - never appeared on the expenses screen even though
 * the money had genuinely left the business. A ledger-driven register sees
 * everything, but a journal only knows a class name and an id, so something has
 * to turn that back into "Staff expense claim, EC-00011, Musa Islam".
 *
 * ⚠ A TYPE THIS CLASS DOES NOT KNOW STILL APPEARS. It falls back to the journal
 * number and a plain label rather than vanishing, because a cost the register
 * hides is worse than one it describes badly - that was the whole defect.
 *
 * ⚠ Rows are looked up one at a time as the report streams. The register is a
 * monthly document over a few hundred lines, and the alternative - preloading
 * every source type - would trade a real simplicity for an imaginary saving.
 * Revisit if this is ever pointed at a year of a busy ledger.
 */
class ExpenseSource
{
    /**
     * @return array{label: string, number: string|null, party: string|null}
     */
    public static function describe(?string $type, null|int|string $id): array
    {
        if ($type === null || $id === null) {
            // A journal typed by hand on the manual screen. It is a real cost
            // and belongs in the register; it just has no document behind it.
            return ['label' => 'Manual journal', 'number' => null, 'party' => null];
        }

        return match ($type) {
            (new ExpenseVoucher)->getMorphClass() => self::voucher((int) $id),
            (new ExpenseClaim)->getMorphClass() => self::claim((int) $id),
            (new SupplierBill)->getMorphClass() => self::bill((int) $id),
            (new PayrollRun)->getMorphClass() => self::payroll((int) $id),
            default => ['label' => class_basename($type), 'number' => null, 'party' => null],
        };
    }

    /** @return array{label: string, number: string|null, party: string|null} */
    private static function voucher(int $id): array
    {
        $voucher = ExpenseVoucher::withoutGlobalScopes()->with('supplier:id,name')->find($id);

        return [
            'label' => 'Payment voucher',
            'number' => $voucher?->voucher_number,
            'party' => $voucher?->supplier?->name ?? $voucher?->paid_to,
        ];
    }

    /** @return array{label: string, number: string|null, party: string|null} */
    private static function claim(int $id): array
    {
        $claim = ExpenseClaim::withoutGlobalScopes()->with('employee')->find($id);

        return [
            // ⭐ The label an owner asked for by name: money a member of staff
            // spent from their own pocket, which is a different kind of cost
            // from one the office paid directly, and reads differently.
            'label' => 'Staff expense claim',
            'number' => $claim?->claim_number,
            'party' => $claim?->employee?->fullName(),
        ];
    }

    /** @return array{label: string, number: string|null, party: string|null} */
    private static function bill(int $id): array
    {
        $bill = SupplierBill::withoutGlobalScopes()->with('supplier:id,name')->find($id);

        return [
            'label' => 'Supplier bill',
            'number' => $bill?->bill_number,
            'party' => $bill?->supplier?->name,
        ];
    }

    /** @return array{label: string, number: string|null, party: string|null} */
    private static function payroll(int $id): array
    {
        $run = PayrollRun::withoutGlobalScopes()->with('period')->find($id);

        return [
            'label' => 'Payroll',
            'number' => $run?->period?->label(),
            'party' => null,
        ];
    }
}
