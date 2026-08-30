<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Actions\PlanCampaignSend;
use App\Modules\Crm\Actions\SendCampaign;
use App\Modules\Crm\Enums\CampaignStatus;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageDelivery;
use App\Modules\Crm\Models\MessageOptOut;
use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Crm\Models\MessagingSetting;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Sending to the phone book: one page per channel, one engine underneath.
 *
 * ⚠⚠ THE THRESHOLD ABOVE WHICH A SEND NEEDS APPROVING IS A SETTING, not a
 * constant. Joy confirmed 50 as the default on 30 August 2026 and asked that the
 * number itself be changeable, so it lives in `messaging_settings` and moving it
 * is a form, not a deploy. It errs small on purpose: a threshold set too high is
 * discovered by a bill, one set too low is discovered by somebody clicking
 * approve, and the second is the cheaper mistake.
 *
 * ⚠ Read through MessagingSetting::approvalAbove() every time rather than
 * cached on the instance - a threshold that was raised this morning must apply
 * to the send being planned this afternoon.
 */
class MessagingController extends Controller
{
    public function index(Request $request, string $channel, PlanCampaignSend $planner): Response
    {
        abort_unless(in_array($channel, ['sms', 'email'], true), 404);

        $campaigns = MessageCampaign::query()
            ->where('channel', $channel)
            ->with(['group:id,name', 'provider:id,name', 'approvalRequest'])
            ->withCount([
                'deliveries as sent_count' => fn ($q) => $q->where('status', 'sent'),
                'deliveries as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (MessageCampaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subject' => $c->subject,
                'body' => $c->body,
                'group' => $c->group?->name,
                'group_id' => $c->contact_group_id,
                'audience_type' => $c->audience_type,
                'provider' => $c->provider?->name,
                'provider_id' => $c->message_provider_id,
                'cap' => $c->recipient_cap,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'editable' => $c->status->isEditable(),
                'awaiting_approval' => $c->approvalRequest !== null
                    && $c->approvalRequest->status === ApprovalStatus::Pending,
                'sent_count' => $c->sent_count,
                'failed_count' => $c->failed_count,
                'estimated_cost' => $c->estimated_cost,
                'sent_at' => $c->sent_at?->format('j M Y, g:i a'),
            ]);

        return Inertia::render('crm/messaging/index', [
            'channel' => $channel,
            'campaigns' => $campaigns,
            'groups' => ContactGroup::query()->where('is_active', true)->orderBy('name')
                ->withCount('contacts')->get()
                ->map(fn (ContactGroup $g) => ['id' => $g->id, 'name' => $g->name, 'contacts_count' => $g->contacts_count]),
            'providers' => MessageProvider::query()->forChannel($channel)->orderBy('priority')->get()
                ->map(fn (MessageProvider $p) => [
                    'id' => $p->id, 'name' => $p->name, 'is_active' => $p->is_active,
                    'cost_per_part' => $p->cost_per_part,
                ]),
            'optOuts' => MessageOptOut::query()->where('channel', $channel)->latest('id')->limit(100)
                ->get(['id', 'address', 'reason', 'created_at'])
                ->map(fn (MessageOptOut $o) => [
                    'id' => $o->id, 'address' => $o->address, 'reason' => $o->reason,
                    'at' => $o->created_at?->toDateString(),
                ]),
            'approvalAbove' => MessagingSetting::approvalAbove(),
            'can' => [
                'send' => $request->user()->can('crm.campaigns.send'),
                'approve' => $request->user()->can('crm.campaigns.approve'),
            ],
        ]);
    }

