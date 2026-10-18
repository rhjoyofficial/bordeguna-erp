<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\SetOffLine;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A set-off's way from the form to the books (decision D-F, 18 Oct 2026):
 *
 *   raise     Finance or the Owner names the party, the bills, the invoices
 *             and the amounts; it is numbered SET-2026-27-0001 and goes to the
 *             approval inbox (the voucher workflow). Nothing moves.
 *   approve   the last approval POSTS it through RecordPartyAdjustment
 *             (`set_off`): Dr 2110 AP (supplier) / Cr 1140 AR (customer), the
 *             bills and invoices reduced exactly as by a payment or a receipt
 *   reject    nothing moves; kept as Rejected for the record
 *
 * Reversal is NOT here: it is the corrections process (Corrections\SetOff*),
 * so a set-off is undone the way every posted money document is.
 *
 * ⚠⚠ THE SAME AMOUNT ON BOTH SIDES, and never more than either side still
 * owes. Every figure is checked when it is raised AND again, under a lock,
 * when it posts: a bill paid in between makes the approval fail with the
 * sentence, never post a set-off on money already settled.
 *
 * ⚠ The party must be ONE firm: the customer must be linked to the supplier
 * (customers.supplier_id). A set-off between two unrelated parties would move
 * one firm's debt onto another's account.
 */
class SetOffs
{
    public function __construct(
        private readonly RecordPartyAdjustment $adjustments,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /**
     * @param  array<int|string, string>  $invoices  sales_invoice_id => taka
     * @param  array<int|string, string>  $bills  supplier_bill_id => taka
     *
     * @throws ValidationException
     */
    public function raise(Supplier $supplier, Customer $customer, mixed $date, array $invoices, array $bills, string $reason, User $by): SetOff
    {
        $on = Carbon::parse($date)->startOfDay();
        $reason = trim($reason);

        if ((int) $customer->supplier_id !== (int) $supplier->id) {
            throw ValidationException::withMessages(['customer_id' => "{$customer->name} is not linked to the supplier {$supplier->name}. Link them as one party first (supplier or customer page)."]);
        }

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['set_off_on' => 'A set-off cannot be dated in the future.']);
        }

