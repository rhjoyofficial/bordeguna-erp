<?php

namespace App\Modules\Purchasing\Reporting\Definitions;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Purchasing\Models\ExpenseVoucherLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Every expense actually paid in a period — the copy that goes in the file.
 *
 * Joy, 1 September 2026: "always export not only csv, pdf, and must printable,
 * also the prepared by, checked by and approve by things need". The expense
 * screen already exported a CSV of its own; this is the same data as a REPORT
 * DEFINITION instead, which is what buys the rest of that sentence for free —
 * CSV, Excel, PDF and a printable page, all with the letterhead at the top and
 * the three signature blocks at the foot, because those live in the shared
 * writer rather than in any one report.
 *
 * ⚠ **One row per LINE, not per voucher.** A voucher can split across accounts
 * — a single payment covering hosting and a domain — and a register that showed
 * only the voucher total could not be reconciled against the ledger it posted
 * to. The voucher number repeats down the rows, which is what makes the split
 * visible rather than hidden.
 *
 * ⛔ **It reads vouchers, it does not re-derive them.** The amount here is the
 * line as recorded; the same figure the journal took. A report that recomputed
 * anything would have invented a second source of truth.
 */
class ExpenseRegisterReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'purchasing_expense_register';
    }

    public function name(): string
    {
        return 'Expense register';
    }

    public function description(): string
    {
        return 'Every expense paid in a period, one row per account line, with what it was for, who it went to and which account it left. This is the monthly file copy.';
    }

    public function group(): string
    {
        return 'Purchases';
    }

    public function permission(): string
    {
        return 'purchasing.reports.view';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::dateRange('spent', 'Paid between', required: false),

            ReportParameter::select(
                'account_id',
                'Expense account',
                Account::query()
                    ->where('type', AccountType::Expense)
                    ->where('is_postable', true)
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Account $a) => ['value' => $a->id, 'label' => $a->label()])
                    ->all(),
            ),

            ReportParameter::select(
                'money_account_id',
                'Paid from',
                MoneyAccount::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (MoneyAccount $a) => ['value' => $a->id, 'label' => $a->name])
                    ->all(),
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::date('spent_on', 'Date'),
            ReportColumn::text('voucher_number', 'Voucher', 16),
            ReportColumn::text('payee', 'Paid to', 26),
            ReportColumn::text('account', 'Charged to', 28),
            ReportColumn::text('description', 'What for', 34),
            ReportColumn::text('reference', 'Reference', 18),
            ReportColumn::text('paid_from', 'Paid from', 20),
            ReportColumn::money('amount', 'Amount'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['amount'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $line) {
                yield [
                    'spent_on' => $line->spent_on,
                    'voucher_number' => $line->voucher_number,
                    // The payee as the voucher itself answers it: a supplier
                    // where there is one, the typed name where there is not.
                    'payee' => $line->supplier_name ?? $line->paid_to ?? 'Unnamed payee',
                    'account' => $line->account_code.' - '.$line->account_name,
                    'description' => $line->description,
                    'reference' => $line->reference,
                    'paid_from' => $line->money_account_name,
                    'amount' => round((float) $line->amount, 2),
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $from = $parameters->from('spent');
        $to = $parameters->to('spent');

        $subtitle = $from && $to
            ? $from->format('d M Y').' to '.$to->format('d M Y')
            : 'All expenses recorded to date';

        return new ReportMeta(
            title: 'Expense register',
            subtitle: $subtitle,
            // ⚠ There is no period lock on an expense voucher, and saying
            // nothing at all would let a reader assume there was one.
            periodLocked: null,
            lockNote: 'An expense voucher is paid the moment it is recorded, so every row here is money that has already left. Nothing on this report is a forecast.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        return ExpenseVoucherLine::query()
            ->join('expense_vouchers', 'expense_vouchers.id', '=', 'expense_voucher_lines.expense_voucher_id')
            ->join('accounts', 'accounts.id', '=', 'expense_voucher_lines.account_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'expense_vouchers.supplier_id')
            ->leftJoin('money_accounts', 'money_accounts.id', '=', 'expense_vouchers.money_account_id')
            ->when(
                $parameters->from('spent'),
                fn (Builder $q, $from) => $q->whereDate('expense_vouchers.spent_on', '>=', $from->toDateString()),
            )
            ->when(
                $parameters->to('spent'),
                fn (Builder $q, $to) => $q->whereDate('expense_vouchers.spent_on', '<=', $to->toDateString()),
            )
            ->when(
                $parameters->int('account_id'),
                fn (Builder $q, int $id) => $q->where('expense_voucher_lines.account_id', $id),
            )
            ->when(
                $parameters->int('money_account_id'),
                fn (Builder $q, int $id) => $q->where('expense_vouchers.money_account_id', $id),
            )
            ->orderBy('expense_vouchers.spent_on')
            ->orderBy('expense_vouchers.id')
            ->orderBy('expense_voucher_lines.id')
            ->select([
                'expense_voucher_lines.id',
                'expense_voucher_lines.description',
                'expense_voucher_lines.amount',
                'expense_vouchers.spent_on',
                'expense_vouchers.voucher_number',
                'expense_vouchers.reference',
                'expense_vouchers.paid_to',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'suppliers.name as supplier_name',
                'money_accounts.name as money_account_name',
            ]);
    }
}
