<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\CustomerAdvanceBook;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A customer's advance used against one of their invoices (B11, 24 Oct
 * 2026): the mirror of ApplySupplierAdvances.
 *
 * For each advance used, one application document (CAAP-...):
 *   Dr 2150 Advances from Customers (party)  Cr 1140 Accounts Receivable (party)
 * and a `customer_advance_applied` party adjustment on the invoice, which is
 * how the invoice's outstanding, the ageing, the statements and the AR
 * reconciliation all see it without learning a new table.
 *
 * ⚠⚠ NEVER APPLIED TWICE. The customer row is locked first, so two clerks
 * using this customer's advances queue behind each other, and every advance
 * and the invoice are RE-READ inside the transaction after the lock: the open
 * figures come from what is committed, not from the form. `lockForUpdate` is a
 * no-op on SQLite, which is why the re-read is the rule.
 *
 * ⚠ Dated the invoice's date or later, never before the advance existed,
 * never in the future, never in a closed month.
 */
class ApplyCustomerAdvances
{
    public function __construct(private readonly PostCustomerAdvanceJournals $posting) {}

    /**
     * @param  array<int, array{advance: CustomerAdvance|null, amount: string}>  $rows  advance null = the on-account balance
     * @return Collection<int, CustomerAdvanceApplication>
     *
     * @throws ValidationException
     */
    public function handle(SalesInvoice $invoice, array $rows, mixed $appliedOn, User $by, ?string $note = null): Collection
    {
        $on = Carbon::parse($appliedOn ?? 'today')->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['applied_on' => 'An advance cannot be used on a future date.']);
        }

        if ($on->lt($invoice->invoice_date->copy()->startOfDay())) {
            throw ValidationException::withMessages(['applied_on' => 'Use an advance on the invoice date or later.']);
        }

        AccountingPeriod::assertOpenFor($on);

        $rows = array_values(array_filter($rows, fn (array $row) => Money::ofTaka($row['amount'])->minor > 0));

        if ($rows === []) {
            throw ValidationException::withMessages(['advances' => 'Say how much of which advance to use.']);
        }

        return DB::transaction(function () use ($invoice, $rows, $on, $by, $note) {
            $customer = Customer::query()->whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $invoice = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->isReversed()) {
                throw ValidationException::withMessages(['advances' => "Invoice {$invoice->invoice_number} is credited."]);
            }

            $invoiceLeft = $invoice->outstanding()->minor;
            $claimed = [];
            $planned = [];

            foreach ($rows as $index => $row) {
                $amount = Money::ofTaka($row['amount']);
                $advance = $row['advance'] === null
                    ? null
                    : CustomerAdvance::query()->whereKey($row['advance']->id)->lockForUpdate()->firstOrFail();
                $key = CustomerAdvanceBook::keyOf($advance);
                $field = "advances.{$index}.amount";

                if ($advance !== null) {
                    if ($advance->customer_id !== $customer->id) {
                        throw ValidationException::withMessages([$field => "Advance {$advance->number} belongs to a different customer."]);
                    }

                    if ($advance->isReversed()) {
                        throw ValidationException::withMessages([$field => "Advance {$advance->number} is reversed."]);
                    }

                    if ($on->lt($advance->received_on->copy()->startOfDay())) {
                        throw ValidationException::withMessages(['applied_on' => "Advance {$advance->number} was received after that date."]);
                    }
                }

                $open = ($advance === null ? CustomerAdvanceBook::onAccount($customer) : $advance->open())->minor - ($claimed[$key] ?? 0);
                $name = $advance?->number ?? 'The on-account balance';

                if ($amount->minor > $open) {
                    throw ValidationException::withMessages([$field => "{$name} has only ".Money::ofMinor(max(0, $open))->format().' left to use.']);
                }

                if ($amount->minor > $invoiceLeft) {
                    throw ValidationException::withMessages([$field => "Invoice {$invoice->invoice_number} has only ".Money::ofMinor(max(0, $invoiceLeft))->format().' outstanding.']);
                }

                $claimed[$key] = ($claimed[$key] ?? 0) + $amount->minor;
                $invoiceLeft -= $amount->minor;
                $planned[] = [$advance, $amount];
            }

            $applications = collect();

            foreach ($planned as [$advance, $amount]) {
                $applications->push($this->applyOne($customer, $invoice, $advance, $amount, $on, $by, $note));
            }

            return $applications;
        });
    }

    private function applyOne(Customer $customer, SalesInvoice $invoice, ?CustomerAdvance $advance, Money $amount, Carbon $on, User $by, ?string $note): CustomerAdvanceApplication
    {
        $from = $advance?->number ?? 'on-account balance';

        $adjustment = PartyAdjustment::create([
            'business_id' => $customer->business_id,
            'kind' => 'customer_advance_applied',
            'adjusted_on' => $on->toDateString(),
            'customer_id' => $customer->id,
            'amount' => $amount->toTaka(),
            'reason' => "Advance {$from} used on invoice {$invoice->invoice_number}",
            'created_by' => $by->id,
        ]);
        $adjustment->allocations()->create([
            'business_id' => $adjustment->business_id,
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount->toTaka(),
        ]);

        $prefix = RecordCustomerAdvance::prefix('CAAP', (int) $customer->business_id, $on);

        $application = DocumentNumber::claim(
            'number',
            fn () => DocumentNumber::next(CustomerAdvanceApplication::query(), 'number', $prefix),
            fn (string $number) => CustomerAdvanceApplication::create([
                'business_id' => $customer->business_id,
                'number' => $number,
                'customer_id' => $customer->id,
                'customer_advance_id' => $advance?->id,
                'sales_invoice_id' => $invoice->id,
                'applied_on' => $on->toDateString(),
                'amount' => $amount->toTaka(),
                'party_adjustment_id' => $adjustment->id,
                'source_note' => $advance === null ? CustomerAdvanceBook::onAccountSourceNote($customer, $amount) : null,
                'note' => $note,
                'created_by' => $by->id,
            ]),
        );

        $this->posting->forApplication($application, $by);

        return $application;
    }

    /**
     * Rows typed on a form (`key` = "advance:12" or "on_account") turned into
     * what handle() takes; an unknown advance is refused by name.
     *
     * @param  array<int, array{key: string, amount: mixed}>  $typed
     * @return array<int, array{advance: CustomerAdvance|null, amount: string}>
     *
     * @throws ValidationException
     */
    public static function rowsFrom(array $typed): array
    {
        $rows = [];

        foreach (array_values($typed) as $index => $row) {
            $key = (string) ($row['key'] ?? '');
            $amount = trim((string) ($row['amount'] ?? ''));

            if ($amount === '' || (float) $amount <= 0) {
                continue;
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
                throw ValidationException::withMessages(["advances.{$index}.amount" => 'Enter the amount in taka (for example 5000 or 5000.50).']);
            }

            if ($key === 'on_account') {
                $rows[] = ['advance' => null, 'amount' => $amount];

                continue;
            }

            $advance = preg_match('/^advance:(\d+)$/', $key, $m) === 1 ? CustomerAdvance::query()->find((int) $m[1]) : null;

            if ($advance === null) {
                throw ValidationException::withMessages(["advances.{$index}.amount" => 'That advance is not in this business.']);
            }

            $rows[] = ['advance' => $advance, 'amount' => $amount];
        }

        return $rows;
    }
}