        AccountingPeriod::assertOpenFor($on);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why: the reason is part of the record.']);
        }

        $invoices = $this->amounts($invoices);
        $bills = $this->amounts($bills);

        if ($invoices === [] || $bills === []) {
            throw ValidationException::withMessages(['bills' => 'Choose at least one open bill and one open invoice, with the amount to clear on each.']);
        }

        $this->assertWorkflow($supplier);

        $setOff = DB::transaction(function () use ($supplier, $customer, $on, $invoices, $bills, $reason, $by) {
            $total = $this->checkAgainstOpen($supplier, $customer, $invoices, $bills);

            $business = Business::withoutGlobalScopes()->findOrFail($supplier->business_id);
            $prefix = 'SET-'.FiscalYear::containing($on, $business)->label().'-';

            $setOff = DocumentNumber::claim(
                'number',
                fn () => DocumentNumber::next(SetOff::query(), 'number', $prefix),
                fn (string $number) => SetOff::create([
                    'business_id' => $supplier->business_id,
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'customer_id' => $customer->id,
                    'set_off_on' => $on->toDateString(),
                    'amount' => $total->toTaka(),
                    'reason' => $reason,
                    'status' => SetOff::PENDING,
                    'created_by' => $by->id,
                ]),
            );

            foreach ($invoices as $id => $money) {
                $setOff->lines()->create(['business_id' => $setOff->business_id, 'sales_invoice_id' => $id, 'amount' => $money->toTaka()]);
            }

            foreach ($bills as $id => $money) {
                $setOff->lines()->create(['business_id' => $setOff->business_id, 'supplier_bill_id' => $id, 'amount' => $money->toTaka()]);
            }

            activity()->performedOn($setOff)->causedBy($by)->log("Raised set-off {$setOff->number} for {$supplier->name} ({$total->format()})");

            $setOff->submitForApproval($by);

            return $setOff;
        });

        $this->notify->handle($setOff->approvalRequest()->firstOrFail(), "Set-off {$setOff->number} ({$supplier->name}, {$setOff->money()->format()}) is waiting for your approval");

        return $setOff->fresh('lines');
    }

    /** @return bool whether it is now posted (false: it moved to a later stage) */
    public function approve(SetOff $setOff, User $by, ?string $note = null): bool
    {
        $approval = $this->pendingRequest($setOff, $by);

        return DB::transaction(function () use ($setOff, $approval, $by, $note) {
            if (! $approval->approve($by, $note)) {
                return false;
            }

            $this->post($setOff, $by, $note);

            return true;
        });
    }

    public function reject(SetOff $setOff, User $by, ?string $note): void
    {
        $approval = $this->pendingRequest($setOff, $by);

        DB::transaction(function () use ($setOff, $approval, $by, $note) {
            $approval->reject($by, $note);
            $setOff->update(['status' => SetOff::REJECTED, 'decision_note' => $note]);
            activity()->performedOn($setOff)->causedBy($by)->withProperties(['note' => $note])->log("Rejected set-off {$setOff->number}");
        });
    }

    /**
     * The posting, inside the approval's transaction: every figure re-read
     * under a lock by RecordPartyAdjustment, which refuses an amount above
     * what a bill or an invoice still owes.
     */
    private function post(SetOff $setOff, User $by, ?string $note): void
    {
        $setOff = SetOff::query()->lockForUpdate()->with(['lines', 'supplier', 'customer'])->findOrFail($setOff->id);

        if (! $setOff->isPending()) {
            throw ValidationException::withMessages(['set_off' => "{$setOff->number} is no longer waiting for approval."]);
        }

        if ((int) $setOff->customer->supplier_id !== (int) $setOff->supplier_id) {
            throw ValidationException::withMessages(['set_off' => "{$setOff->customer->name} is no longer linked to {$setOff->supplier->name}, so {$setOff->number} cannot post."]);
        }

        $invoices = $setOff->lines->whereNotNull('sales_invoice_id')->mapWithKeys(fn (SetOffLine $line) => [$line->sales_invoice_id => $line->money()->toTaka()])->all();
        $bills = $setOff->lines->whereNotNull('supplier_bill_id')->mapWithKeys(fn (SetOffLine $line) => [$line->supplier_bill_id => $line->money()->toTaka()])->all();

        $adjustment = $this->adjustments->handle(
            'set_off', $setOff->set_off_on->toDateString(), $setOff->customer, $setOff->supplier,
            $invoices, $bills, "{$setOff->number}: {$setOff->reason}", $by, source: $setOff,
        );

        $journal = Journal::query()
            ->where('source_type', $setOff->getMorphClass())->where('source_id', $setOff->getKey())
            ->whereNull('reverses_journal_id')->latest('id')->firstOrFail();

        $setOff->update([
            'status' => SetOff::POSTED,
            'party_adjustment_id' => $adjustment->id,
            'journal_id' => $journal->id,
            'posted_by' => $by->id,
            'posted_at' => now(),
            'decision_note' => $note,
        ]);

        activity()->performedOn($setOff)->causedBy($by)->log("Approved and posted set-off {$setOff->number} as {$journal->journal_number}");
    }

    /**
     * Each invoice is the customer's and each bill the supplier's; no amount
     * is above what the document owes after the set-offs already waiting on
     * it; both sides are the same total.
     *
     * @param  array<int, Money>  $invoices
     * @param  array<int, Money>  $bills
     */
    private function checkAgainstOpen(Supplier $supplier, Customer $customer, array $invoices, array $bills): Money
    {
        $customerSide = Money::zero();
        $supplierSide = Money::zero();

        foreach ($invoices as $id => $money) {
            $invoice = SalesInvoice::query()->whereKey($id)->lockForUpdate()->first();

            if ($invoice === null || (int) $invoice->customer_id !== (int) $customer->id) {
                throw ValidationException::withMessages(['invoices' => 'An invoice chosen does not belong to this customer.']);
            }

            $open = Money::ofMinor($invoice->outstanding()->minor - $this->waiting('sales_invoice_id', $invoice->id)->minor);

            if ($money->minor > $open->minor) {
                throw ValidationException::withMessages(['invoices' => "Invoice {$invoice->invoice_number} has only {$open->format()} open to set off."]);
            }

            $customerSide = $customerSide->plus($money);
        }

        foreach ($bills as $id => $money) {
            $bill = SupplierBill::query()->whereKey($id)->lockForUpdate()->first();

            if ($bill === null || (int) $bill->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages(['bills' => 'A bill chosen does not belong to this supplier.']);
            }

            $open = Money::ofMinor($bill->outstanding()->minor - $this->waiting('supplier_bill_id', $bill->id)->minor);

            if ($money->minor > $open->minor) {
                throw ValidationException::withMessages(['bills' => "Bill {$bill->bill_number} has only {$open->format()} open to set off."]);
            }

            $supplierSide = $supplierSide->plus($money);
        }

        if ($customerSide->minor !== $supplierSide->minor) {
            throw ValidationException::withMessages([
                'bills' => "A set-off clears the same amount on both sides: the invoices come to {$customerSide->format()}, the bills to {$supplierSide->format()}.",
            ]);
        }

        return $customerSide;
    }

    /** What set-offs still waiting for approval already claim on one document. */
    private function waiting(string $column, int $id): Money
    {
        $sum = SetOffLine::query()->where($column, $id)
            ->whereIn('set_off_id', SetOff::query()->where('status', SetOff::PENDING)->select('id'))
            ->sum('amount');

        return Money::ofTaka((string) ($sum ?: '0'));
    }

    /**
     * @param  array<int|string, mixed>  $amounts
     * @return array<int, Money>
     */
    private function amounts(array $amounts): array
    {
        $clean = [];

        foreach ($amounts as $id => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }

            if (! is_numeric($amount) || (float) $amount < 0) {
                throw ValidationException::withMessages(['bills' => 'Each amount is a number of taka, zero or more.']);
            }

            $money = Money::ofTaka((string) $amount);

            if ($money->minor > 0) {
                $clean[(int) $id] = $money;
            }
        }

        return $clean;
    }

    /** Refuses a set-off that no workflow would carry to anybody. */
    private function assertWorkflow(Supplier $supplier): void
    {
        $exists = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $supplier->business_id)->where('key', (new SetOff)->approvalWorkflowKey())
            ->where('is_active', true)->whereHas('stages')->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['set_off' => 'Nobody is set up to approve set-offs yet. Ask the administrator to run the voucher approval setup.']);
        }
    }

    private function pendingRequest(SetOff $setOff, User $by): ApprovalRequest
    {
        $approval = $setOff->approvalRequest;

        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        return $approval;
    }
}
