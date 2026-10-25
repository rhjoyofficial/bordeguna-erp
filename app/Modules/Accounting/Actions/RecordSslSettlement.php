<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SslClearing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): "When SSL pays out, the real amount goes to
 * the actual bank and SSL's charge is deducted: handle it properly (gross,
 * fee, net)."
 *
 *   Dr  the bank (money account)                 net
 *   Dr  6475 Payment Gateway Charge              fee   (mapping sales.gateway_fees)
 *   Cr  1146 SSL Commerz Clearing (money acct)   gross
 *
 * One journal through PostJournal, the settlement row and its number in the
 * same transaction. Gross = net + fee, always: the person types the gross and
 * the fee, the net is worked out. Dated the day SSL paid out (no future date;
 * a closed month is PostJournal's refusal).
 */
class RecordSslSettlement
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        MoneyAccount $bank,
        string $gross,
        string $fee,
        mixed $settledOn,
        User $createdBy,
        ?string $reference = null,
        ?string $note = null,
    ): SslSettlement {
        $businessId = (int) app(BusinessContext::class)->id();
        $date = Carbon::parse($settledOn)->startOfDay();
        $grossMoney = Money::ofTaka($gross);
        $feeMoney = Money::ofTaka($fee === '' ? '0' : $fee);

        if ($date->isAfter(Carbon::today())) {
            throw ValidationException::withMessages(['settled_on' => 'A settlement cannot be dated in the future.']);
        }

        if ($grossMoney->minor <= 0) {
            throw ValidationException::withMessages(['gross' => 'Type the gross amount SSL settled.']);
        }

        if ($feeMoney->minor < 0 || $feeMoney->minor >= $grossMoney->minor) {
            throw ValidationException::withMessages(['fee' => 'The SSL charge must be zero or more, and less than the gross.']);
        }

        if ((int) $bank->business_id !== $businessId || ! $bank->is_active || $bank->account_id === null || $bank->isGateway() || $bank->isCourier()) {
            throw ValidationException::withMessages(['bank_money_account_id' => 'Choose the bank or wallet account of this business the money landed in.']);
        }

        $clearing = SslClearing::for($businessId);

        if ($clearing === null || $clearing->account_id === null) {
            throw ValidationException::withMessages(['gross' => 'There is no SSL Commerz Clearing account: the chart has no 1146 Receivable from Payment Gateways.']);
        }

        $feeHead = null;

        if ($feeMoney->minor > 0) {
            $feeHead = AccountMapping::forKeyOn('sales.gateway_fees', $date)?->account;

            if ($feeHead === null || $feeHead->type !== AccountType::Expense) {
                throw ValidationException::withMessages(['fee' => 'SSL charges have no expense head mapped (sales.gateway_fees). Map 6475 Payment Gateway Charge under Accounts > Account mappings.']);
            }
        }

        $net = $grossMoney->plus($feeMoney->negated());

        return DB::transaction(function () use ($businessId, $date, $bank, $clearing, $grossMoney, $feeMoney, $net, $feeHead, $createdBy, $reference, $note) {
            $settlement = DocumentNumber::claim(
                'number',
                fn () => $this->nextNumber($businessId, $date),
                fn (string $number) => SslSettlement::create([
                    'business_id' => $businessId,
                    'number' => $number,
                    'settled_on' => $date,
                    'clearing_money_account_id' => $clearing->id,
                    'bank_money_account_id' => $bank->id,
                    'gross' => $grossMoney->toTaka(),
                    'fee' => $feeMoney->toTaka(),
                    'net' => $net->toTaka(),
                    'fee_account_id' => $feeHead?->id,
                    'reference' => blank($reference) ? null : trim((string) $reference),
                    'note' => blank($note) ? null : trim((string) $note),
                    'created_by' => $createdBy->id,
                ]),
            );

            $lines = [JournalLineDraft::debit($bank->account_id, $net, "SSL payout {$settlement->number}", null, $bank)];

            if ($feeHead !== null) {
                $lines[] = JournalLineDraft::debit($feeHead->id, $feeMoney, "SSL charge on {$settlement->number}");
            }

            $lines[] = JournalLineDraft::credit($clearing->account_id, $grossMoney, "SSL payout {$settlement->number}", null, $clearing);

            try {
                $journal = $this->postJournal->handle(new JournalDraft(
                    date: $date,
                    lines: $lines,
                    memo: "SSL settlement {$settlement->number}: ".$grossMoney->format().' gross, '.$feeMoney->format().' SSL charge, '.$net->format()." to {$bank->name}",
                    source: $settlement,
                ), $createdBy);
            } catch (MappingNotFound $e) {
                throw ValidationException::withMessages(['bank_money_account_id' => $e->getMessage()]);
            }

            $settlement->forceFill(['journal_id' => $journal->id])->save();

            return $settlement->fresh(['bank', 'clearing', 'feeAccount']);
        });
    }

    /** `SSL-2026-27-0001`, restarting each fiscal year. */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);

        return DocumentNumber::next(SslSettlement::query(), 'number', 'SSL-'.FiscalYear::containing($date, $business)->label().'-');
    }
}
