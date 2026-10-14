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
use App\Modules\Foundation\Exceptions\ApprovalAlreadyDecidedException;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request, string $channel, PlanCampaignSend $planner, SendCampaign $sender): Response
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
                // CRM-004 (14 Oct 2026): a send that died part-way. Planned
                // only for a stuck one, so the list stays one query per page.
                ...$this->stuckState($c, $planner, $sender),
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

        $data = $this->rules($request, $campaign->channel);

        // ⚠⚠ CRM-002 (audit 14 Oct 2026). WHAT IS APPROVED IS WHAT GOES OUT.
        // A campaign waiting for approval used to take any edit (text,
        // audience, cap) while the approver's card still showed the count and
        // cost it was submitted with, so the second-person brake approved
        // figures that no longer described the send. An edit now hands the
        // pending request back (a recorded decision by the person who edited,
        // never a silent delete) and returns the campaign to Draft; the next
        // Send plans it afresh and raises approval again on the new figures.
        $returned = DB::transaction(function () use ($campaign, $data, $request) {
            $locked = MessageCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status->isEditable(), 403, 'This campaign has already gone out and cannot be edited.');

            $wasPending = $locked->status === CampaignStatus::PendingApproval;

            if ($wasPending && $locked->approvalRequest?->status === ApprovalStatus::Pending) {
                $locked->approvalRequest->sendBack($request->user(), 'Edited after it was sent for approval; it needs approval again.');
            }

            $locked->update($data + ($wasPending ? [
                'status' => CampaignStatus::Draft,
                'estimated_recipients' => 0,
                'estimated_parts' => 0,
                'estimated_cost' => 0,
            ] : []));

            return $wasPending;
        });

        return back()->with('success', $returned
            ? 'Saved. Because it changed after it was sent for approval, it is a draft again and will need approval again when you send it.'
            : 'Draft saved.');
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
        //
        // CRM-002 (14 Oct 2026): a request handed back by an edit counts as
        // no approval at all, so the edited campaign asks again on the same
        // request row (resubmit), with the figures it will really be sent with.
        $approval = $campaign->approvalRequest;

        if ($plan['count'] > MessagingSetting::approvalAbove()
            && ($approval === null || $approval->status === ApprovalStatus::Returned)) {
            $campaign->update([
                'status' => CampaignStatus::PendingApproval,
                'estimated_recipients' => $plan['count'],
                'estimated_parts' => $plan['parts_total'],
                'estimated_cost' => $plan['cost'],
            ]);

            $campaign->resubmitForApproval($request->user());

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

    /**
     * CRM-004 (audit 14 Oct 2026). Finish a send that died part-way, for the
     * people it never reached. The refusals live in SendCampaign::resume.
     */
    public function resume(MessageCampaign $campaign, SendCampaign $sender): RedirectResponse
    {
        try {
            $result = $sender->resume($campaign);
        } catch (RuntimeException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Finished: sent to %d more, %d failed, %d skipped for having asked not to be contacted. %d had already been tried and were left alone.',
            $result['sent'], $result['failed'], $result['skipped'], $result['already'],
        ));
    }

    /**
     * CRM-004 (audit 14 Oct 2026). Close a stalled send by hand. The reason is
     * required because a stopped campaign is a record somebody will ask about.
     */
    public function stop(Request $request, MessageCampaign $campaign, SendCampaign $sender): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $counts = $sender->stop($campaign, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            '%s stopped: %d sent, %d failed, %d not sent.',
            $campaign->name, $counts['sent'], $counts['failed'], $counts['unsent'],
        ));
    }

    /**
     * @return array{stuck:bool, unsent_count:int|null}
     */
    private function stuckState(MessageCampaign $campaign, PlanCampaignSend $planner, SendCampaign $sender): array
    {
        if (! SendCampaign::isStalled($campaign)) {
            return ['stuck' => false, 'unsent_count' => null];
        }

        return [
            'stuck' => true,
            'unsent_count' => count($sender->remaining($campaign, $planner->handle($campaign)['recipients'])),
        ];
    }

    public function approve(Request $request, MessageCampaign $campaign, SendCampaign $sender): RedirectResponse
    {
        $this->authorize('crm.campaigns.approve');

        $approval = $campaign->approvalRequest;

        abort_if($approval === null, 404);

        // ⚠⚠ CRM-001 (audit 14 Oct 2026). ONE DECISION, ON A PENDING REQUEST.
        // This used to write Approved over whatever was there, so a second
        // press from a stale approvals tab turned a SENT campaign back into
        // "Approved, not sent", the Send button came back, and the next press
        // messaged the whole audience again (60 deliveries became 120). It
        // also revived a rejected one. Now the campaign row is locked, it must
        // still be waiting for approval, and the decision goes through the
        // approval engine, which refuses anything no longer Pending.
        if ($refusal = $this->decide($campaign, $approval, $request, approve: true)) {
            return $refusal;
        }

        // ⚠ Approving does NOT send. Somebody still presses send, because
        // approval is permission to spend and not an instruction to spend now.
        return back()->with('success', "{$campaign->name} approved. It still has to be sent.");
    }

    public function reject(Request $request, MessageCampaign $campaign, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('crm.campaigns.approve');

        $approval = $campaign->approvalRequest;

        abort_if($approval === null, 404);

        // CRM-001 (14 Oct 2026): the same one-decision rule as approve().
        if ($refusal = $this->decide($campaign, $approval, $request, approve: false)) {
            return $refusal;
        }

        return back()->with('success', "{$campaign->name} rejected.");
    }

    /**
     * The approve or reject decision, made once (CRM-001, 14 Oct 2026).
     *
     * Returns a refusal to show, or null when the decision was recorded.
     */
    private function decide(MessageCampaign $campaign, ApprovalRequest $approval, Request $request, bool $approve): ?RedirectResponse
    {
        try {
            return DB::transaction(function () use ($campaign, $approval, $request, $approve) {
                $locked = MessageCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== CampaignStatus::PendingApproval) {
                    return back()->withErrors([
                        'approval' => "{$locked->name} is {$locked->status->label()}, not waiting for approval, so there is nothing to decide.",
                    ]);
                }

                if ($approve) {
                    // A later stage still to go keeps it waiting.
                    if ($approval->approve($request->user())) {
                        $locked->update(['status' => CampaignStatus::Approved]);
                    }
                } else {
                    $approval->reject($request->user());
                    $locked->update(['status' => CampaignStatus::Cancelled]);
                }

                return null;
            });
        } catch (ApprovalAlreadyDecidedException) {
            return back()->withErrors([
                'approval' => "{$campaign->name} has already been decided by somebody else. Refresh to see what happened.",
            ]);
        }
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
            // CRM-008 (audit 14 Oct 2026): this business's own group and
            // provider only (C-CRM-1). A bare exists rule stored another
            // business's id, which then failed later as "nobody" or "no
            // provider" with no hint why.
            'contact_group_id' => ['nullable', Rule::exists('contact_groups', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'message_provider_id' => ['nullable', Rule::exists('message_providers', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'recipient_cap' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
    }
}
