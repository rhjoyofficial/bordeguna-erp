<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\CampaignStatus;
use App\Modules\Crm\Messaging\DriverRegistry;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageDelivery;
use App\Modules\Crm\Models\MessageOptOut;
use App\Modules\Crm\Models\MessagingSetting;
use App\Modules\Crm\Support\SmsText;
use App\Modules\Foundation\Enums\ApprovalStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
    /** CRM-004 (14 Oct 2026): no progress for this long means the send died. */
    public const STALLED_AFTER_MINUTES = 15;

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

        // CRM-001 (audit 14 Oct 2026): only a draft or an approved campaign
        // can go out. A cancelled (rejected) one, or one still waiting for
        // approval, is refused here whatever route reached this class.
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Approved], true)) {
            throw new RuntimeException("This campaign is {$campaign->status->label()} and cannot be sent.");
        }

        // ⚠⚠ APPROVAL IS CHECKED HERE, not only on the button. A campaign that
        // needs approval and has not got it must be unsendable by every route
        // into this class.
        $request = $campaign->approvalRequest;

        //
        // CRM-002 (14 Oct 2026): a request handed back by an edit is no
        // approval; a Draft carrying one sends only if it no longer needs
        // approval (the controller raises it again above the threshold).
        $handedBack = $request?->status === ApprovalStatus::Returned && $campaign->status === CampaignStatus::Draft;

        if ($request !== null && $request->status !== ApprovalStatus::Approved && ! $handedBack) {
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

        // ⚠⚠ Bug hunt 16 Oct 2026: approval was given for a number of people.
        // If the audience has grown since (a group import, say), nobody
        // approved that spend, so it is refused here as resume() refuses it.
        if ($request?->status === ApprovalStatus::Approved && $plan['count'] > (int) $campaign->estimated_recipients) {
            throw new RuntimeException(
                "The audience is now {$plan['count']} people, more than the {$campaign->estimated_recipients} it was approved for. "
                .'Edit it so the new size is approved, or narrow the audience.'
            );
        }

        // ⚠⚠ CRM-003 (audit 14 Oct 2026). CLAIMED, NOT JUST SET. Two presses
        // of Send both read "draft" from their own copy before either wrote
        // "sending", and both sent the whole list. The conditional update is
        // atomic in the database: exactly one request moves the row out of
        // Draft/Approved, and the other gets zero rows and is refused.
        $claimed = MessageCampaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Approved->value])
            ->update(['status' => CampaignStatus::Sending->value]);

        if ($claimed === 0) {
            throw new RuntimeException('This campaign is already going out, or has gone. Refresh to see where it is.');
        }

        $campaign->status = CampaignStatus::Sending;
        $campaign->syncOriginalAttribute('status');

        $result = $this->deliver($campaign, $plan['recipients']);

        $this->finish($campaign);

        return $result;
    }

    /**
     * CRM-004 (audit 14 Oct 2026). Finish a send that died part-way.
     *
     * ⚠⚠ The send runs inside the web request, one provider call per person,
     * and a timeout, a restart or a slow gateway kills it with the campaign
     * still saying Sending. Nothing could move it after that: Send refused
     * ("already going out"), Edit refused, and a fresh copy would message the
     * people already reached a second time. Resume sends ONLY to addresses
     * with no delivery row on this campaign, sent or failed: a failure can be
     * a timeout the provider still delivered, and a second charge is the
     * mistake this module exists to prevent. Failed rows stay visible on
     * "Who got it" for somebody to follow up by hand.
     *
     * ⚠ Offered only once the send has made no progress for
     * STALLED_AFTER_MINUTES, which no live send can reach (one call times out
     * at 20 s, a request dies at 300 s). The row is locked and touched as the
     * claim, so a second press inside the window finds it fresh and refuses.
     *
     * ⚠ The approval rule still holds: the audience is planned again, and if
     * it has grown past what was approved (or, with no approval, past the
     * threshold) resuming is refused. Mark it stopped and send a copy instead.
     *
     * @return array{sent:int, failed:int, skipped:int, already:int}
     */
    public function resume(MessageCampaign $campaign): array
    {
        [$locked, $plan, $remaining] = DB::transaction(function () use ($campaign) {
            $locked = $this->lockStalled($campaign);

            $provider = $locked->provider;

            if ($provider === null || ! $provider->is_active) {
                throw new RuntimeException('No active provider is set for this campaign, so there is nothing to finish it with.');
            }

            $plan = $this->planner->handle($locked);

            if ($plan['over_cap']) {
                throw new RuntimeException("This would now go to {$plan['count']} people and the cap is {$locked->recipient_cap}. Mark it stopped instead.");
            }

            $request = $locked->approvalRequest;
            $limit = $request?->status === ApprovalStatus::Approved
                ? (int) $locked->estimated_recipients
                : MessagingSetting::approvalAbove();

            if ($plan['count'] > $limit) {
                throw new RuntimeException(
                    "The audience is now {$plan['count']} people, more than the {$limit} it was cleared to go to. "
                    .'Mark it stopped and send a copy, so the new size is approved.'
                );
            }

            // The claim: a fresh updated_at makes the row look busy to anyone else.
            $locked->touch();

            return [$locked, $plan, $this->remaining($locked, $plan['recipients'])];
        });

        $result = $this->deliver($locked, $remaining);

        $this->finish($locked);

        return $result + ['already' => $plan['count'] - count($remaining)];
    }

    /**
     * CRM-004 (audit 14 Oct 2026). Close a stalled send by hand, with a reason.
     * Every delivery row is kept; the campaign's figures become what actually
     * went out, and the reason and counts go to the activity log.
     *
     * @return array{sent:int, failed:int, unsent:int}
     */
    public function stop(MessageCampaign $campaign, User $by, string $reason): array
    {
        return DB::transaction(function () use ($campaign, $by, $reason) {
            $locked = $this->lockStalled($campaign);

            $counts = [
                'sent' => $locked->deliveries()->where('status', 'sent')->count(),
                'failed' => $locked->deliveries()->where('status', 'failed')->count(),
                'unsent' => count($this->remaining($locked, $this->planner->handle($locked)['recipients'])),
            ];

            $locked->update(['status' => CampaignStatus::Stopped] + $this->figures($locked));

            activity()
                ->performedOn($locked)
                ->causedBy($by)
                ->withProperties(['reason' => $reason] + $counts)
                ->log('campaign stopped part-way');

            return $counts;
        });
    }

    /** Last sign of life: the newest delivery row, or the claim itself. */
    public static function lastProgressAt(MessageCampaign $campaign): CarbonInterface
    {
        $touched = $campaign->updated_at ?? now();
        $latest = $campaign->deliveries()->max('created_at');

        if ($latest === null) {
            return $touched;
        }

        $latest = Carbon::parse($latest);

        return $latest->greaterThan($touched) ? $latest : $touched;
    }

    public static function isStalled(MessageCampaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::Sending
            && self::lastProgressAt($campaign)->lessThanOrEqualTo(now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }

    /**
     * The planned recipients this campaign has no delivery row for yet.
     *
     * @param  list<array{contact_id:int|null, name:string, address:string}>  $recipients
     * @return list<array{contact_id:int|null, name:string, address:string}>
     */
    public function remaining(MessageCampaign $campaign, array $recipients): array
    {
        $tried = $campaign->deliveries()->pluck('to_address')->flip();

        return array_values(array_filter($recipients, fn (array $r) => ! $tried->has($r['address'])));
    }

    /** Must be called inside a transaction. */
    private function lockStalled(MessageCampaign $campaign): MessageCampaign
    {
        $locked = MessageCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== CampaignStatus::Sending) {
            throw new RuntimeException("This campaign is {$locked->status->label()}, not stuck sending, so there is nothing to finish.");
        }

        if (! self::isStalled($locked)) {
            throw new RuntimeException(
                'This send has moved in the last '.self::STALLED_AFTER_MINUTES.' minutes and may still be going out. Wait, then refresh.'
            );
        }

        return $locked;
    }

    /**
     * One provider call and one delivery row per recipient.
     *
     * @param  list<array{contact_id:int|null, name:string, address:string}>  $recipients
     * @return array{sent:int, failed:int, skipped:int}
     */
    private function deliver(MessageCampaign $campaign, array $recipients): array
    {
        $provider = $campaign->provider;
        $driver = $this->drivers->make($provider->driver);
        $costPerPart = (float) $provider->cost_per_part;
        $parts = $campaign->channel === 'sms' ? SmsText::measure($campaign->body)['parts'] : 1;

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($recipients as $recipient) {
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

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Mark it Sent with the figures of every accepted delivery row, the first
     * run and any resume together.
     *
     * CRM-004 (14 Oct 2026): only while it still says Sending, so a run that
     * outlived a "Mark stopped" never turns a Stopped campaign back into Sent.
     */
    private function finish(MessageCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            $locked = MessageCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CampaignStatus::Sending) {
                return;
            }

            $locked->update(['status' => CampaignStatus::Sent, 'sent_at' => now()] + $this->figures($locked));

            $campaign->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * ⚠ What it ACTUALLY went to, frozen for comparison with the provider's
     * monthly bill: the accepted delivery rows, whichever run wrote them.
     *
     * @return array{estimated_recipients:int, estimated_parts:int, estimated_cost:float}
     */
    private function figures(MessageCampaign $campaign): array
    {
        $sentRows = $campaign->deliveries()->where('status', 'sent');

        return [
            'estimated_recipients' => (clone $sentRows)->count(),
            'estimated_parts' => (int) (clone $sentRows)->sum('parts'),
            'estimated_cost' => round((float) (clone $sentRows)->sum('cost'), 2),
        ];
    }
}
