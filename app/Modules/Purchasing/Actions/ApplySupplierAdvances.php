<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Uses a supplier's advances against one of its bills (ruling P2, 18 Oct 2026).
 *
 * For each advance used, one application document (SAAP-...):
 *   Dr 2110 Accounts Payable (party = supplier)  Cr 1160 Advances to Suppliers (party)
 * and an `advance_applied` party adjustment on the bill, which is how the
 * bill's outstanding, the ageing, the statements and the AP reconciliation all
 * see it without learning a new table (the set-off's pattern).
 *
 * ⚠⚠ NEVER APPLIED TWICE. The supplier row is locked first, so two clerks
 * applying this supplier's advances at once queue behind each other, and every
 * advance and the bill are RE-READ inside the transaction after the lock: the
 * open figures are recomputed from what is committed, not from the form.
 * `lockForUpdate` is a no-op on SQLite, which is why the re-read is the rule
 * and the lock its companion (the I3 lesson, as in RecordSupplierPayment).
 *
 * ⚠ Dated the bill's date or later, never before the advance existed, never in
 * the future, never in a closed month.
 */
class ApplySupplierAdvances
{
    public function __construct(private readonly PostSupplierAdvanceJournals $posting) {}

    /**
     * @param  array<int, array{advance: SupplierAdvance|null, amount: string}>  $rows  advance null = the on-account balance
     * @return Collection<int, SupplierAdvanceApplication>
     *
     * @throws ValidationException
     */
    public function handle(SupplierBill $bill, array $rows, mixed $appliedOn, User $by, ?string $note = null): Collection
    {
        $on = Carbon::parse($appliedOn)->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['applied_on' => 'An advance cannot be applied on a future date.']);
        }

        if ($on->lt($bill->bill_date->copy()->startOfDay())) {
            throw ValidationException::withMessages(['applied_on' => 'Apply an advance on the bill date or later.']);
        }

        AccountingPeriod::assertOpenFor($on);

        $rows = array_values(array_filter($rows, fn (array $row) => Money::ofTaka($row['amount'])->minor > 0));

        if ($rows === []) {
            throw ValidationException::withMessages(['advances' => 'Say how much of which advance to use.']);
        }

        return DB::transaction(function () use ($bill, $rows, $on, $by, $note) {
            $supplier = Supplier::query()->whereKey($bill->supplier_id)->lockForUpdate()->firstOrFail();
            $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($bill->isReversed()) {
                throw ValidationException::withMessages(['advances' => "Bill {$bill->bill_number} is reversed."]);
            }

            $billLeft = $bill->outstanding()->minor;
            $claimed = [];
            $planned = [];

            foreach ($rows as $index => $row) {
                $amount = Money::ofTaka($row['amount']);
                $advance = $row['advance'] === null
                    ? null
                    : SupplierAdvance::query()->whereKey($row['advance']->id)->lockForUpdate()->firstOrFail();
                $key = SupplierAdvanceBook::keyOf($advance);
                $field = "advances.{$index}.amount";

                if ($advance !== null) {
                    if ($advance->supplier_id !== $supplier->id) {
                        throw ValidationException::withMessages([$field => "Advance {$advance->number} belongs to a different supplier."]);
                    }

                    if ($advance->isReversed()) {
                        throw ValidationException::withMessages([$field => "Advance {$advance->number} is reversed."]);
                    }

                    if ($on->lt($advance->paid_on->copy()->startOfDay())) {
                        throw ValidationException::withMessages(['applied_on' => "Advance {$advance->number} was paid after that date."]);
                    }
                }

                $open = ($advance === null ? SupplierAdvanceBook::onAccount($supplier) : $advance->open())->minor - ($claimed[$key] ?? 0);
                $name = $advance?->number ?? 'The on-account balance';

                if ($amount->minor > $open) {
                    throw ValidationException::withMessages([$field => "{$name} has only ".Money::ofMinor(max(0, $open))->format().' left to use.']);
                }

                if ($amount->minor > $billLeft) {
                    throw ValidationException::withMessages([$field => "Bill {$bill->bill_number} has only ".Money::ofMinor(max(0, $billLeft))->format().' outstanding.']);
                }

                $claimed[$key] = ($claimed[$key] ?? 0) + $amount->minor;
                $billLeft -= $amount->minor;
                $planned[] = [$advance, $amount];
            }

            $applications = collect();

            foreach ($planned as [$advance, $amount]) {
                $applications->push($this->applyOne($supplier, $bill, $advance, $amount, $on, $by, $note));
            }

            return $applications;
        });
    }

    private function applyOne(Supplier $supplier, SupplierBill $bill, ?SupplierAdvance $advance, Money $amount, Carbon $on, User $by, ?string $note): SupplierAdvanceApplication
    {
        $from = $advance?->number ?? 'on-account balance';

        $adjustment = PartyAdjustment::create([
            'business_id' => $supplier->business_id,
            'kind' => 'advance_applied',
            'adjusted_on' => $on->toDateString(),
            'supplier_id' => $supplier->id,
            'amount' => $amount->toTaka(),
            'reason' => "Advance {$from} applied to bill {$bill->bill_number}",
            'created_by' => $by->id,
        ]);
        $adjustment->allocations()->create([
            'business_id' => $adjustment->business_id,
            'supplier_bill_id' => $bill->id,
            'amount' => $amount->toTaka(),
        ]);

        $business = Business::withoutGlobalScopes()->findOrFail($supplier->business_id);
        $prefix = 'SAAP-'.FiscalYear::containing($on, $business)->label().'-';

        $application = DocumentNumber::claim(
            'number',
            fn () => DocumentNumber::next(SupplierAdvanceApplication::query(), 'number', $prefix),
            fn (string $number) => SupplierAdvanceApplication::create([
                'business_id' => $supplier->business_id,
                'number' => $number,
                'supplier_id' => $supplier->id,
                'supplier_advance_id' => $advance?->id,
                'supplier_bill_id' => $bill->id,
                'applied_on' => $on->toDateString(),
                'amount' => $amount->toTaka(),
                // Ruling P2: the tax this part of the advance already carried, so
                // nobody holds it back again on the same base.
                'tds_covered' => ($advance?->tdsOn($amount) ?? Money::zero())->toTaka(),
                'party_adjustment_id' => $adjustment->id,
                'source_note' => $advance === null ? SupplierAdvanceBook::onAccountSourceNote($supplier, $amount) : null,
                'note' => $note,
                'created_by' => $by->id,
            ]),
        );

        $this->posting->forApplication($application, $by);

        return $application;
    }
}
