<?php

namespace App\Modules\Crm\Actions;

use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageOptOut;
use App\Modules\Crm\Support\SmsText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Exactly who would receive this, what it would cost, and who would be left out.
 * Writes nothing.
 *
 * ⚠⚠ THIS IS THE FIRST BRAKE, AND IT IS NOT OPTIONAL. A send is the only thing
 * in this ERP that spends money by itself, and the failure that costs real money
 * is an audience larger than anybody intended. Nothing may be sent that has not
 * been planned first, and the plan is what approval approves.
 *
 * C-SEND-1  OPT-OUTS ARE APPLIED AT PLAN TIME AND AGAIN AT SEND TIME. Somebody
 *           may ask to be removed between the preview and the send, and the
 *           preview is not a promise to ignore them.
 * C-SEND-2  ONE MESSAGE PER PERSON, not per row. A household sharing a number is
 *           legitimate and appears twice in the phone book on purpose, but
 *           sending twice to the same handset is a duplicate charge and an
 *           annoyance. Deduplicated on the ADDRESS, which is what actually gets
 *           messaged.
 * C-SEND-3  INACTIVE CONTACTS ARE NEVER INCLUDED. Deactivating a contact is how
 *           somebody says "stop including this person"; if a send ignored it,
 *           deactivation would mean nothing.
 * C-SEND-4  A CONTACT WITH NO ADDRESS FOR THIS CHANNEL IS COUNTED AND EXCLUDED,
 *           never silently dropped. "I sent to the 200 dealers" and "180 of them
 *           have an email" are different facts and the screen must show both.
 * C-SEND-5  THE CAP IS A REFUSAL, NOT A TRUNCATION. Sending the first 500 of an
 *           unexpected 5000 hides the mistake and still spends money.
 */
class PlanCampaignSend
{
    /**
     * @return array{
     *   recipients: list<array{contact_id:int|null, name:string, address:string}>,
     *   count:int, parts_each:int, parts_total:int, characters:int, alphabet:string,
     *   cost:float, opted_out:int, unreachable:int, duplicates:int, over_cap:bool
     * }
     */
    public function handle(MessageCampaign $campaign): array
    {
        $channel = $campaign->channel;

        $contacts = $this->audience($campaign);

        $suppressed = MessageOptOut::query()
            ->where('channel', $channel)
            ->pluck('address')
            ->flip();

        $recipients = [];
        $seen = [];
        $optedOut = 0;
        $unreachable = 0;
        $duplicates = 0;

        foreach ($contacts as $contact) {
            $address = $channel === 'sms' ? $contact->phone_normalized : $contact->email;

            // C-SEND-4
            if ($address === null || trim((string) $address) === '') {
                $unreachable++;

                continue;
            }

            $key = MessageOptOut::normalise($channel, (string) $address);

            if ($key === null) {
                $unreachable++;

                continue;
            }

            // C-SEND-1
            if ($suppressed->has($key)) {
                $optedOut++;

                continue;
            }

            // C-SEND-2
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }

            $seen[$key] = true;

            $recipients[] = [
                'contact_id' => $contact->id,
                'name' => $contact->name,
                // ⚠ The address as it will actually be used, not the pretty one.
                'address' => $channel === 'sms' ? (string) $contact->phone_normalized : (string) $contact->email,
            ];
        }

        // ⚠ Email has no part arithmetic; only SMS is priced per part.
        $measure = $channel === 'sms'
            ? SmsText::measure($campaign->body)
            : ['alphabet' => 'n/a', 'characters' => mb_strlen($campaign->body), 'parts' => 1, 'per_part' => 0];

        $count = count($recipients);
        $partsEach = $channel === 'sms' ? $measure['parts'] : 0;
        $costPerPart = (float) ($campaign->provider?->cost_per_part ?? 0);

        return [
            'recipients' => $recipients,
            'count' => $count,
            'parts_each' => $partsEach,
            'parts_total' => $partsEach * $count,
            'characters' => $measure['characters'],
            'alphabet' => $measure['alphabet'],
            'cost' => round($partsEach * $count * $costPerPart, 2),
            'opted_out' => $optedOut,
            'unreachable' => $unreachable,
            'duplicates' => $duplicates,
            // C-SEND-5
            'over_cap' => $count > $campaign->recipient_cap,
        ];
    }

    /**
     * @return Collection<int,Contact>
     */
    private function audience(MessageCampaign $campaign): Collection
    {
        // C-SEND-3 applies to both branches.
        $query = Contact::query()->where('is_active', true);

        if ($campaign->audience_type === 'group' && $campaign->contact_group_id !== null) {
            $query->whereHas('groups', fn (Builder $g) => $g->where('contact_groups.id', $campaign->contact_group_id));
        }

        // ⚠ A `group` campaign whose group has been deleted resolves to NOBODY
        // rather than to everybody. The alternative - falling through to the
        // whole phone book - is the single most expensive bug this module could
        // have, and it would look like a successful send.
        if ($campaign->audience_type === 'group' && $campaign->contact_group_id === null) {
            return collect();
        }

        return $query->orderBy('name')->get();
    }
}
