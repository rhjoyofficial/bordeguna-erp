<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\PurchaseDraft;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays a supplier, against specific bills.
 *
 * ⚠⚠ THE EVENT THAT DID NOT EXIST. Until now the ERP could post a liability to
 * Accounts Payable and had no way to settle it: the only related action set a
 * payment-status string that moved no money and reached no ledger. Payables
 * would have grown forever.
 *
 * ⚠ The document is the point and the journal is a consequence. Somebody moved
 * money on a date, from an account, with a reference, against these invoices.
 */
class RecordSupplierPayment
{
    public function __construct(
        private readonly PostPurchaseJournals $posting,
        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026).
        private readonly RecordSupplierDiscount $discounts,
        // ⭐ Purchasing approvals (ruling P7, 18 Oct 2026).
        private readonly PurchasingApprovalGate $approvals,
    ) {}

    /**
     * Each allocation may also carry `discount` (taka): a settlement discount
     * the supplier allowed on that bill (ruling P3, 18 Oct 2026), recorded with
     * `$discountEvidence` and `$discountReason`.
     *
     * @param  array<int, array{bill: SupplierBill, amount: string, discount?: ?string}>  $allocations
     *
     * @throws ValidationException
     */
    public function handle(
        Supplier $supplier,
        MoneyAccount $moneyAccount,
        mixed $paidOn,
        array $allocations,
        User $createdBy,
        ?string $reference = null,
        ?string $note = null,
        // ⭐ A Payment voucher line on a supplier (finance decision C3): the
        // payment belongs to the voucher, whose own journal carries the debit
        // to Accounts Payable, so no second journal is posted here.
        ?int $voucherId = null,
        // Finance decision C4: the TDS / VDS rates held back from this payment.
        // ⭐ 20 Oct 2026 (party ledgers): a voucher may carry them too; the
        // voucher's journal then posts the tax lines (PostVoucher).
        ?WithholdingRate $tdsRate = null,
        ?WithholdingRate $vdsRate = null,
        // ⭐ Supplier advances (ruling P2, 18 Oct 2026): the payer confirmed
        // that what a line pays beyond its bill becomes an advance.
        bool $excessToAdvance = false,
        // ⭐ Supplier discounts (ruling P3): the evidence of the supplier's
        // agreement and why. Required whenever a discount is taken.
        ?string $discountEvidence = null,
        ?string $discountReason = null,
    ): SupplierPayment {
        $date = Carbon::parse($paidOn)->startOfDay();

        // ⚠ A payment dated into a closed month would change a figure somebody
        // has already reported. Same lock as everything else.
        AccountingPeriod::assertOpenFor($date);

        // ⚠ Money cannot leave on a date that has not happened: a future-dated
        // payment would sit in the cash book ahead of the bank statement.
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['paid_on' => 'A payment cannot be dated in the future.']);
        }

        if ($moneyAccount->business_id !== $supplier->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        if (($refusal = $moneyAccount->handRefusal()) !== null) {
            throw ValidationException::withMessages(['money_account_id' => $refusal]);
        }

        $allocations = array_values(array_filter(
            $allocations,
            fn (array $entry) => Money::ofTaka($entry['amount'])->minor > 0,
        ));

        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Say which bills this payment settles, and how much of each.',
            ]);
        }

        foreach (['tds_rate_id' => [$tdsRate, 'tds'], 'vds_rate_id' => [$vdsRate, 'vds']] as $field => [$rate, $kind]) {
            if ($rate === null) {
                continue;
            }

            if ($rate->kind !== $kind || ! $rate->appliesTo($supplier)
                || ! WithholdingRate::query()->whereKey($rate->id)->inForceOn($date)->exists()) {
                throw ValidationException::withMessages([$field => "{$rate->label()} does not apply to this supplier on that date."]);
            }
        }

        // ── Supplier advances (ruling P2) ─────────────────────────────────
        if ($excessToAdvance && $voucherId !== null) {
            throw ValidationException::withMessages(['allocations' => 'A voucher keeps what no bill is waiting for as an advance by itself.']);
        }
        // ── end supplier advances ─────────────────────────────────────────

        // ── Supplier discounts (ruling P3, 18 Oct 2026) ──────────────────────
        $discounted = array_filter($allocations, fn (array $entry) => Money::ofTaka((string) ($entry['discount'] ?? '0'))->minor > 0);

        if ($discounted !== [] && $voucherId !== null) {
            throw ValidationException::withMessages(['discount' => 'Take a settlement discount on the supplier payment screen, not on a voucher.']);
        }
        // ── end supplier discounts ───────────────────────────────────────────

        // ⭐ Purchasing approvals (ruling P7): what was typed, so the same
        // payment can be made again on approval.
        $payload = [
            'supplier_id' => $supplier->id,
            'money_account_id' => $moneyAccount->id,
            'paid_on' => $date->toDateString(),
            'allocations' => array_map(fn (array $entry) => [
                'supplier_bill_id' => $entry['bill']->id,
                'amount' => (string) $entry['amount'],
                'discount' => (string) ($entry['discount'] ?? '0'),
            ], $allocations),
            'reference' => $reference,
            'note' => $note,
            'tds_rate_id' => $tdsRate?->id,
            'vds_rate_id' => $vdsRate?->id,
            'excess_to_advance' => $excessToAdvance,
            'discount_evidence' => $discountEvidence,
            'discount_reason' => $discountReason,
        ];

        return DB::transaction(function () use ($supplier, $moneyAccount, $date, $allocations, $createdBy, $reference, $note, $voucherId, $tdsRate, $vdsRate, $excessToAdvance, $discounted, $discountEvidence, $discountReason, $payload) {
            $total = Money::zero();
            // Supplier advances (ruling P2): paid beyond the bills, confirmed.
            $excess = Money::zero();
            // ⚠⚠ Per bill, across the whole payment: the same bill listed twice
            // would otherwise pass the outstanding cap once per line.
            $claimed = [];

            foreach ($allocations as $index => $entry) {
                /** @var SupplierBill $bill */
                // ⚠⚠ RE-READ UNDER A LOCK inside the transaction. Two clerks
                // paying the same invoice at once is the realistic way a
                // supplier gets paid twice, and the re-read is what makes this
                // correct - `lockForUpdate` is a no-op on SQLite, so a
                // lock-only fix is green in the suite and races in production.
                // Third application of the I3 lesson.
                $bill = SupplierBill::query()->whereKey($entry['bill']->id)->lockForUpdate()->firstOrFail();

                if ($bill->supplier_id !== $supplier->id) {
                    // ⚠ C-PUR-E2. `allocations` and not `allocations.N.bill`:
                    // both screens render the top-level key and neither renders
                    // a per-line bill key, so the old one was never displayed.
                    // The message already names the bill, so nothing is lost.
                    throw ValidationException::withMessages([
                        'allocations' => "Bill {$bill->bill_number} belongs to a different supplier.",
                    ]);
                }

                $amount = Money::ofTaka($entry['amount']);
                $outstanding = Money::ofMinor($bill->outstanding()->minor - ($claimed[$bill->id] ?? 0));
                // ⭐ Supplier discounts: the discount settles the bill too, so it counts against what is owed.
                $discount = Money::ofTaka((string) ($entry['discount'] ?? '0'));

                if ($discount->minor > 0 && $amount->minor + $discount->minor > $outstanding->minor) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.discount" => "Bill {$bill->bill_number} has only {$outstanding->format()} outstanding; the payment and the discount together are more.",
                    ]);
                }

                $claimed[$bill->id] = ($claimed[$bill->id] ?? 0) + max($discount->minor, 0);

                // ⚠ Paying more than is owed is not a payment, it is an advance
                // to the supplier - a different event with a different account.
                // Refused rather than absorbed, because absorbing it would make
                // a bill read as overpaid and the payable go negative.
                // ⭐ Ruling P2 (18 Oct 2026): unless the payer CONFIRMED it, in
                // which case the bill takes what it owes and the rest becomes a
                // traceable advance on this payment (never a stranded AP debit).
                if ($amount->minor > $outstanding->minor) {
                    if (! $excessToAdvance) {
                        throw ValidationException::withMessages([
                            "allocations.{$index}.amount" => "Bill {$bill->bill_number} has only {$outstanding->format()} outstanding.",
                        ]);
                    }

                    $excess = $excess->plus(Money::ofMinor($amount->minor - max(0, $outstanding->minor)));
                    $amount = Money::ofMinor(max(0, $outstanding->minor));
                    $allocations[$index]['amount'] = $amount->toTaka();
                }

                $claimed[$bill->id] = ($claimed[$bill->id] ?? 0) + $amount->minor;
                $total = $total->plus($amount);
            }

            if ($total->isZero()) {
                throw ValidationException::withMessages(['allocations' => 'Nothing is outstanding on these bills. Record an advance instead.']);
            }

            $tds = $tdsRate?->taxOn($total) ?? Money::zero();
            $vds = $vdsRate?->taxOn($total) ?? Money::zero();
            $net = Money::ofMinor($total->minor - $tds->minor - $vds->minor);

            if ($net->minor < 0) {
                throw ValidationException::withMessages(['tds_rate_id' => 'The tax held back is more than the payment.']);
            }

            // ── Purchasing approvals (ruling P7, 18 Oct 2026) ─────────────────
            // The whole payment is the basis (bills and any advance part), never
            // one allocation at a time. Asked before anything is written.
            $discountTotal = Money::sum(array_map(fn (array $entry) => Money::ofTaka((string) ($entry['discount'] ?? '0')), $discounted));
            $draft = new PurchaseDraft(
                kind: 'payment',
                supplier: $supplier,
                amount: $total->plus($excess),
                documentDate: $date,
                reference: $reference,
                summary: "Payment to {$supplier->name} from {$moneyAccount->name}",
                payload: $payload,
                discount: $discountTotal,
                discountBase: $total->plus($discountTotal),
                overApplication: $excess->isZero() ? null : "it pays {$excess->format()} more than the bills owe, kept as an advance",
                moneyAccount: $moneyAccount,
            );

            if ($voucherId === null) {
                $this->approvals->enforce($draft, $createdBy);
            } else {
                $this->approvals->enforceForVoucher($draft, $createdBy, (int) DB::table('vouchers')->where('id', $voucherId)->value('created_by'));
            }
            // ── end purchasing approvals ─────────────────────────────────────

            if ($voucherId === null) {
                // Finance policy B14: no cash box or wallet below zero, a bank only to its overdraft.
                // Supplier advances: the advance part leaves the same account in the same remittance.
                $excessNet = Money::ofMinor($excess->minor - ($tdsRate?->taxOn($excess) ?? Money::zero())->minor);
                MoneyAvailability::assertCanPay($moneyAccount, $net->plus($excessNet), $date, 'money_account_id');
            }

            $payment = SupplierPayment::create([
                'supplier_id' => $supplier->id,
                'money_account_id' => $moneyAccount->id,
                'voucher_id' => $voucherId,
                'paid_on' => $date,
                'amount' => $total->toTaka(),
                'tds_rate_id' => $tdsRate?->id,
                'tds_amount' => $tds->toTaka(),
                'vds_rate_id' => $vdsRate?->id,
                'vds_amount' => $vds->toTaka(),
                'reference' => $reference,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($allocations as $entry) {
                // Supplier advances: a line capped to nothing paid only the advance.
                if (Money::ofTaka($entry['amount'])->isZero()) {
                    continue;
                }

                $payment->allocations()->create([
                    'business_id' => $payment->business_id,
                    'supplier_bill_id' => $entry['bill']->id,
                    'amount' => Money::ofTaka($entry['amount'])->toTaka(),
                ]);
            }

            // ── Supplier advances (ruling P2) ─────────────────────────────────
            if (! $excess->isZero()) {
                app(RecordSupplierAdvance::class)->forOverpayment($payment->setRelation('supplier', $supplier), $excess, $tdsRate, $createdBy);
            }
            // ── end supplier advances ─────────────────────────────────────────

            // ⚠⚠ Dr Accounts Payable, Cr the money account - posted in the same
            // transaction as the document, so a payment the cash book cannot see
            // is not a state this can reach.
            if ($voucherId === null) {
                $this->posting->forPayment($payment->fresh(['allocations', 'supplier', 'moneyAccount']), $createdBy);
            }

            // ── Supplier discounts (ruling P3, 18 Oct 2026) ──────────────────
            // A documented settlement discount reduces the goods' cost, dated
            // the payment date. ⚠ A short payment with no discount entered is
            // just a part payment: the bill stays open by the difference.
            foreach ($discounted as $index => $entry) {
                $this->discounts->settlement(
                    $entry['bill'],
                    Money::ofTaka((string) $entry['discount']),
                    $payment,
                    (string) $discountEvidence,
                    (string) $discountReason,
                    $createdBy,
                    "allocations.{$index}.discount",
                );
            }
            // ── end supplier discounts ───────────────────────────────────────

            return $payment->fresh(['allocations', 'supplier']);
        });
    }
}
