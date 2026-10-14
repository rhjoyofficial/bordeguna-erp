<?php

namespace App\Modules\Purchasing\Reporting\Definitions;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Purchasing\Support\ExpenseSource;
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
 * ⚠ **One row per LINE, not per document.** A voucher can split across accounts
 * — a single payment covering hosting and a domain — and a register that showed
 * only the document total could not be reconciled against the ledger it posted
 * to. The document number repeats down the rows, which makes the split visible
 * rather than hidden.
 *
 * ⛔⛔ **IT READS THE LEDGER, NOT ONE KIND OF DOCUMENT** (Joy, 10 Sep 2026: "when
 * customer do some Expenses, they did for office, if it is, then why they not
 * shows in the Purchasing > Expenses"). He was right, and the cause was that this
 * read `expense_voucher_lines`. An employee claim booked at approval clears a
 * payable when it is paid and writes NO voucher, so a courier Musa paid for out
 * of his own pocket was invisible here while an identical courier paid from the
 * till was not. Since the 1 September cutover that was most of the operating
 * spend.
 *
 * So the register asks the honest question - **what hit an expense account?** -
 * by reading the journal lines themselves. Every source appears: payment
 * vouchers, employee claims, supplier bills, payroll, and anything built next
 * year, automatically, on the day it first posts. Each row names the document
 * that produced it, so a figure can still be walked back to its paper.
 *
 * ⛔ It re-derives nothing. `amount_minor` is the figure the ledger holds.
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
        return 'Every operating expense that hit the books in a period, whatever document produced it - a payment voucher, a staff expense claim, a supplier bill. One row per account line, naming the document it came from. This is the monthly file copy.';
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
            ReportColumn::text('source', 'Came from', 22),
            ReportColumn::text('document', 'Document', 18),
            ReportColumn::text('payee', 'Who', 24),
            ReportColumn::text('account', 'Charged to', 28),
            ReportColumn::text('description', 'What for', 34),
            ReportColumn::text('paid_from', 'Paid from', 18),
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
                $source = ExpenseSource::describe($line->source_type, $line->source_id);

                yield [
                    'spent_on' => $line->journal_date,
                    'source' => $source['label'],
                    'document' => $source['number'] ?? $line->journal_number,
                    'payee' => $source['party'] ?? '-',
                    'account' => $line->account_code.' - '.$line->account_name,
                    'description' => $line->memo,
                    'paid_from' => $line->money_account_name,
                    // ⚠ Paisa to taka. A debit is positive in the ledger and the
                    // query refuses credits, so nothing here is negated.
                    'amount' => round($line->amount_minor / 100, 2),
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
        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            // ⚠⚠ EXPENSE ACCOUNTS ONLY, AND DEBITS ONLY. A credit to an expense
            // account is a refund or a correction reversing a cost, not a cost.
            // Including it would net the register against itself and it would
            // stop agreeing with the profit and loss account line by line.
            ->where('accounts.type', AccountType::Expense->value)
            ->where('journal_lines.amount_minor', '>', 0)
            // ⚠ PUR-003 (14 Oct 2026): a cost whose journal was REVERSED was
            // never spent (an expense voucher, bill or payment voucher undone).
            // The reversal is a credit and so already left out above; leaving
            // the original in would list money that never went.
            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')
                ->from('journals as reversal')
                ->whereColumn('reversal.reverses_journal_id', 'journals.id'))
            ->when(
                $parameters->from('spent'),
                fn (Builder $q, $from) => $q->whereDate('journals.journal_date', '>=', $from->toDateString()),
            )
            ->when(
                $parameters->to('spent'),
                fn (Builder $q, $to) => $q->whereDate('journals.journal_date', '<=', $to->toDateString()),
            )
            ->when(
                $parameters->int('account_id'),
                fn (Builder $q, int $id) => $q->where('journal_lines.account_id', $id),
            )
            ->when(
                $parameters->int('money_account_id'),
                // The paying line, not the expense line (see below): the debit
                // never carries a money account, so filtering on it found nothing.
                fn (Builder $q, int $id) => $q->whereExists(fn ($paid) => $paid->selectRaw('1')
                    ->from('journal_lines as paid_from')
                    ->whereColumn('paid_from.journal_id', 'journal_lines.journal_id')
                    ->where('paid_from.money_account_id', $id)),
            )
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.line_no')
            ->select([
                'journal_lines.id',
                'journal_lines.amount_minor',
                'journal_lines.memo',
                'journals.journal_date',
                'journals.journal_number',
                'journals.source_type',
                'journals.source_id',
                'accounts.code as account_code',
                'accounts.name as account_name',
            ])
            // ⚠⚠ THE MONEY ACCOUNT IS ON THE OTHER SIDE OF THE JOURNAL. An
            // expense DEBIT never carries one; the cash or bank CREDIT does. The
            // reimbursement voucher had the same defect on 7 Sep and printed
            // "Paid directly" followed by nothing. So this reads across the
            // journal rather than down the line.
            //
            // ⚠ Null is the RIGHT answer for a staff claim booked at approval:
            // the cost was recognised that day and no cash moved, which is what
            // an empty "Paid from" honestly says.
            ->selectSub(
                MoneyAccount::query()
                    ->select('money_accounts.name')
                    ->join('journal_lines as paying_line', 'paying_line.money_account_id', '=', 'money_accounts.id')
                    ->whereColumn('paying_line.journal_id', 'journal_lines.journal_id')
                    // A journal paid from two accounts names the first line's,
                    // the same one on every run.
                    ->orderBy('paying_line.line_no')
                    ->orderBy('paying_line.id')
                    ->limit(1),
                'money_account_name',
            );
    }
}
