<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Facades\DB;

/**
 * Courier webhook pairing that failed, or will (the pairing guard, 11 Oct
 * 2026). The courier's connect button only says "Validation error"; this says
 * why and what to do, per account:
 *
 *   missing echo    the courier called, our answer lacked the value it shows on
 *                   its own webhook page, because none is saved (high)
 *   retry           that value is saved now: press connect again (normal)
 *   bad secret      the courier's call did not carry our webhook secret (high)
 *   not ready       an active account with no reply value saved and no update
 *                   ever received: pairing it would fail (normal)
 *
 * ⚠ A failed attempt followed by an authentic status update is history, not a
 * problem: the courier is talking to us.
 *
 * ⚠ Courier accounts are not business-scoped; an account shows here when it
 * serves this business, or no business yet (being set up). Owner only.
 */
class CourierWebhookPairingSource extends Source
{
    public function key(): string
    {
        return 'courier_webhook_pairing';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['couriers.settings.manage'];
    }

    public function items(User $user, int $businessId): array
    {
        $linked = DB::table('courier_account_business')->select('courier_account_id')->distinct()->pluck('courier_account_id')->map(fn ($id) => (int) $id)->all();
        $here = DB::table('courier_account_business')->where('business_id', $businessId)->pluck('courier_account_id')->map(fn ($id) => (int) $id)->all();

        $accounts = CourierAccount::query()->with('provider')->orderBy('id')->get()
            ->filter(fn (CourierAccount $a) => in_array((int) $a->id, $here, true) || ! in_array((int) $a->id, $linked, true));

        $items = [];

        foreach ($accounts as $account) {
            $definition = $account->definition();
            $header = $definition->integrationEchoHeader();
            $params = [
                'account' => trim(($account->provider?->name ?? '')." '".$account->label."'"),
                'page' => (string) ($definition->integrationEchoPage() ?? ''),
                'header' => (string) ($header ?? ''),
            ];
            $id = (int) $account->id;
            $tried = $account->webhook_last_handshake_at;
            $heardSince = $tried !== null && $account->webhook_last_event_at !== null && $account->webhook_last_event_at->gte($tried);
            $extra = ['since' => $tried, 'ownerRole' => 'Owner'];

            if ($tried !== null && $account->webhook_last_handshake_ok === false && ! $heardSince) {
                $problem = $account->webhook_last_handshake_problem;

                if ($problem === CourierAccount::PAIRING_MISSING_ECHO && $account->needsIntegrationEcho()) {
                    $items[] = $this->item(
                        "{$id}:missing_echo",
                        AttentionItem::HIGH,
                        'courier_pairing_missing_echo',
                        "{account} tried to connect its webhook but the courier's reply value is missing. Open {page}, copy the value from the line \"The header {header} value should be exactly ...\", paste it in Settings > Couriers > Edit > Courier's reply value, save, then press connect on the courier's page again.",
                        $params,
                        '/settings/couriers',
                        $extra,
                    );
                } elseif ($problem === CourierAccount::PAIRING_MISSING_ECHO) {
                    $items[] = $this->item(
                        "{$id}:retry",
                        AttentionItem::NORMAL,
                        'courier_pairing_retry',
                        "{account}: the courier's reply value is saved now. Press connect on {page} again to finish pairing the webhook.",
                        $params,
                        '/settings/couriers',
                        $extra,
                    );
                } elseif ($problem === CourierAccount::PAIRING_BAD_SIGNATURE) {
                    $items[] = $this->item(
                        "{$id}:bad_secret",
                        AttentionItem::HIGH,
                        'courier_pairing_bad_secret',
                        "{account} tried to connect its webhook but the webhook secret in the courier's form does not match the ERP. Copy the secret again from Settings > Couriers (Rotate webhook secret shows a new one once), paste it in the courier's form, then connect again.",
                        $params,
                        '/settings/couriers',
                        $extra,
                    );
                }

                continue;
            }

            if ($account->is_active && $tried === null && $account->webhook_last_event_at === null && $account->needsIntegrationEcho()) {
                $items[] = $this->item(
                    "{$id}:not_ready",
                    AttentionItem::NORMAL,
                    'courier_pairing_not_ready',
                    "{account}: the courier's reply value is not saved, so connecting its webhook would fail with \"Validation error\". Copy it from {page} into Settings > Couriers first.",
                    $params,
                    '/settings/couriers',
                    ['since' => $account->activated_at, 'ownerRole' => 'Owner'],
                );
            }
        }

        return $items;
    }
}
