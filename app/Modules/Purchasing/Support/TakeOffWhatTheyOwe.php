<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Accounting\Actions\SetOffs;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\SetOffLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Http\Controllers\SupplierAdvanceController;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐⭐ A purchase taken off EVERYTHING the firm owes us (Joy, 26 Oct 2026:
 * "from now purchsed amount will be deduct from total owes money not only from
 * Allocate advance ... from total 57,331 not 17,400.00").
 *
 * When the supplier is also our customer (customers.supplier_id), what they
 * owe us is two things: our advance still with them (1160) and their open
 * sales invoices (1140). The person types ONE amount; it is taken
 *
 *   1. from our advances, oldest first (ApplySupplierAdvances, exactly as the
 *      "Allocate advance" box does), then
 *   2. from their open invoices, oldest first, as a SET-OFF of this bill
 *      against those invoices (SetOffs::raise, the voucher workflow).
 *
 * ⚠ The set-off is approved on the spot only when the person may approve it
 * (the voucher workflow's one stage: the Owner, or whoever may post
 * vouchers). It is then a SELF-approval (Rule #22: allowed with a reason), and
 * the reason kept in the approval history is the set-off's own reason, said
 * as such. Anyone else's set-off waits in the approval inbox and the bill
 * stays owed until it is approved; the screen says so.
 *
 * ⚠⚠ All or nothing, inside the caller's transaction: a refused advance or a
 * refused set-off rolls the whole purchase back. Never more than the bill
 * owes, never more than they owe in all; every figure is re-read under a lock
 * by the two actions it calls.
 */
final class TakeOffWhatTheyOwe
{
    /**
     * What the purchase screens show for one supplier.
     *
     * @return array{linked: bool, customer: array{id: int, name: string}|null, advances: list<array<string, mixed>>, invoices: list<array{id: int, number: string, date: ?string, open: string}>, advance_total: string, invoice_total: string, total: string, may_set_off: bool, posts_now: bool}
     */
    public static function forSupplier(Supplier $supplier, User $user): array
    {
        $customer = self::customerOf($supplier);
        $advances = SupplierAdvanceBook::orderedFor($supplier, null)
            ->map(fn (array $row) => SupplierAdvanceController::availableRow($row))->values();
        $invoices = $customer === null ? collect() : self::openInvoices($customer);

        $advanceTotal = Money::sum($advances->map(fn (array $row) => Money::ofTaka($row['open'])));
        $invoiceTotal = Money::sum($invoices->map(fn (array $row) => $row['open']));

        return [
            'linked' => $customer !== null,
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name],
            'advances' => $advances->all(),
            'invoices' => $invoices->map(fn (array $row) => [
                'id' => $row['invoice']->id,
                'number' => $row['invoice']->invoice_number,
                'date' => $row['invoice']->invoice_date?->toDateString(),
                'open' => $row['open']->toTaka(),
            ])->values()->all(),
            'advance_total' => $advanceTotal->toTaka(),
            'invoice_total' => $invoiceTotal->toTaka(),
            'total' => $advanceTotal->plus($invoiceTotal)->toTaka(),
            'may_set_off' => $user->can('accounting.set_off.manage'),
            'posts_now' => self::postsNow($supplier, $user),
        ];
    }

    /**
     * Takes `$amount` off the bill: advances first, then invoices. Call it
     * inside the purchase's transaction, after the bill has posted.
     *
     * @return array{advance: Money, set_off: SetOff|null, posted: bool}
     *
     * @throws ValidationException
     */
    public function apply(SupplierBill $bill, Money $amount, string $on, User $by): array
    {
        if ($amount->minor <= 0) {
            return ['advance' => Money::zero(), 'set_off' => null, 'posted' => false];
        }

        $bill = $bill->fresh('supplier');
        $supplier = $bill->supplier;

        if ($amount->minor > $bill->outstanding()->minor) {
            throw ValidationException::withMessages(['take_off' => "Bill {$bill->bill_number} owes only {$bill->outstanding()->format()}; take off no more than that."]);
        }

        $split = self::split($supplier, $amount);

        if ($split['advances'] !== []) {
            app(ApplySupplierAdvances::class)->handle($bill, SupplierAdvanceController::resolveRows($split['advances']), $on, $by);
        }

        if ($split['invoices'] === []) {
            return ['advance' => $split['advance'], 'set_off' => null, 'posted' => false];
        }

        if (! $by->can('accounting.set_off.manage')) {
            throw ValidationException::withMessages(['take_off' => "{$split['invoice']->format()} of this would come off their sales invoices, which is a set-off, and your role may not raise one. Take off no more than our advance ({$split['advance']->format()}), or ask Finance or the Owner."]);
        }

        $customer = $split['customer'];
        $reason = "Purchase {$bill->bill_number} taken off what {$customer->name} owes us";
        $sets = app(SetOffs::class);

        $setOff = $sets->raise(
            $supplier, $customer, $on,
            collect($split['invoices'])->mapWithKeys(fn (array $row) => [$row['id'] => $row['amount']->toTaka()])->all(),
            [$bill->id => $split['invoice']->toTaka()],
            $reason, $by, notify: false,
        );

        $posted = false;

        if ($setOff->approvalRequest()->firstOrFail()->canBeActionedBy($by)) {
            // ⚠ A self-approval (Rule #22): the note is the reason it is kept with.
            $posted = $sets->approve($setOff, $by, "Approved as it was entered, with the purchase: {$reason}.");
        }

        if (! $posted) {
            DB::afterCommit(fn () => $sets->notifyApprovers($setOff->fresh()));
        }

        return ['advance' => $split['advance'], 'set_off' => $setOff->fresh('lines'), 'posted' => $posted];
    }

    /**
     * The one amount split: our advances oldest first, then their invoices
     * oldest first.
     *
     * @return array{advances: list<array{key: string, amount: string}>, invoices: list<array{id: int, number: string, amount: Money}>, advance: Money, invoice: Money, customer: Customer|null}
     *
     * @throws ValidationException
     */
    public static function split(Supplier $supplier, Money $amount): array
    {
        $customer = self::customerOf($supplier);
        $left = $amount->minor;
        $advances = [];
        $invoices = [];
        $advanceTotal = 0;
        $invoiceTotal = 0;

        foreach (SupplierAdvanceBook::orderedFor($supplier, null) as $row) {
            $take = min($left, $row['open']->minor);

            if ($take > 0) {
                $advances[] = ['key' => SupplierAdvanceBook::keyOf($row['advance']), 'amount' => Money::ofMinor($take)->toTaka()];
                $advanceTotal += $take;
                $left -= $take;
            }
        }

        foreach ($customer === null ? [] : self::openInvoices($customer) as $row) {
            $take = min($left, $row['open']->minor);

            if ($take > 0) {
                $invoices[] = ['id' => $row['invoice']->id, 'number' => $row['invoice']->invoice_number, 'amount' => Money::ofMinor($take)];
                $invoiceTotal += $take;
                $left -= $take;
            }
        }

        if ($left > 0) {
            $all = Money::ofMinor($advanceTotal + $invoiceTotal);

            throw ValidationException::withMessages(['take_off' => $customer === null
                ? "{$supplier->name} holds only {$all->format()} of our advance; take off no more than that."
                : "{$supplier->name} owes us only {$all->format()} in all (our advance and their open invoices); take off no more than that."]);
        }

        return [
            'advances' => $advances,
            'invoices' => $invoices,
            'advance' => Money::ofMinor($advanceTotal),
            'invoice' => Money::ofMinor($invoiceTotal),
            'customer' => $customer,
        ];
    }

    /**
     * What came off one bill, for the result and bill pages: each advance
     * used and each set-off against it, with its state.
     *
     * @return list<array{kind: string, number: string, amount: string, state: string, invoices: list<string>, url: ?string}>
     */
    public static function takenOff(SupplierBill $bill): array
    {
        $applications = SupplierAdvanceApplication::query()->where('supplier_bill_id', $bill->id)->live()
            ->with('advance:id,number')->orderBy('id')->get()
            ->map(fn (SupplierAdvanceApplication $application) => [
                'kind' => 'advance',
                'number' => $application->advance?->number ?? 'On account',
                'amount' => $application->money()->toTaka(),
                'state' => 'applied',
                'invoices' => [],
                'url' => $application->supplier_advance_id === null ? null : route('purchasing.advances.show', $application->supplier_advance_id),
            ]);

        $setOffs = SetOff::query()
            ->whereIn('id', SetOffLine::query()->where('supplier_bill_id', $bill->id)->select('set_off_id'))
            ->with('lines.invoice:id,invoice_number')->orderBy('id')->get()
            ->map(fn (SetOff $setOff) => [
                'kind' => 'set_off',
                'number' => $setOff->number,
                'amount' => Money::ofTaka((string) $setOff->lines->firstWhere('supplier_bill_id', $bill->id)?->amount)->toTaka(),
                'state' => $setOff->status,
                'invoices' => $setOff->lines->whereNotNull('sales_invoice_id')->map(fn (SetOffLine $line) => (string) $line->invoice?->invoice_number)->values()->all(),
                'url' => route('accounting.set-offs.show', $setOff),
            ]);

        return [...$applications->all(), ...$setOffs->all()];
    }

    /**
     * One sentence for the flash message: "6,900.00 from our advance;
     * 3,000.00 off INV-2026-27-0002 by set-off SET-2026-27-0001 (posted)."
     * Empty when nothing came off.
     *
     * @param  list<array{kind: string, number: string, amount: string, state: string, invoices: list<string>}>  $takenOff
     */
    public static function sentence(array $takenOff): string
    {
        $advance = Money::sum(array_map(fn (array $row) => Money::ofTaka($row['amount']), array_filter($takenOff, fn (array $row) => $row['kind'] === 'advance')));
        $parts = $advance->minor > 0 ? ["{$advance->format()} from our advance"] : [];

        foreach (array_filter($takenOff, fn (array $row) => $row['kind'] === 'set_off' && in_array($row['state'], [SetOff::PENDING, SetOff::POSTED], true)) as $row) {
            $parts[] = Money::ofTaka($row['amount'])->format().' off '.implode(', ', $row['invoices'])." by set-off {$row['number']}"
                .($row['state'] === SetOff::POSTED ? '' : ' (waiting for approval: the bill still owes it until then)');
        }

        return $parts === [] ? '' : ucfirst(implode('; ', $parts)).'.';
    }

    private static function customerOf(Supplier $supplier): ?Customer
    {
        return Customer::query()->where('supplier_id', $supplier->id)->first();
    }

    /**
     * Their open invoices oldest first, each less what set-offs still waiting
     * for approval already claim on it (SetOffs' own rule).
     *
     * @return Collection<int, array{invoice: SalesInvoice, open: Money}>
     */
    private static function openInvoices(Customer $customer): Collection
    {
        $invoices = SalesInvoice::query()->where('customer_id', $customer->id)->unsettled()->withOutstandingPaisa()
            ->orderBy('invoice_date')->orderBy('id')->get();

        $waiting = SetOffLine::query()->whereIn('sales_invoice_id', $invoices->pluck('id'))
            ->whereIn('set_off_id', SetOff::query()->where('status', SetOff::PENDING)->select('id'))
            ->get(['sales_invoice_id', 'amount'])
            ->groupBy('sales_invoice_id')
            ->map(fn ($lines) => Money::sum($lines->map(fn (SetOffLine $line) => Money::ofTaka((string) $line->amount)))->minor);

        return $invoices
            ->map(fn (SalesInvoice $invoice) => ['invoice' => $invoice, 'open' => Money::ofMinor((int) $invoice->outstanding_paisa - (int) ($waiting[$invoice->id] ?? 0))])
            ->filter(fn (array $row) => $row['open']->minor > 0)
            ->values();
    }

    /** Whether a set-off this person raises would post at once (they are its one approver). */
    private static function postsNow(Supplier $supplier, User $user): bool
    {
        if (! $user->can('accounting.set_off.manage')) {
            return false;
        }

        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $supplier->business_id)->where('key', (new SetOff)->approvalWorkflowKey())
            ->where('is_active', true)->with('stages')->first();

        return $workflow !== null && $workflow->stages->count() === 1 && $workflow->stages->first()->allows($user);
    }
}
