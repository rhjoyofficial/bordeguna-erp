<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\VoucherForm;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 5): a voucher that looks
 * like one saved in the last few days. A warning with "Save anyway", never a
 * refusal; drafts, waiting and posted vouchers count, reversed ones do not.
 *
 *   payment, receive   same type, same party (or the same "Paid to" text,
 *                      ignoring case and spaces), same amount, same bank or
 *                      cash account, within 3 days; with no party named at
 *                      all, the same heads stand in for it
 *   contra             same from and to accounts, same amount, within 3 days
 *   journal            same heads, same amount, within 3 days
 *
 * The payload carries the voucher type (`type`), so like is compared with like.
 * "+ reference" (FINAL-PLAN 5): two vouchers that both carry a cheque or
 * transfer number, and different ones, are two payments, never a warning.
 */
class VoucherDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return VoucherActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        $type = $this->type($payload);

        return [
            'business' => $businessId,
            'type' => $type?->value,
            'date' => Carbon::parse($payload['voucher_date'] ?? 'today')->toDateString(),
            'total' => $type === null ? 0 : VoucherForm::totalMinor($type, $payload),
            'money' => (int) ($payload['money_account_id'] ?? 0),
            'to' => (int) ($payload['to_money_account_id'] ?? 0),
            'party' => $this->partyKey($payload),
            'heads' => $this->heads($payload),
            'instrument' => $this->instrument($payload['instrument_number'] ?? null),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $type = $this->type($payload);

        if ($type === null) {
            return [];
        }

        $total = VoucherForm::totalMinor($type, $payload);

        if ($total <= 0) {
            return [];
        }

        $date = Carbon::parse($payload['voucher_date'] ?? 'today')->startOfDay();
        $party = $this->partyKey($payload);
        $heads = $this->heads($payload);
        $instrument = $this->instrument($payload['instrument_number'] ?? null);
        $morph = (new Voucher)->getMorphClass();

        return Voucher::query()
            ->where('business_id', $businessId)
            ->where('type', $type->value)
            ->where('status', '!=', VoucherStatus::Reversed->value)
            ->where('total_minor', $total)
            ->whereBetween('voucher_date', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()])
            ->when($type->usesMoneyAccount(), fn ($q) => $q->where('money_account_id', (int) ($payload['money_account_id'] ?? 0)))
            ->when($type === VoucherType::Contra, fn ($q) => $q->where('to_money_account_id', (int) ($payload['to_money_account_id'] ?? 0)))
            ->with(['lines:id,voucher_id,account_id', 'moneyAccount:id,name', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->reject(fn (Voucher $v) => in_array("{$morph}:{$v->id}", $exclude, true))
            ->reject(fn (Voucher $v) => $instrument !== null && $this->instrument($v->instrument_number) !== null && $this->instrument($v->instrument_number) !== $instrument)
            ->filter(fn (Voucher $v) => match ($type) {
                VoucherType::Contra => true,
                VoucherType::Journal => $this->headsOf($v) === $heads,
                default => $party !== null
                    ? $this->partyKey(['party' => $v->party_type === null ? null : strtolower(class_basename($v->party_type)).':'.$v->party_id, 'counterparty' => $v->counterparty]) === $party
                    : $this->headsOf($v) === $heads,
            })
            ->map(fn (Voucher $v) => new DuplicateCandidate(
                type: $morph,
                id: (int) $v->id,
                number: $v->title(),
                summary: "A {$type->label()} of ".Tk::of($total).($v->counterparty ? " to {$v->counterparty}" : '').($v->moneyAccount ? " through {$v->moneyAccount->name}" : '').' on '.Tk::date($v->voucher_date),
                savedBy: $v->createdBy?->name ?? 'somebody',
                savedAgo: $v->created_at?->diffForHumans() ?? '',
                status: $v->status->label(),
                visibleToUser: $viewer->can('accounting.view'),
                url: route('accounting.vouchers.show', $v->id),
            ))
            ->values()
            ->all();
    }

    private function instrument(mixed $number): ?string
    {
        $number = mb_strtolower(preg_replace('/\s+/u', '', (string) $number) ?? '');

        return $number === '' ? null : $number;
    }

    private function type(array $payload): ?VoucherType
    {
        return VoucherType::tryFrom((string) ($payload['type'] ?? ''));
    }

    /** "supplier:12", or the typed name in lower case with no spaces, or null. */
    private function partyKey(array $payload): ?string
    {
        $party = trim((string) ($payload['party'] ?? ''));

        if ($party !== '' && $party !== 'other' && str_contains($party, ':')) {
            return $party;
        }

        $name = mb_strtolower(preg_replace('/\s+/u', '', (string) ($payload['counterparty'] ?? '')) ?? '');

        return $name === '' ? null : "text:{$name}";
    }

    /** @return list<int> */
    private function heads(array $payload): array
    {
        $heads = array_values(array_unique(array_map('intval', array_column(array_filter($payload['lines'] ?? [], fn ($l) => is_numeric($l['amount'] ?? null) && (float) $l['amount'] > 0), 'account_id'))));
        sort($heads);

        return $heads;
    }

    /** @return list<int> */
    private function headsOf(Voucher $voucher): array
    {
        $heads = array_values(array_unique($voucher->lines->pluck('account_id')->map(fn ($id) => (int) $id)->all()));
        sort($heads);

        return $heads;
    }
}
