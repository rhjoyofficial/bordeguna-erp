<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What KIND of voucher a journal is, read from the document that posted it.
 *
 * ⚠ `journals.journal_type` cannot answer this: it is `manual` for nearly every
 * journal and the engine never consults it. The kind a person recognises in a
 * Day Book - Sales invoice, Payment voucher, Payroll - is the source document's
 * class, so the Day Book reads that.
 *
 * ⚠ Keyed by class BASENAME. A class this list has never heard of still gets a
 * readable label (its name in words) rather than disappearing from the book,
 * because a Day Book that silently omits a journal is worse than one that
 * labels it plainly.
 */
final class JournalSourceCatalog
{
    /** basename => [label, module, the attribute that carries the document's own number] */
    private const SOURCES = [
        'SalesInvoice' => ['Sales invoice', 'Sales', 'invoice_number'],
        'Delivery' => ['Delivery challan', 'Sales', 'delivery_number'],
        'CustomerReceipt' => ['Customer receipt', 'Sales', 'reference'],
        'CustomerRefund' => ['Customer refund', 'Sales', 'reference'],
        'SalesReturn' => ['Sales return', 'Sales', 'return_number'],
        'CourierStatement' => ['Courier payout', 'Sales', null],
        'GatewayPayment' => ['Online payment', 'Sales', 'tran_id'],
        'GatewayRefund' => ['Online refund', 'Sales', null],
        'GoodsReceipt' => ['Goods received', 'Purchasing', null],
        'SupplierBill' => ['Supplier bill', 'Purchasing', 'bill_number'],
        'SupplierPayment' => ['Supplier payment', 'Purchasing', 'reference'],
        'PurchaseReturn' => ['Purchase return', 'Purchasing', null],
        'SupplierCreditNote' => ['Supplier credit note', 'Purchasing', 'credit_note_number'],
        'ExpenseVoucher' => ['Expense voucher', 'Vouchers', 'voucher_number'],
        'IncomeVoucher' => ['Income voucher', 'Vouchers', 'voucher_number'],
        'MoneyTransfer' => ['Money transfer', 'Vouchers', 'transfer_number'],
        'Voucher' => ['Voucher', 'Vouchers', 'voucher_number'],
        'PayrollRun' => ['Payroll', 'Payroll', null],
        'Loan' => ['Employee loan', 'Payroll', 'reference'],
        'LoanLedgerEntry' => ['Loan settlement', 'Payroll', null],
        'ExpenseClaim' => ['Expense claim', 'Payroll', 'claim_number'],
        'FixedAsset' => ['Fixed asset', 'Assets', 'name'],
        'InventoryAdjustment' => ['Stock adjustment', 'Inventory', null],
        'StockCount' => ['Stock count', 'Inventory', 'count_number'],
        'ProductionRun' => ['Packing run', 'Inventory', 'run_number'],
        'OpeningStock' => ['Opening stock', 'Inventory', null],
        'AmortizationSchedule' => ['Accrual or prepayment', 'Accounting', 'name'],
    ];

    public const MANUAL = 'Journal voucher';

    public const OPENING = 'Opening balance';

    public static function label(?string $sourceType): ?string
    {
        if ($sourceType === null) {
            return null;
        }

        $basename = class_basename($sourceType);

        return self::SOURCES[$basename][0] ?? Str::headline($basename);
    }

    public static function module(?string $sourceType): string
    {
        if ($sourceType === null) {
            return 'Accounting';
        }

        return self::SOURCES[class_basename($sourceType)][1] ?? 'Other';
    }

    /** The source document's own number (INV-..., EXP-...), where it has one. */
    public static function documentNumber(?Model $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $attribute = self::SOURCES[class_basename($source)][2] ?? null;
        $value = $attribute === null ? null : $source->getAttribute($attribute);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * The filter list: label => the fully qualified classes that carry it, so
     * the query filters on the real `source_type` strings in this database.
     *
     * @param  iterable<int, string|null>  $sourceTypesInUse
     * @return array<string, array<int, string>>
     */
    public static function groupByLabel(iterable $sourceTypesInUse): array
    {
        $groups = [];

        foreach ($sourceTypesInUse as $type) {
            if ($type !== null) {
                $groups[self::label($type)][] = $type;
            }
        }

        ksort($groups);

        return $groups;
    }
}