    public function store(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, ['sms', 'email'], true), 404);

        MessageCampaign::create(array_merge($this->rules($request, $channel), [
            'business_id' => app(BusinessContext::class)->id(),
            'channel' => $channel,
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Draft saved. Preview it before sending.');
    }

    public function update(Request $request, MessageCampaign $campaign): RedirectResponse
    {
        // ⚠⚠ A sent campaign is history. Editing one would rewrite what was
        // approved and what went out, and the delivery rows would then describe
        // a message nobody can read any more.
        abort_unless($campaign->status->isEditable(), 403, 'This campaign has already gone out and cannot be edited.');

        $campaign->update($this->rules($request, $campaign->channel));

        return back()->with('success', 'Draft saved.');
    }

    /**
     * The preview. Writes nothing except the figures it just worked out.
     */
    public function preview(Request $request, MessageCampaign $campaign, PlanCampaignSend $planner): RedirectResponse
    {
        $plan = $planner->handle($campaign);

        // ⚠ The estimate is stored so approval has a number to approve, and so
        // the provider's monthly bill can later be compared with what somebody
        // actually agreed to.
        $campaign->update([
            'estimated_recipients' => $plan['count'],
            'estimated_parts' => $plan['parts_total'],
            'estimated_cost' => $plan['cost'],
        ]);

        return back()->with('campaignPreview', [
            'campaign_id' => $campaign->id,
            'count' => $plan['count'],
            'parts_each' => $plan['parts_each'],
            'parts_total' => $plan['parts_total'],
            'characters' => $plan['characters'],
            'alphabet' => $plan['alphabet'],
            'cost' => $plan['cost'],
            'opted_out' => $plan['opted_out'],
            'unreachable' => $plan['unreachable'],
            'duplicates' => $plan['duplicates'],
            'over_cap' => $plan['over_cap'],
            'needs_approval' => $plan['count'] > MessagingSetting::approvalAbove(),
        ]);
    }

    /**
     * Send it, or raise it for approval if it is big enough to need one.
     */
    public function send(Request $request, MessageCampaign $campaign, PlanCampaignSend $planner, SendCampaign $sender): RedirectResponse
    {
        $plan = $planner->handle($campaign);

        if ($plan['over_cap']) {
            return back()->withErrors(['send' => "This would go to {$plan['count']} people and the cap is {$campaign->recipient_cap}."]);
        }

        // ⚠⚠ THE THIRD BRAKE. Above the threshold this does NOT send: it raises
        // an approval and stops. The person who pressed the button is told so
        // plainly, because a button that silently does something else is worse
        // than a button that refuses.
        if ($plan['count'] > MessagingSetting::approvalAbove() && $campaign->approvalRequest === null) {
            $campaign->update([
                'status' => CampaignStatus::PendingApproval,
                'estimated_recipients' => $plan['count'],
                'estimated_parts' => $plan['parts_total'],
                'estimated_cost' => $plan['cost'],
            ]);

            $campaign->submitForApproval($request->user());

            return back()->with('success', "{$plan['count']} recipients is above {$this->threshold()}, so this has gone for approval rather than out.");
        }

        try {
            $result = $sender->handle($campaign);
        } catch (RuntimeException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Sent to %d, %d failed, %d skipped for having asked not to be contacted.',
            $result['sent'], $result['failed'], $result['skipped'],
        ));
    }

    public function approve(Request $request, MessageCampaign $campaign, SendCampaign $sender): RedirectResponse
    {
        $this->authorize('crm.campaigns.approve');

        $approval = $campaign->approvalRequest;

        abort_if($approval === null, 404);

        $approval->update(['status' => ApprovalStatus::Approved, 'reviewed_by' => $request->user()->id]);
        $campaign->update(['status' => CampaignStatus::Approved]);

        // ⚠ Approving does NOT send. Somebody still presses send, because
        // approval is permission to spend and not an instruction to spend now.
        return back()->with('success', "{$campaign->name} approved. It still has to be sent.");
    }

    public function reject(Request $request, MessageCampaign $campaign, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('crm.campaigns.approve');

        $approval = $campaign->approvalRequest;

        abort_if($approval === null, 404);

        $approval->update(['status' => ApprovalStatus::Rejected, 'reviewed_by' => $request->user()->id]);
        $campaign->update(['status' => CampaignStatus::Cancelled]);

        return back()->with('success', "{$campaign->name} rejected.");
    }

    /**
     * Record that somebody asked not to be contacted.
     */
    public function optOut(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, ['sms', 'email'], true), 404);

        $data = $request->validate([
            'address' => ['required', 'string', 'max:191'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $address = MessageOptOut::normalise($channel, $data['address']);

        if ($address === null) {
            return back()->withErrors(['address' => 'That is not a number or an address this could ever have sent to.']);
        }

        MessageOptOut::updateOrCreate(
            [
                'business_id' => app(BusinessContext::class)->id(),
                'channel' => $channel,
                'address' => $address,
            ],
            ['reason' => $data['reason'] ?? null],
        );

        return back()->with('success', 'Recorded. Nothing will be sent to that address again.');
    }

    public function deliveries(Request $request, MessageCampaign $campaign): Response
    {
        return Inertia::render('crm/messaging/deliveries', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'channel' => $campaign->channel,
                'status_label' => $campaign->status->label(),
                'sent_at' => $campaign->sent_at?->format('j M Y, g:i a'),
                'estimated_cost' => $campaign->estimated_cost,
            ],
            'deliveries' => $campaign->deliveries()->with('contact:id,name')->latest('id')->paginate(100)
                ->through(fn (MessageDelivery $d) => [
                    'id' => $d->id,
                    'name' => $d->contact?->name ?? 'Removed from the phone book',
                    'to' => $d->to_address,
                    'status' => $d->status,
                    'error' => $d->error,
                    'parts' => $d->parts,
                    'cost' => $d->cost,
                    'sent_at' => $d->sent_at?->format('j M, g:i a'),
                ]),
        ]);
    }

    private function threshold(): string
    {
        return MessagingSetting::approvalAbove().' recipients';
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(Request $request, string $channel): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // ⚠ Only email carries a subject, and requiring one for SMS would be
            // asking for something that has nowhere to go.
            'subject' => [$channel === 'email' ? 'required' : 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'audience_type' => ['required', Rule::in(['group', 'all'])],
            'contact_group_id' => ['nullable', Rule::exists('contact_groups', 'id')],
            'message_provider_id' => ['nullable', Rule::exists('message_providers', 'id')],
            'recipient_cap' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
    }
}
