<?php

namespace App\Modules\Crm\Actions;

use App\Modules\Crm\Enums\CampaignStatus;
use App\Modules\Crm\Messaging\DriverRegistry;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageDelivery;
use App\Modules\Crm\Models\MessageOptOut;
use App\Modules\Crm\Support\SmsText;
use App\Modules\Foundation\Enums\ApprovalStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Actually sends, and is the only thing that does.
 *
 * ⚠⚠ EVERY REFUSAL BELOW IS A REFUSAL TO SPEND MONEY, and each is here rather
 * than on the screen because the screen will not be the only caller. A scheduled
 * send, a retry, or a future API all arrive here.
 *
 * ⚠⚠ THE OPT-OUT REGISTER IS READ AGAIN, at the moment of sending. The plan was
 * made minutes or days ago and somebody may have asked to be removed since. A
 * preview is not a licence to ignore a later request.
 *
 * ⚠ Sending is not transactional and must not be. A message handed to a provider
 * cannot be rolled back, so each delivery row is written as it happens; a crash
 * halfway leaves an accurate record of what did and did not go out, which is
 * exactly what somebody needs in order to finish the job by hand.
 */
class SendCampaign
{
    public function __construct(
        private readonly PlanCampaignSend $planner,
        private readonly DriverRegistry $drivers,
    ) {}

    /**
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function handle(MessageCampaign $campaign): array
    {
        if ($campaign->status === CampaignStatus::Sent) {
            throw new RuntimeException('This campaign has already been sent. Copy it if you want to send it again.');
        }

        if ($campaign->status === CampaignStatus::Sending) {
            throw new RuntimeException('This campaign is already going out.');
        }

        // ⚠⚠ APPROVAL IS CHECKED HERE, not only on the button. A campaign that
        // needs approval and has not got it must be unsendable by every route
        // into this class.
        $request = $campaign->approvalRequest;

        if ($request !== null && $request->status !== ApprovalStatus::Approved) {
            throw new RuntimeException('This campaign is waiting for approval and cannot be sent yet.');
        }

        $provider = $campaign->provider;

        if ($provider === null || ! $provider->is_active) {
            throw new RuntimeException('No active provider is set for this campaign, so there is nothing to send it with.');
        }

        $plan = $this->planner->handle($campaign);

        if ($plan['over_cap']) {
            throw new RuntimeException(
                "This would go to {$plan['count']} people and the cap is {$campaign->recipient_cap}. "
                .'Raise the cap deliberately, or narrow the audience.'
            );
        }

        if ($plan['count'] === 0) {
            throw new RuntimeException('Nobody in this audience can be reached, so there is nothing to send.');
        }

        $driver = $this->drivers->make($provider->driver);
        $costPerPart = (float) $provider->cost_per_part;
        $parts = $campaign->channel === 'sms' ? SmsText::measure($campaign->body)['parts'] : 1;

        $campaign->update(['status' => CampaignStatus::Sending]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($plan['recipients'] as $recipient) {
            // ⚠⚠ Re-read per recipient. See the class note.
            $key = MessageOptOut::normalise($campaign->channel, $recipient['address']);

            $optedOutSince = MessageOptOut::query()
                ->where('channel', $campaign->channel)
                ->where('address', $key)
                ->exists();

            if ($optedOutSince) {
                $skipped++;

                continue;
            }

            $result = $driver->send($provider, $recipient['address'], $campaign->body, $campaign->subject);

            MessageDelivery::create([
                'business_id' => $campaign->business_id,
                'message_campaign_id' => $campaign->id,
                'contact_id' => $recipient['contact_id'],
                'to_address' => $recipient['address'],
                // ⚠⚠ `sent`, never `delivered`. Acceptance by a provider is not
                // arrival at a handset, and several providers never say which.
                'status' => $result->accepted ? 'sent' : 'failed',
                'provider_message_id' => $result->providerMessageId,
                'error' => $result->error,
                'parts' => $parts,
                'cost' => round($parts * $costPerPart, 4),
                'sent_at' => $result->accepted ? now() : null,
            ]);

            $result->accepted ? $sent++ : $failed++;
        }

        DB::transaction(function () use ($campaign, $parts, $costPerPart, $sent) {
            $campaign->update([
                'status' => CampaignStatus::Sent,
                'sent_at' => now(),
                // ⚠ What it ACTUALLY went to, frozen for comparison with the
                // provider's monthly bill.
                'estimated_recipients' => $sent,
                'estimated_parts' => $parts * $sent,
                'estimated_cost' => round($parts * $sent * $costPerPart, 2),
            ]);
        });

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }
}
