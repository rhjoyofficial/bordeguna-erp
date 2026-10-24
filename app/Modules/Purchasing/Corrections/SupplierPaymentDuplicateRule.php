<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 5 "Supplier payment: same
 * supplier + same amount + date within 3 days"). A warning with "Save anyway",
 * never a refusal; reversed payments do not count.
 */
class SupplierPaymentDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return SupplierPaymentActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        return [
            'business' => $businessId,
            'supplier' => (int) ($payload['supplier_id'] ?? 0),
            'amount' => $this->amount($payload),
            'date' => Carbon::parse($payload['paid_on'] ?? 'today')->toDateString(),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $amount = $this->amount($payload);

        if ($amount <= 0 || empty($payload['supplier_id'])) {
            return [];
        }

        $date = Carbon::parse($payload['paid_on'] ?? 'today')->startOfDay();
        $morph = (new SupplierPayment)->getMorphClass();

        return SupplierPayment::query()
            ->whereNull('reversed_at')
            ->where('business_id', $businessId)
            ->where('supplier_id', (int) $payload['supplier_id'])
            ->whereBetween('paid_on', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()])
            ->with(['supplier:id,name', 'moneyAccount:id,name', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (SupplierPayment $p) => Money::ofTaka((string) $p->amount)->minor === $amount)
            ->reject(fn (SupplierPayment $p) => in_array("{$morph}:{$p->id}", $exclude, true))
            ->map(fn (SupplierPayment $p) => new DuplicateCandidate(
                type: $morph,
                id: (int) $p->id,
                number: $p->reference ?: "Payment #{$p->id}",
                summary: 'A payment of '.Tk::of($amount).' to '.($p->supplier?->name ?? 'the supplier').' from '.($p->moneyAccount?->name ?? 'an account').' on '.Tk::date($p->paid_on),
                savedBy: $p->createdBy?->name ?? 'somebody',
                savedAgo: $p->created_at?->diffForHumans() ?? '',
                status: 'Posted',
                visibleToUser: $viewer->can('purchasing.reports.view') || $viewer->can('accounting.view'),
                url: route('purchasing.payments.show', $p->id),
            ))
            ->values()
            ->all();
    }

    private function amount(array $payload): int
    {
        if (isset($payload['amount']) && is_numeric($payload['amount'])) {
            return Money::ofTaka((string) $payload['amount'])->minor;
        }

        return array_sum(array_map(fn ($row) => is_numeric($row['amount'] ?? null) ? Money::ofTaka((string) $row['amount'])->minor : 0, $payload['allocations'] ?? []));
    }
}
