<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\Loans\RecordLoanLinesFromJournal;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Models\VoucherLine;
use App\Modules\Accounting\Support\CounterpartyRules;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Loans\LoanLines;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Accounting\Support\PartyBillWise;
use App\Modules\Accounting\Support\PartyHeads;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\RecordSupplierRefund;
use App\Modules\Purchasing\Actions\RefundSupplierAdvance;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a draft voucher into a posted one: a number, one journal, no way back.
 *
 * ⚠⚠ THE ONLY PLACE A VOUCHER MEETS THE LEDGER, and it meets it through
 * PostJournal like every other document. No voucher type has posting rules of
 * its own; the four differ only in how the lines below are laid out:
 *
 *   Payment   Dr each line              Cr the money account (total)
 *   Receipt   Dr the money account      Cr each line
 *   Contra    Dr the receiving account  Cr the sending account
 *   Journal   each line on its own side; must balance
 *
 * ⚠ Everything a draft was allowed to leave unfinished is insisted on here.
 */
class PostVoucher
{
    /**
     * What each customer or supplier line settles, planned while the lines are
     * laid out and created once the voucher is posted.
     *
     * @var list<array{kind: string, party: Customer|Supplier, allocations: list<array<string, mixed>>}>
     */
    private array $settlements = [];

    /** @var array<string, int> paisa already planned against an invoice, bill or refundable in this voucher, keyed "party:ref" */
    private array $planned = [];

    /**
     * ⭐ The refunds a supplier line on a Receipt voucher or a customer line
     * on a Payment voucher stands for (party ledgers, 20 Oct 2026), created
     * BEFORE the voucher's journal, because their caps read the ledger as it
     * stands without this voucher.
     *
     * @var list<array{kind: string, party: Customer|Supplier, document: ?Model, amount: Money}>
     */
    private array $refunds = [];

