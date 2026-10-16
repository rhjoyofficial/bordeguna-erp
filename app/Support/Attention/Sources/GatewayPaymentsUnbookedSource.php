<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/**
 * Money a customer paid online that the books do not hold yet (bug hunt 16 Oct
 * 2026). The ten-minute job books most of these on its own; one still waiting
 * after half an hour needs a person, and one whose day is in a closed month is
 * not retried at all until that month is opened again.
 */
class GatewayPaymentsUnbookedSource extends Source
{
    /** How long the job is left to book a payment by itself before a person is told. */
    public const GRACE_MINUTES = 30;

    public function key(): string
    {
        return 'gateway_payment_unbooked';
    }

    public function area(): string
    {
        return 'money';
    }

    protected function permissions(): array
    {
        return ['accounting.view'];
    }

    public function items(User $user, int $businessId): array
    {
        return GatewayPayment::query()
            ->withoutGlobalScopes()
            ->with(['salesOrder' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'shop_reference'])])
            ->where('business_id', $businessId)
            ->where('status', GatewayPaymentStatus::Proven->value)
            ->where('proven_at', '<=', Carbon::now()->subMinutes(self::GRACE_MINUTES))
            ->orderBy('proven_at')
            ->get()
            ->map(fn (GatewayPayment $payment) => $this->item(
                (string) $payment->id,
                AttentionItem::CRITICAL,
                'gateway_payment_unbooked',
                'Online payment {transaction} for order {order} was paid but is not in the books: {problem}',
                [
                    'transaction' => (string) $payment->tran_id,
                    'order' => (string) ($payment->salesOrder?->shop_reference ?? $payment->sales_order_id),
                    'problem' => (string) ($payment->problem ?? 'still waiting to be booked.'),
                ],
                '/sales/orders/'.$payment->sales_order_id,
                ['since' => $payment->proven_at, 'ownerRole' => 'Finance'],
            ))
            ->values()
            ->all();
    }
}
