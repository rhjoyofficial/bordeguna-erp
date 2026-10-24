<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Voucher;
use Illuminate\Validation\Rule;

/**
 * The payment, receive, contra and journal voucher form, read once
 * (corrections wave 2, B7, 24 Oct 2026).
 *
 * ⭐ The shape rules moved out of VoucherController so the record screen, a
 * posted voucher's Correct and the duplicate warning read the same fields the
 * same way. What a voucher MAY be is still SaveVoucherDraft's and
 * PostVoucher's, decided once.
 */
final class VoucherForm
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'voucher_date' => ['required', 'date'],
            'money_account_id' => ['nullable', 'integer'],
            'to_money_account_id' => ['nullable', 'integer'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            // ⭐ "supplier:12", "customer:5", "employee:3" (party ledgers, 20 Oct 2026).
            'party' => ['nullable', 'string', 'max:40'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'instrument_type' => ['nullable', Rule::in(Voucher::INSTRUMENT_TYPES)],
            'instrument_number' => ['nullable', 'string', 'max:60'],
            'instrument_date' => ['nullable', 'date'],
            'instrument_bank' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.side' => ['nullable', Rule::in(['debit', 'credit'])],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
            'lines.*.party_id' => ['nullable', 'integer'],
            'lines.*.loan_contract_id' => ['nullable', 'integer'],
            // ⭐ Bill-wise details and tax held back (party ledgers, 20 Oct 2026).
            'lines.*.bill_wise' => ['nullable', 'array', 'max:200'],
            'lines.*.bill_wise.*.ref' => ['required', 'string', 'max:40'],
            'lines.*.bill_wise.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.tds_rate_id' => ['nullable', 'integer'],
            'lines.*.vds_rate_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * What the voucher is worth, in paisa, as SaveVoucherDraft totals it: a
     * journal voucher's debit side, the lines of a payment or receipt, a
     * contra's amount.
     */
    public static function totalMinor(VoucherType $type, array $data): int
    {
        if (! $type->hasLines()) {
            return is_numeric($data['amount'] ?? null) ? Money::ofTaka((string) $data['amount'])->minor : 0;
        }

        $minor = 0;

        foreach ($data['lines'] ?? [] as $line) {
            if (! is_numeric($line['amount'] ?? null)) {
                continue;
            }

            $side = $type->fixedLineSide() ?? ($line['side'] ?? 'debit');

            if ($type !== VoucherType::Journal || $side === 'debit') {
                $minor += Money::ofTaka((string) $line['amount'])->minor;
            }
        }

        return $minor;
    }

    /** A posted or draft voucher as its form would send it. */
    public static function of(Voucher $voucher): array
    {
        $voucher->loadMissing('lines');

        return [
            'voucher_date' => $voucher->voucher_date->toDateString(),
            'money_account_id' => $voucher->money_account_id,
            'to_money_account_id' => $voucher->to_money_account_id,
            'counterparty' => $voucher->counterparty,
            'party' => $voucher->party_type === null ? null : strtolower(class_basename($voucher->party_type)).':'.$voucher->party_id,
            'narration' => $voucher->narration,
            'instrument_type' => $voucher->instrument_type,
            'instrument_number' => $voucher->instrument_number,
            'instrument_date' => $voucher->instrument_date?->toDateString(),
            'instrument_bank' => $voucher->instrument_bank,
            'amount' => $voucher->total()->toTaka(),
            'lines' => $voucher->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'side' => $line->side,
                'amount' => $line->amount()->toTaka(),
                'narration' => $line->narration,
                'party_id' => $line->party_id,
                'loan_contract_id' => $line->loan_contract_id,
                'bill_wise' => $line->bill_wise,
                'tds_rate_id' => $line->tds_rate_id,
                'vds_rate_id' => $line->vds_rate_id,
            ])->values()->all(),
        ];
    }
}