    /** Paisa of TDS and VDS held back on this voucher's supplier lines: the money account pays the rest. */
    private int $withheld = 0;

    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly RecordCustomerReceipt $recordReceipt,
        private readonly RecordSupplierPayment $recordPayment,
        private readonly RecordLoanLinesFromJournal $recordLoanLines,
        private readonly RecordSupplierRefund $recordSupplierRefund,
        private readonly RefundSupplierAdvance $refundSupplierAdvance,
        private readonly RecordCustomerRefund $recordCustomerRefund,
        private readonly RecordPartyAdjustment $recordAdjustment,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, User $by): Voucher
    {
        return DB::transaction(function () use ($voucher, $by) {
            // ⚠ Locked and re-read: two people pressing Post on one draft must
            // produce one journal, not two.
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);

            // ⚠ A voucher awaiting approval posts only once that approval is
            // complete (SubmitOrPostVoucher); until then it touches nothing.
            $approved = $voucher->isPendingApproval() && $voucher->approvalRequest?->isComplete();

            if (! $voucher->isDraft() && ! $approved) {
                throw ValidationException::withMessages([
                    'voucher' => $voucher->isPendingApproval()
                        ? "{$voucher->title()} is waiting for a second person's approval."
                        : "{$voucher->title()} is already posted.",
                ]);
            }

            $voucher->load(['lines.account', 'lines.party', 'moneyAccount', 'toMoneyAccount']);

            $date = $voucher->voucher_date->copy()->startOfDay();
            AccountingPeriod::assertOpenFor($date);

            if ($date->isFuture()) {
                throw ValidationException::withMessages(['voucher_date' => 'A voucher cannot be posted with a future date.']);
            }

            $this->settlements = [];
            $this->refunds = [];
            $this->withheld = 0;

            // ⭐ A customer or supplier on a JOURNAL voucher is a write-off,
            // discount or set-off: posted as the party adjustment it is, with
            // the journal look (party ledgers, 20 Oct 2026).
            if ($voucher->type === VoucherType::Journal && $this->namesTradeParty($voucher)) {
                return $this->postAdjustment($voucher, $by, $date);
            }

            $lines = $this->journalLines($voucher);

            // ⚠ The number is taken only now, after every refusal above, so a
            // voucher that fails to post burns no number.
            $type = $voucher->type;
            $number = $type === VoucherType::Journal ? null : $this->nextNumber($voucher);

            // ⭐ Refunds first (party ledgers): each engine checks its own cap
            // against the ledger before this voucher's journal moves it.
            $this->refund($voucher, $number, $by);

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $date,
                lines: $lines,
                memo: $this->memo($voucher, $number),
                source: $voucher,
            ), $by);

            $voucher->update([
                // A journal voucher IS its journal: it carries the journal's own
                // JV number rather than a second JV stream beside it.
                'voucher_number' => $number ?? $journal->journal_number,
                'status' => VoucherStatus::Posted,
                'journal_id' => $journal->id,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ]);

            // The receipts and payments a customer or supplier line stands for,
            // created now the voucher has its number (finance decision C3).
            $this->settle($voucher, $by);

            // ⭐ A line naming a register loan moves that loan (loans plan v2,
            // phase 2): recorded in the Loan Register, linked to this journal once.
            $this->recordLoanLines->handle($journal, $by);

            return $voucher->fresh(['lines', 'journal']);
        });
    }

    /**
     * @return array<int, JournalLineDraft>
     */
    private function journalLines(Voucher $voucher): array
    {
        $type = $voucher->type;
        $date = $voucher->voucher_date->copy()->startOfDay();

        if ($type === VoucherType::Contra) {
            // ⭐ A gateway may be a contra's SOURCE: its payout reaching the bank
            // (accountant meeting 19 Sep 2026, F4). It has no statement flow of
            // its own, unlike a courier, so without this the payout had no screen.
            $from = $this->usable($voucher->moneyAccount, 'money_account_id', $date, allowGateway: true);
            $to = $this->usable($voucher->toMoneyAccount, 'to_money_account_id', $date);

            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to_money_account_id' => 'Money cannot be moved into the account it came from.']);
            }

            $amount = $voucher->total();

            if ($amount->minor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount that was moved.']);
            }

            MoneyAvailability::assertCanPay($from, $amount, $date, 'money_account_id');

            return [
                JournalLineDraft::debit($to->account_id, $amount, $voucher->narration ?? "From {$from->label()}", null, $to),
                JournalLineDraft::credit($from->account_id, $amount, $voucher->narration ?? "To {$to->label()}", null, $from),
            ];
        }

        $rows = $voucher->lines->filter(fn ($line) => $line->amount_minor > 0)->values();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'A voucher needs at least one line with an amount.']);
        }

        $lines = [];

        $this->planned = [];

        foreach ($rows as $index => $row) {
            if (($refusal = VoucherAccounts::refusal($row->account, $date, $type)) !== null) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => $refusal]);
            }

            $person = $row->account->is_postable ? null : PartyHeads::entityFor($row->account);

            if ($person !== null) {
                array_push($lines, ...$this->personLines($person, $row, $voucher, $date, $index));

                continue;
            }

            if ($row->tds_rate_id !== null || $row->vds_rate_id !== null) {
                throw ValidationException::withMessages(["lines.{$index}.tds_rate_id" => 'Tax is held back only on a line paying a supplier.']);
            }

            $partyClass = VoucherAccounts::partyClassFor($row->account);

            if ($partyClass !== null && $row->party === null) {
                // A loan head is named in full: "Say which counterparty this
                // 2210 - Bank Loans line is for." (loans plan v2, 14 Oct 2026).
                if ($partyClass === Counterparty::class) {
                    throw ValidationException::withMessages(["lines.{$index}.party_id" => "Say which counterparty this {$row->account->label()} line is for."]);
                }

                $who = PartyLedgers::noun($partyClass);
                $what = $row->account->system_slug === 'employee_deductions_payable' ? 'deduction' : 'advance';

                throw ValidationException::withMessages(["lines.{$index}.party_id" => "Say which {$who} this {$what} belongs to."]);
            }

            if ($row->party instanceof Counterparty && ($refusal = CounterpartyRules::refusal($row->account, $row->party)) !== null) {
                throw ValidationException::withMessages(["lines.{$index}.party_id" => $refusal]);
            }

            // ⚠ The Loan Register's rule, as a sentence on the line's own picker
            // (PostJournal checks it again for every writer).
            if (($refusal = LoanLines::refusal($row->account, $row->party, $row->loan_contract_id === null ? null : (int) $row->loan_contract_id, (int) $voucher->business_id)) !== null) {
                throw ValidationException::withMessages(["lines.{$index}.loan_contract_id" => $refusal]);
            }

            $loanId = $row->loan_contract_id === null ? null : (int) $row->loan_contract_id;

            $lines[] = $row->isDebit()
                ? JournalLineDraft::debit($row->account_id, $row->amount(), $row->narration ?? $voucher->narration, $row->party, null, $loanId)
                : JournalLineDraft::credit($row->account_id, $row->amount(), $row->narration ?? $voucher->narration, $row->party, null, $loanId);
        }

        if ($type === VoucherType::Journal) {
            if ($rows->count() < 2) {
                throw ValidationException::withMessages(['lines' => 'A journal voucher needs at least two lines.']);
            }

            $debits = $rows->filter->isDebit()->sum('amount_minor');
            $credits = $rows->reject->isDebit()->sum('amount_minor');

            if ($debits !== $credits) {
                $gap = Money::ofMinor(abs($debits - $credits))->format();

                throw ValidationException::withMessages(['lines' => "Debits and credits differ by {$gap}. A journal voucher must balance."]);
            }

            return $lines;
        }

        $money = $this->usable($voucher->moneyAccount, 'money_account_id', $date);
        // ⭐ TDS / VDS held back on supplier lines go to NBR, not out of the
        // bank: the money account moves the rest (finance decision C4).
        $total = Money::ofMinor((int) $rows->sum('amount_minor') - $this->withheld);
        $label = trim($money->label().($voucher->counterparty ? " - {$voucher->counterparty}" : ''));

        if ($type === VoucherType::Payment) {
            $this->assertCashLimit($voucher, $money, Money::ofMinor((int) $rows->sum('amount_minor')));
            MoneyAvailability::assertCanPay($money, $total, $date, 'money_account_id');
        }

        $lines[] = $type === VoucherType::Payment
            ? JournalLineDraft::credit($money->account_id, $total, $label, null, $money)
            : JournalLineDraft::debit($money->account_id, $total, $label, null, $money);

        return $lines;
    }

    private function usable(?MoneyAccount $account, string $field, mixed $date, bool $allowGateway = false): MoneyAccount
    {
        if ($account === null) {
            throw ValidationException::withMessages([$field => 'Choose the cash, bank or mobile account.']);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([$field => "{$account->name} is no longer active."]);
        }

        if ($account->isCourier()) {
            throw ValidationException::withMessages([$field => "{$account->name} is settled by its own statements, not by a voucher."]);
        }

        if ($account->isGateway() && ! $allowGateway) {
            throw ValidationException::withMessages([$field => "{$account->name} holds money from online sales. It can only be the account a contra voucher moves money OUT of, when its payout reaches the bank."]);
        }

        if ($account->account_id === null) {
            throw MappingNotFound::for("money account [{$account->name}]", $date);
        }

        return $account;
    }

    /**
     * A line picked by a person's own head, as it reaches the ledger.
     *
     * ⚠⚠ AR and AP stay document-backed: a customer's money is a real receipt
     * against open invoices, oldest first, and only what no invoice is waiting
     * for becomes an advance (2150). Suppliers mirror it (bills, then 1160). An
     * employee is simply their advances ledger, 1170.
     *
     * ⭐ 20 Oct 2026 (party ledgers, Tally's bill-wise details): the person may
     * say which bill takes how much (`bill_wise`); a supplier on a Receipt
     * voucher pays back what they owe us (SREF) or an advance (SARF); a
     * customer on a Payment voucher is refunded their advance, a return's
     * credit or an order's money. See PartyBillWise.
     *
     * @return list<JournalLineDraft>
     */
    private function personLines(Model $person, VoucherLine $row, Voucher $voucher, Carbon $date, int $index): array
    {
        $narration = $row->narration ?? $voucher->narration;
        $amount = $row->amount();
        $mode = PartyBillWise::mode($voucher->type, $person);

        if ($mode !== PartyBillWise::SUPPLIER_PAYMENT && ($row->tds_rate_id !== null || $row->vds_rate_id !== null)) {
            throw ValidationException::withMessages(["lines.{$index}.tds_rate_id" => 'Tax is held back only on a line paying a supplier.']);
        }

        if ($person instanceof Employee || $mode === null) {
            $advances = Account::bySlug('employee_advances')->id;

            return [$row->isDebit()
                ? JournalLineDraft::debit($advances, $amount, $narration, $person)
                : JournalLineDraft::credit($advances, $amount, $narration, $person)];
        }

        // ⚠ Planned per party: one voucher naming a party on two lines must
        // never settle the same bill twice.
        $prefix = $person->getMorphClass().':'.$person->getKey().':';
        $planned = [];

        foreach ($this->planned as $key => $minor) {
            if (str_starts_with($key, $prefix)) {
                $planned[substr($key, strlen($prefix))] = $minor;
            }
        }

        $items = PartyBillWise::items($mode, $person, $date);
        $field = "lines.{$index}.bill_wise";
        ['allocations' => $allocations, 'rest' => $rest] = PartyBillWise::allocate($items, $row->bill_wise, $amount->minor, $planned, $field);

        foreach ($planned as $ref => $minor) {
            $this->planned[$prefix.$ref] = $minor;
        }

        return match ($mode) {
            PartyBillWise::SUPPLIER_PAYMENT => $this->supplierPaymentLines($person, $row, $allocations, $rest, $date, $narration, $index),
            PartyBillWise::CUSTOMER_RECEIPT => $this->customerReceiptLines($person, $allocations, $rest, $date, $narration),
            PartyBillWise::SUPPLIER_REFUND => $this->supplierRefundLines($person, $items, $allocations, $rest, $date, $narration, $field),
            PartyBillWise::CUSTOMER_REFUND => $this->customerRefundLines($person, $items, $allocations, $rest, $date, $narration, $field),
        };
    }

    /**
     * Dr 2110 for the bills, Dr 1160 for the rest, Cr TDS / VDS payable for
     * the tax held back (finance decision C4, through the owner's rates).
     *
     * @param  list<array{item: array<string, mixed>, minor: int}>  $allocations
     * @return list<JournalLineDraft>
     */
    private function supplierPaymentLines(Supplier $supplier, VoucherLine $row, array $allocations, int $rest, Carbon $date, ?string $narration, int $index): array
    {
        $settled = Money::ofMinor(array_sum(array_column($allocations, 'minor')));
        $tds = $this->withholdingRate($row->tds_rate_id, 'tds', $supplier, $date, "lines.{$index}.tds_rate_id");
        $vds = $this->withholdingRate($row->vds_rate_id, 'vds', $supplier, $date, "lines.{$index}.vds_rate_id");
        $lines = [];

        if ($settled->minor > 0) {
            $lines[] = JournalLineDraft::debit(AccountMapping::resolve('purchasing.accounts_payable', $date)->id, $settled, $narration, $supplier);
            $this->settlements[] = [
                'kind' => 'payment',
                'party' => $supplier,
                'allocations' => array_map(fn (array $a) => ['bill' => $a['item']['document'], 'amount' => Money::ofMinor($a['minor'])->toTaka()], $allocations),
                'tds' => $tds,
                'vds' => $vds,
            ];
        }

        if ($rest > 0) {
            $lines[] = JournalLineDraft::debit(Account::bySlug('supplier_advances')->id, Money::ofMinor($rest), $narration ?? 'Advance paid', $supplier);
        }

        // On the whole line, bills and advance alike: the supplier is paid the
        // net and the tax is owed to NBR in their name.
        foreach (['tds' => [$tds, 'tds_payable'], 'vds' => [$vds, 'vds_payable']] as $kind => [$rate, $slug]) {
            if ($rate === null) {
                continue;
            }

            $held = $rate->taxOn($row->amount());

            if ($held->minor > 0) {
                $this->withheld += $held->minor;
                $lines[] = JournalLineDraft::credit(Account::bySlug($slug)->id, $held, strtoupper($kind).' held from '.$supplier->name, $supplier);
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{item: array<string, mixed>, minor: int}>  $allocations
     * @return list<JournalLineDraft>
     */
    private function customerReceiptLines(Customer $customer, array $allocations, int $rest, Carbon $date, ?string $narration): array
    {
        $settled = Money::ofMinor(array_sum(array_column($allocations, 'minor')));
        $lines = [];

        if ($settled->minor > 0) {
            $lines[] = JournalLineDraft::credit(AccountMapping::resolve('sales.accounts_receivable', $date)->id, $settled, $narration, $customer);
            $this->settlements[] = [
                'kind' => 'receipt',
                'party' => $customer,
                'allocations' => array_map(fn (array $a) => ['invoice' => $a['item']['document'], 'amount' => Money::ofMinor($a['minor'])->toTaka()], $allocations),
            ];
        }

        if ($rest > 0) {
            $lines[] = JournalLineDraft::credit(Account::bySlug('customer_advances')->id, Money::ofMinor($rest), $narration ?? 'Advance received', $customer);
        }

        return $lines;
    }

    /**
     * A supplier paying money back: what they owe us on 2110 (SREF, Cr 2110)
     * and advances they return (SARF, Cr 1160). Nothing else: money from a
     * supplier with nothing to pay back is not theirs to settle here.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{item: array<string, mixed>, minor: int}>  $allocations
     * @return list<JournalLineDraft>
     */
    private function supplierRefundLines(Supplier $supplier, array $items, array $allocations, int $rest, Carbon $date, ?string $narration, string $field): array
    {
        $this->refuseRest($supplier->name, $items, $rest, $field, 'owes us back');

        $lines = [];

        foreach ($allocations as $allocation) {
            $money = Money::ofMinor($allocation['minor']);
            $isBalance = $allocation['item']['kind'] === 'credit_balance';

            $lines[] = JournalLineDraft::credit(
                $isBalance ? AccountMapping::resolve('purchasing.accounts_payable', $date)->id : Account::bySlug('supplier_advances')->id,
                $money,
                $narration ?? ($isBalance ? 'Refund of what they owed us' : 'Advance refunded'),
                $supplier,
            );
            $this->refunds[] = ['kind' => $isBalance ? 'supplier_refund' : 'advance_refund', 'party' => $supplier, 'document' => $allocation['item']['document'], 'amount' => $money];
        }

        return $lines;
    }

    /**
     * Money back to a customer: their advance on 2150 (Dr 2150), or the credit
     * of a sales return or the money an order holds (a customer refund, Dr
     * 1140, C-SR-6). Never a goodwill payment with nothing behind it.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{item: array<string, mixed>, minor: int}>  $allocations
     * @return list<JournalLineDraft>
     */
    private function customerRefundLines(Customer $customer, array $items, array $allocations, int $rest, Carbon $date, ?string $narration, string $field): array
    {
        $this->refuseRest($customer->name, $items, $rest, $field, 'can be refunded');

        $lines = [];

        foreach ($allocations as $allocation) {
            $money = Money::ofMinor($allocation['minor']);
            $kind = $allocation['item']['kind'];

            if ($kind === 'customer_advance') {
                $lines[] = JournalLineDraft::debit(Account::bySlug('customer_advances')->id, $money, $narration ?? 'Advance refunded', $customer);

                continue;
            }

            $lines[] = JournalLineDraft::debit(AccountMapping::resolve('sales.accounts_receivable', $date)->id, $money, $narration ?? "Refund {$allocation['item']['number']}", $customer);
            $this->refunds[] = ['kind' => $kind === 'return' ? 'return_refund' : 'order_refund', 'party' => $customer, 'document' => $allocation['item']['document'], 'amount' => $money];
        }

        return $lines;
    }

    /**
     * The clear sentence when a refund line asks for more than is open,
     * naming what is (replaces "recorded on Purchases > Credit notes").
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    private function refuseRest(string $name, array $items, int $rest, string $field, string $verb): void
    {
        if ($rest <= 0) {
            return;
        }

        if ($items === []) {
            throw ValidationException::withMessages([$field => $verb === 'owes us back'
                ? "{$name} owes us nothing back and holds no advance of ours, so there is nothing to receive from them here. Money they send for something else (a claim, a rebate) is recorded as other income."
                : "Nothing of {$name}'s can be refunded: they hold no advance with us, and no return or order of theirs is waiting for money back."]);
        }

        $open = Money::sum(array_map(fn (array $item) => $item['open'], $items));
        $what = implode(', ', array_map(fn (array $item) => "{$item['number']} {$item['open']->format()}", $items));

        throw ValidationException::withMessages([$field => $verb === 'owes us back'
            ? "{$name} owes us back only {$open->format()} ({$what}). Enter that much or less."
            : "Only {$open->format()} can be refunded to {$name} ({$what}). Enter that much or less."]);
    }

    /**
     * The rate a supplier line holds back, checked as the payment screen
     * checks it: the right kind, fitting the supplier, in force on the date.
     *
     * @throws ValidationException
     */
    private function withholdingRate(mixed $rateId, string $kind, Supplier $supplier, Carbon $date, string $field): ?WithholdingRate
    {
        if ($rateId === null) {
            return null;
        }

        $rate = WithholdingRate::query()->find((int) $rateId);

        if ($rate === null || $rate->kind !== $kind || ! $rate->appliesTo($supplier)
            || ! WithholdingRate::query()->whereKey($rate->id)->inForceOn($date)->exists()) {
            throw ValidationException::withMessages([$field => ($rate?->label() ?? 'That rate').' does not apply to this supplier on that date.']);
        }

        return $rate;
    }

    /** Creates the receipts and payments planned by personLines(), owned by the voucher. */
    private function settle(Voucher $voucher, User $by): void
    {
        $voucher->loadMissing('moneyAccount');

        foreach ($this->settlements as $settlement) {
            if ($settlement['kind'] === 'receipt') {
                $this->recordReceipt->handle(
                    $settlement['party'], $voucher->moneyAccount, $voucher->voucher_date, $settlement['allocations'], $by,
                    reference: $voucher->voucher_number, note: $voucher->narration, voucherId: $voucher->id,
                );
            } else {
                $this->recordPayment->handle(
                    $settlement['party'], $voucher->moneyAccount, $voucher->voucher_date, $settlement['allocations'], $by,
                    reference: $voucher->voucher_number, note: $voucher->narration, voucherId: $voucher->id,
                    tdsRate: $settlement['tds'] ?? null, vdsRate: $settlement['vds'] ?? null,
                );
            }
        }

        $this->settlements = [];
    }

    /**
     * Creates the refunds planned by the refund lines, owned by the voucher
     * (party ledgers, 20 Oct 2026). Each engine re-checks its own cap under
     * its own lock and the purchasing approval rule (Tk 200,000); none posts
     * a journal of its own: the voucher's journal carries the line.
     */
    private function refund(Voucher $voucher, ?string $number, User $by): void
    {
        $voucher->loadMissing('moneyAccount');

        foreach ($this->refunds as $refund) {
            $taka = $refund['amount']->toTaka();

            match ($refund['kind']) {
                'supplier_refund' => $this->recordSupplierRefund->handle(
                    $refund['party'], $voucher->moneyAccount, $voucher->voucher_date, $taka, $by,
                    reference: $number, note: $voucher->narration, voucherId: $voucher->id,
                ),
                'advance_refund' => $this->refundSupplierAdvance->handle(
                    $refund['party'], $refund['document'], $voucher->moneyAccount, $voucher->voucher_date, $taka, $by,
                    reference: $number, note: $voucher->narration, voucherId: $voucher->id,
                ),
                'return_refund' => $this->recordCustomerRefund->handle(
                    $refund['document'], $voucher->moneyAccount, $taka, $by, $voucher->voucher_date,
                    reference: $number, note: $voucher->narration, voucherId: $voucher->id,
                ),
                'order_refund' => $this->recordCustomerRefund->forOrder(
                    $refund['document'], $voucher->moneyAccount, $taka, $by, $voucher->voucher_date,
                    reference: $number, note: $voucher->narration, voucherId: $voucher->id,
                ),
            };
        }

        $this->refunds = [];
    }

    /** Whether a journal voucher names a customer or supplier by their own head. */
    private function namesTradeParty(Voucher $voucher): bool
    {
        return $voucher->lines->contains(function (VoucherLine $line) {
            if ($line->amount_minor <= 0 || $line->account === null || $line->account->is_postable) {
                return false;
            }

            $person = PartyHeads::entityFor($line->account);

            return $person instanceof Customer || $person instanceof Supplier;
        });
    }

    /**
     * ⭐ A journal voucher on a customer or supplier (Tally's F7 with a party
     * ledger), posted as the typed party adjustment it is (finance decision
     * C3): RecordPartyAdjustment, against named invoices and bills, writes
     * the journal and the voucher carries it, its JV number and its look.
     *
     *   Dr Bad Debts           Cr customer   customer write-off
     *   Dr Discount Allowed    Cr customer   customer discount
     *   Dr Rounding            Cr customer   rounding
     *   Dr supplier            Cr Discount Received   supplier discount
     *   Dr supplier            Cr customer   set-off
     *
     * ⚠ Same permission as Accounting > Adjustments: the Owner's journal right.
     *
     * @throws ValidationException
     */
    private function postAdjustment(Voucher $voucher, User $by, Carbon $date): Voucher
    {
        if (! $by->can('accounting.journal.post')) {
            throw ValidationException::withMessages(['voucher' => 'A customer or supplier on a journal voucher is a write-off, discount or set-off. Only someone who may post journals can post it.']);
        }

        $rows = $voucher->lines->filter(fn (VoucherLine $line) => $line->amount_minor > 0)->values();
        [$debits, $credits] = $rows->partition(fn (VoucherLine $line) => $line->isDebit());
        $guide = 'A journal voucher with a customer or supplier has two lines: the party, and Bad debts, Discount allowed or Rounding (customer), Discount received (supplier), or the other party for a set-off.';

        if ($rows->count() !== 2 || $debits->count() !== 1 || $credits->count() !== 1) {
            throw ValidationException::withMessages(['lines' => $guide]);
        }

        /** @var VoucherLine $debit */
        $debit = $debits->first();
        /** @var VoucherLine $credit */
        $credit = $credits->first();

        if ($debit->amount_minor !== $credit->amount_minor) {
            $gap = Money::ofMinor(abs($debit->amount_minor - $credit->amount_minor))->format();

            throw ValidationException::withMessages(['lines' => "Debits and credits differ by {$gap}. A journal voucher must balance."]);
        }

        $partyOf = fn (VoucherLine $line) => $line->account->is_postable ? null : PartyHeads::entityFor($line->account);
        $debitParty = $partyOf($debit);
        $creditParty = $partyOf($credit);
        $slug = fn (VoucherLine $line) => $line->account->system_slug;
        $rounding = AccountMapping::forKeyOn('accounting.rounding_difference', $date)?->account_id;

        $kind = match (true) {
            $creditParty instanceof Customer && $debitParty === null && $slug($debit) === 'bad_debts' => 'customer_write_off',
            $creditParty instanceof Customer && $debitParty === null && $rounding !== null && (int) $debit->account_id === (int) $rounding => 'rounding',
            $creditParty instanceof Customer && $debitParty === null && $slug($debit) === 'discounts_allowed' => 'customer_discount',
            $debitParty instanceof Supplier && $creditParty === null && $slug($credit) === 'discounts_received' => 'supplier_discount',
            $debitParty instanceof Supplier && $creditParty instanceof Customer => 'set_off',
            default => throw ValidationException::withMessages(['lines' => $guide]),
        };

        $reason = trim((string) $voucher->narration);

        if ($reason === '') {
            throw ValidationException::withMessages(['narration' => 'Say why in the narration: it is the reason the adjustment keeps.']);
        }

        // Against named invoices and bills, as the person shared them out or
        // oldest first, and never more than they still owe.
        $documents = function (VoucherLine $line, Model $party) use ($date, $rows) {
            $index = $rows->search(fn (VoucherLine $row) => $row->is($line));
            $items = PartyBillWise::items(PartyBillWise::ADJUSTMENT, $party, $date);
            $planned = [];
            ['allocations' => $allocations, 'rest' => $rest] = PartyBillWise::allocate($items, $line->bill_wise, (int) $line->amount_minor, $planned, "lines.{$index}.bill_wise");

            if ($rest > 0) {
                $open = Money::sum(array_map(fn (array $item) => $item['open'], $items));
                $what = $party instanceof Customer ? 'invoices' : 'bills';

                throw ValidationException::withMessages(["lines.{$index}.bill_wise" => "{$party->getAttribute('name')} has only {$open->format()} open on {$what} dated on or before the voucher, and an adjustment is always against named {$what}."]);
            }

            $map = [];

            foreach ($allocations as $allocation) {
                $id = (int) $allocation['item']['id'];
                $map[$id] = Money::ofMinor(Money::ofTaka($map[$id] ?? '0')->minor + $allocation['minor'])->toTaka();
            }

            return $map;
        };

        $customer = $creditParty instanceof Customer ? $creditParty : null;
        $supplier = $debitParty instanceof Supplier ? $debitParty : null;

        $adjustment = $this->recordAdjustment->handle(
            $kind,
            $date,
            $customer,
            $supplier,
            $customer === null ? [] : $documents($credit, $customer),
            $supplier === null ? [] : $documents($debit, $supplier),
            $reason,
            $by,
            source: $voucher,
        );

        $adjustment->forceFill(['voucher_id' => $voucher->id])->save();

        $journal = Journal::query()->where('source_type', $voucher->getMorphClass())->where('source_id', $voucher->id)
            ->whereNull('reverses_journal_id')->latest('id')->firstOrFail();

        $voucher->update([
            'voucher_number' => $journal->journal_number,
            'status' => VoucherStatus::Posted,
            'journal_id' => $journal->id,
            'posted_by' => $by->id,
            'posted_at' => now(),
        ]);

        return $voucher->fresh(['lines', 'journal']);
    }

    /**
     * Income Tax Act 2023 s.55: a payment above the limit made other than by
     * bank transfer is disallowed as a tax expense. Refused here; the limit is
     * the Owner's, in Finance policy (finance policy B13, 29 Sep 2026).
     */
    private function assertCashLimit(Voucher $voucher, MoneyAccount $money, Money $total): void
    {
        if ($money->type !== MoneyAccountType::Cash) {
            return;
        }

        $limit = FinancePolicy::for((int) $voucher->business_id)->money('cash_payment_limit_minor');

        if ($limit->minor > 0 && $total->minor > $limit->minor) {
            throw ValidationException::withMessages([
                'money_account_id' => "A payment above {$limit->format()} must go through a bank (Income Tax Act 2023, s.55). Pay it from a bank account, or split it only if these are genuinely separate payments.",
            ]);
        }
    }

    private function memo(Voucher $voucher, ?string $number): string
    {
        $head = trim($voucher->type->label().' '.($number ?? ''));
        $tail = $voucher->counterparty ?? $voucher->narration;

        return $tail === null ? $head : "{$head} - ".mb_strimwidth($tail, 0, 200, '…');
    }

    /**
     * PV-2026-27-0001, per business, per type, per fiscal year.
     *
     * ⚠ A counter row under lock, not "highest number + 1": the expense
     * voucher's max()+1 reads a number a concurrent transaction is about to
     * take, and the unique index then fails one of two honest users.
     */
    private function nextNumber(Voucher $voucher): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($voucher->business_id);
        $fiscalYear = FiscalYear::containing($voucher->voucher_date, $business);
        $key = ['business_id' => $business->id, 'type' => $voucher->type->value, 'fiscal_year' => $fiscalYear->startingYear];

        if (! DB::table('voucher_sequences')->where($key)->exists()) {
            DB::table('voucher_sequences')->insertOrIgnore($key + ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $row = DB::table('voucher_sequences')->where($key)->lockForUpdate()->first();
        DB::table('voucher_sequences')->where('id', $row->id)->update(['next_number' => $row->next_number + 1, 'updated_at' => now()]);

        return sprintf('%s-%s-%04d', $voucher->type->prefix(), $fiscalYear->label(), $row->next_number);
    }
}
