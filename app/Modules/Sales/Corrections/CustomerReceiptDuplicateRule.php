<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Support\Carbon;

/**
 * ⭐ A customer receipt that looks like one already recorded (FINAL-PLAN 5:
 * "same customer + same amount + within 3 days"), for a correction's successor
 * (never warning about its own original or chain). A warning, never a block.
 */
class CustomerReceiptDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return CustomerReceiptActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        return [
            'business' => $businessId,
            'customer' => (int) ($payload['customer_id'] ?? 0),
            'amount' => Money::ofTaka((string) ($payload['amount'] ?? '0'))->minor,
            'date' => Carbon::parse($payload['received_on'] ?? 'today')->toDateString(),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $date = Carbon::parse($payload['received_on'] ?? 'today')->startOfDay();
        $amount = Money::ofTaka((string) ($payload['amount'] ?? '0'))->minor;

        if ($amount <= 0 || empty($payload['customer_id'])) {
            return [];
        }

        $morph = (new CustomerReceipt)->getMorphClass();

        return CustomerReceipt::query()
            ->live()
            ->where('business_id', $businessId)
            ->where('customer_id', (int) $payload['customer_id'])
            ->whereBetween('received_on', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()])
            ->with(['customer:id,name', 'moneyAccount:id,name', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (CustomerReceipt $r) => Money::ofTaka((string) $r->amount)->minor === $amount)
            ->reject(fn (CustomerReceipt $r) => in_array("{$morph}:{$r->id}", $exclude, true))
            ->map(fn (CustomerReceipt $r) => new DuplicateCandidate(
                type: $morph,
                id: (int) $r->id,
                number: CustomerReceiptActionPolicy::label($r),
                summary: Tk::of($amount).' from '.($r->customer?->name ?? 'the customer').' into '.($r->moneyAccount?->name ?? 'a money account').' on '.Tk::date($r->received_on),
                savedBy: $r->createdBy?->name ?? 'somebody',
                savedAgo: $r->created_at?->diffForHumans() ?? '',
                status: 'Posted',
                visibleToUser: $viewer->can('sales.reports.view'),
                url: route('sales.receipts.print', $r),
            ))
            ->values()
            ->all();
    }
}
