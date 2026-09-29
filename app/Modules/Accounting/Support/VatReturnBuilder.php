<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Http\Controllers\VatSettingsController;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\VatReturn;
use App\Modules\Foundation\Models\Business;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;

/**
 * Works out one VAT period: the two registers, the totals, and what is owed.
 *
 * ⚠⚠ TWO SOURCES, DELIBERATELY. The REGISTERS are read from the documents
 * (every invoice, return, bill and credit note of the period, with the VAT that
 * was frozen onto each). The TOTALS that get filed are read from the LEDGER
 * (what actually moved through Output VAT Payable and Input VAT Receivable).
 * The screen shows both and says whether they agree. They must: a difference
 * means a VAT posting exists that no document explains, and that is exactly the
 * thing a tax officer finds. It is shown, never hidden by picking one source.
 *
 * ⚠ Read only. Nothing here writes; FileVatReturn does.
 */
final class VatReturnBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Business $business, Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        $regime = VatRegime::tryFrom((string) $business->vat_regime) ?? VatRegime::NotRegistered;

        $sales = $this->salesRegister($start, $end);
        $purchases = $this->purchaseRegister($start, $end);

        $documentOutput = array_sum(array_column($sales, 'tax_minor'));
        $documentInput = array_sum(array_column($purchases, 'claimed_minor'));

        // Credits are negative in the ledger; output VAT is a credit balance.
        $ledgerOutput = -$this->ledgerMovement('sales.output_vat', $start, $end);
        $ledgerInput = $this->ledgerMovement('purchasing.input_vat', $start, $end);

        $turnover = array_sum(array_column($sales, 'value_minor'));
        $turnoverRate = $regime === VatRegime::TurnoverTax ? $this->turnoverRate($end) : '0.0000';
        $turnoverTax = $regime === VatRegime::TurnoverTax
            ? (new TaxCalculator)->split(Money::ofMinor(max(0, $turnover)), $turnoverRate, false)->tax->minor
            : 0;

        $broughtForward = $this->creditBroughtForward($start);

        $due = $regime === VatRegime::TurnoverTax ? $turnoverTax : $ledgerOutput - $ledgerInput;
        $net = $due - $broughtForward;

        return [
            'regime' => $regime->value,
            'regime_label' => $regime->label(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'sales' => $sales,
            'purchases' => $purchases,
            'output_tax_minor' => $ledgerOutput,
            'input_tax_minor' => $ledgerInput,
            'document_output_minor' => $documentOutput,
            'document_input_minor' => $documentInput,
            'agrees' => $documentOutput === $ledgerOutput && $documentInput === $ledgerInput,
            'turnover_minor' => $turnover,
            'turnover_tax_rate' => $turnoverRate,
            'turnover_tax_minor' => $turnoverTax,
            'credit_brought_forward_minor' => $broughtForward,
            'net_payable_minor' => max(0, $net),
            'credit_carried_forward_minor' => max(0, -$net),
        ];
    }

    /**
     * Every sale and every return of the period (the data of Mushak 6.2).
     *
     * @return array<int, array<string, mixed>>
     */
    private function salesRegister(Carbon $start, Carbon $end): array
    {
        $rows = [];

        // ⚠ Opening invoices are debts from before the ERP, not supplies in this
        // period: counted here they would inflate turnover and turnover tax.
        $invoices = SalesInvoice::query()->trading()->with('customer:id,name')
            ->whereDate('invoice_date', '>=', $start)->whereDate('invoice_date', '<=', $end)
            ->orderBy('invoice_date')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            $total = Money::ofTaka((string) $invoice->total)->minor;
            $tax = Money::ofTaka((string) $invoice->tax_total)->minor;

            $rows[] = [
                'date' => $invoice->invoice_date->toDateString(),
                'kind' => 'Invoice',
                'number' => $invoice->invoice_number,
                'party' => $invoice->customer?->name,
                'value_minor' => $total - $tax,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'url' => route('sales.invoices.show', $invoice),
            ];
        }

        $returns = SalesReturn::query()->with('customer:id,name')
            ->whereDate('returned_at', '>=', $start)->whereDate('returned_at', '<=', $end)
            ->orderBy('returned_at')->orderBy('id')->get();

        foreach ($returns as $return) {
            $total = Money::ofTaka((string) $return->credit_total)->minor;
            $tax = Money::ofTaka((string) $return->tax_total)->minor;

            if ($total === 0) {
                continue;
            }

            $rows[] = [
                'date' => $return->returned_at->toDateString(),
                'kind' => 'Credit note',
                'number' => $return->return_number,
                'party' => $return->customer?->name,
                'value_minor' => -($total - $tax),
                'tax_minor' => -$tax,
                'total_minor' => -$total,
                'url' => null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['number']] <=> [$b['date'], $b['number']]);

        return $rows;
    }

    /**
     * Every bill and supplier credit note of the period (the data of Mushak 6.1).
     * A reversed bill appears twice: on its own date, and negative on the day
     * it was reversed, which is how the ledger holds it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function purchaseRegister(Carbon $start, Carbon $end): array
    {
        $rows = [];

        $bills = SupplierBill::query()->where('is_opening', false)->with(['supplier:id,name', 'lines'])
            ->where(function ($query) use ($start, $end) {
                $query->where(fn ($q) => $q->whereDate('bill_date', '>=', $start)->whereDate('bill_date', '<=', $end))
                    ->orWhere(fn ($q) => $q->whereDate('reversed_at', '>=', $start)->whereDate('reversed_at', '<=', $end));
            })
            ->orderBy('bill_date')->orderBy('id')->get();

        foreach ($bills as $bill) {
            $total = Money::ofTaka((string) $bill->total)->minor;
            $tax = Money::ofTaka((string) $bill->tax_total)->minor;
            $claimed = $bill->recoverableTax()->minor;

            $row = fn (string $date, string $kind, int $sign) => [
                'date' => $date,
                'kind' => $kind,
                'number' => $bill->bill_number,
                'party' => $bill->supplier?->name,
                'value_minor' => $sign * ($total - $tax),
                'tax_minor' => $sign * $tax,
                'claimed_minor' => $sign * $claimed,
                'total_minor' => $sign * $total,
                'url' => route('purchasing.bills.show', $bill),
            ];

            if ($bill->bill_date->betweenIncluded($start, $end)) {
                $rows[] = $row($bill->bill_date->toDateString(), 'Bill', 1);
            }

            if ($bill->reversed_at !== null && $bill->reversed_at->copy()->startOfDay()->betweenIncluded($start, $end)) {
                $rows[] = $row($bill->reversed_at->toDateString(), 'Bill reversed', -1);
            }
        }

        $notes = SupplierCreditNote::query()->with('supplier:id,name')
            ->whereDate('credit_date', '>=', $start)->whereDate('credit_date', '<=', $end)
            ->orderBy('credit_date')->orderBy('id')->get();

        foreach ($notes as $note) {
            $total = Money::ofTaka((string) $note->total)->minor;
            $tax = Money::ofTaka((string) $note->tax_total)->minor;

            $rows[] = [
                'date' => Carbon::parse($note->credit_date)->toDateString(),
                'kind' => 'Credit note',
                'number' => $note->credit_note_number,
                'party' => $note->supplier?->name,
                'value_minor' => -($total - $tax),
                'tax_minor' => -$tax,
                'claimed_minor' => -$tax,
                'total_minor' => -$total,
                'url' => null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['number']] <=> [$b['date'], $b['number']]);

        return $rows;
    }

    /**
     * What moved through a mapped account in the period, in signed paisa,
     * leaving out the settlement journals themselves (which clear it).
     */
    private function ledgerMovement(string $key, Carbon $start, Carbon $end): int
    {
        $mapping = AccountMapping::forKeyOn($key, $end);

        if ($mapping === null) {
            return 0;
        }

        return (int) JournalLine::query()
            ->where('account_id', $mapping->account_id)
            ->whereHas('journal', function ($query) use ($start, $end) {
                $query->whereDate('journal_date', '>=', $start)
                    ->whereDate('journal_date', '<=', $end)
                    ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', (new VatReturn)->getMorphClass()));
            })
            ->sum('amount_minor');
    }

    /**
     * A credit left over from earlier returns: a DEBIT balance sitting on VAT
     * Settlement the day before this period starts. Never paid out, only used
     * against a later period (the Act's carry-forward).
     */
    private function creditBroughtForward(Carbon $start): int
    {
        $account = Account::bySlug('vat_settlement');

        if ($account === null) {
            return 0;
        }

        $balance = (int) JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journal', fn ($query) => $query->whereDate('journal_date', '<', $start))
            ->sum('amount_minor');

        return max(0, $balance);
    }

    private function turnoverRate(Carbon $on): string
    {
        $rate = TaxClass::query()->where('code', VatSettingsController::TURNOVER)->first()?->rateOn($on);

        return $rate === null ? '0.0000' : number_format((float) $rate->rate, 4, '.', '');
    }
}
