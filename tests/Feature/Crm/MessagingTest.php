<?php

use App\Models\User;
use App\Modules\Crm\Actions\SendCampaign;
use App\Modules\Crm\database\seeders\MessageCampaignWorkflowSeeder;
use App\Modules\Crm\Enums\CampaignStatus;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageDelivery;
use App\Modules\Crm\Models\MessageOptOut;
use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Crm\Support\SmsText;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sending to the phone book.
 *
 * ⚠⚠ THIS IS THE ONLY MODULE IN THE ERP THAT SPENDS MONEY BY ITSELF. Everything
 * else records money somebody else moved. Almost every test below is about a
 * refusal, because the failure that costs real money is not a crash: it is a
 * send that works perfectly and goes to more people than anybody meant.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Send Co', 'slug' => 'send-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['crm.campaigns.send', 'crm.campaigns.approve', 'crm.settings.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['crm.campaigns.send', 'crm.campaigns.approve', 'crm.settings.manage']);
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['crm.campaigns.send']);

    (new MessageCampaignWorkflowSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->provider = MessageProvider::create([
        'business_id' => $this->business->id, 'channel' => 'sms', 'driver' => 'recording_sms',
        'name' => 'Test SMS', 'is_active' => true, 'cost_per_part' => 0.50,
    ]);

    $this->group = ContactGroup::create(['business_id' => $this->business->id, 'name' => 'Dealers']);
});

function contactIn(int $businessId, ?ContactGroup $group, array $overrides = []): Contact
{
    $contact = Contact::create(array_merge([
        'business_id' => $businessId, 'name' => 'Rahim', 'phone' => '01711000000',
    ], $overrides));

    $group?->contacts()->attach($contact->id);

    return $contact;
}

function campaignFor(int $businessId, ?ContactGroup $group, int $providerId, array $overrides = []): MessageCampaign
{
    return MessageCampaign::create(array_merge([
        'business_id' => $businessId, 'channel' => 'sms', 'name' => 'Eid greeting',
        'body' => 'Eid Mubarak from us', 'audience_type' => 'group',
        'contact_group_id' => $group?->id, 'message_provider_id' => $providerId,
    ], $overrides));
}

// ─── What a message costs ────────────────────────────────────────────────────

it('counts an English message at 160 characters a part', function () {
    expect(SmsText::measure(str_repeat('a', 160)))->toMatchArray(['alphabet' => 'gsm', 'parts' => 1])
        // ⚠ 153, not 160, once concatenated: the joining header eats seven.
        ->and(SmsText::measure(str_repeat('a', 161))['parts'])->toBe(2)
        ->and(SmsText::measure(str_repeat('a', 306))['parts'])->toBe(2)
        ->and(SmsText::measure(str_repeat('a', 307))['parts'])->toBe(3);
});

it('counts a Bangla message at 70 characters a part, which is why it costs more', function () {
    $bangla = str_repeat("\u{0985}", 70);

    expect(SmsText::measure($bangla))->toMatchArray(['alphabet' => 'unicode', 'parts' => 1])
        ->and(SmsText::measure(str_repeat("\u{0985}", 71))['parts'])->toBe(2);
});

it('turns the WHOLE message Unicode for one stray character', function () {
    // ⚠⚠ 100 English characters is one part. Add a single Bangla letter and the
    // same message becomes two, because the whole thing switches alphabet. This
    // is the surprise that shows up on a bill.
    expect(SmsText::measure(str_repeat('a', 100))['parts'])->toBe(1)
        ->and(SmsText::measure(str_repeat('a', 100)."\u{0985}")['parts'])->toBe(2);
});

it('charges nothing for an empty message', function () {
    // ⚠ Zero parts, not one. A preview of a mistake must not look like a plan.
    expect(SmsText::measure('')['parts'])->toBe(0);
});

it('counts an extension character as two septets', function () {
    // ⚠ `€` and the bracket family take two GSM septets each, so 80 of them
    // overflow a 160-character part.
    expect(SmsText::measure(str_repeat('€', 80))['parts'])->toBe(1)
        ->and(SmsText::measure(str_repeat('€', 81))['parts'])->toBe(2);
});

// ─── Who would receive it ────────────────────────────────────────────────────

it('leaves out anybody who asked not to be contacted', function () {
    contactIn($this->business->id, $this->group, ['name' => 'Wants it', 'phone' => '01711000001']);
    contactIn($this->business->id, $this->group, ['name' => 'Asked to stop', 'phone' => '01711000002']);

    MessageOptOut::create([
        'business_id' => $this->business->id, 'channel' => 'sms', 'address' => '01711000002',
    ]);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id))
        ->assertSessionHas('campaignPreview', fn ($p) => $p['count'] === 1 && $p['opted_out'] === 1);
});

it('sends one message per number, not one per row', function () {
    // ⚠⚠ A household sharing a number is legitimate and appears twice in the
    // phone book ON PURPOSE. Sending twice is a duplicate charge and a nuisance.
    contactIn($this->business->id, $this->group, ['name' => 'Rahim', 'phone' => '01711000000']);
    contactIn($this->business->id, $this->group, ['name' => 'Rahim wife', 'phone' => '+8801711000000']);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id))
        ->assertSessionHas('campaignPreview', fn ($p) => $p['count'] === 1 && $p['duplicates'] === 1);
});

it('counts the people it cannot reach instead of quietly dropping them', function () {
    contactIn($this->business->id, $this->group, ['name' => 'Has a phone', 'phone' => '01711000001']);
    contactIn($this->business->id, $this->group, ['name' => 'Email only', 'phone' => null, 'email' => 'a@example.com']);

    // ⚠ "I sent to the 200 dealers" and "180 of them have a number" are
    // different facts, and the screen has to show both.
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id))
        ->assertSessionHas('campaignPreview', fn ($p) => $p['count'] === 1 && $p['unreachable'] === 1);
});

it('never includes a contact somebody has deactivated', function () {
    contactIn($this->business->id, $this->group, ['name' => 'Active', 'phone' => '01711000001']);
    contactIn($this->business->id, $this->group, ['name' => 'Gone', 'phone' => '01711000002', 'is_active' => false]);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id))
        ->assertSessionHas('campaignPreview', fn ($p) => $p['count'] === 1);
});

it('resolves a group campaign with no group to NOBODY, never to everybody', function () {
    contactIn($this->business->id, null, ['name' => 'Not in any group', 'phone' => '01711000009']);

    $campaign = campaignFor($this->business->id, null, $this->provider->id, ['contact_group_id' => null]);

    // ⚠⚠ THE MOST EXPENSIVE BUG THIS MODULE COULD HAVE. Falling through to the
    // whole phone book would look like a successful send.
    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id))
        ->assertSessionHas('campaignPreview', fn ($p) => $p['count'] === 0);
});

// ─── The brakes ──────────────────────────────────────────────────────────────

it('refuses to send over the cap rather than sending the first few', function () {
    foreach (range(1, 3) as $i) {
        contactIn($this->business->id, $this->group, ['name' => "P{$i}", 'phone' => '0171100000'.$i]);
    }

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id, ['recipient_cap' => 2]);

    // ⚠⚠ Truncating would hide the mistake AND still spend money.
    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id))
        ->assertSessionHasErrors('send');

    expect(MessageDelivery::count())->toBe(0);
});

it('raises approval instead of sending when the audience is big', function () {
    foreach (range(1, 60) as $i) {
        contactIn($this->business->id, $this->group, ['name' => "P{$i}", 'phone' => '017110'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
    }

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id, ['recipient_cap' => 1000]);

    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))
        ->assertRedirect()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'gone for approval'));

    // ⚠⚠ NOTHING WENT OUT. A button that silently does something else is worse
    // than one that refuses.
    expect(MessageDelivery::count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::PendingApproval)
        ->and($campaign->fresh()->approvalRequest)->not->toBeNull();
});

it('refuses to send a campaign that is still waiting for approval', function () {
    contactIn($this->business->id, $this->group);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);
    $campaign->submitForApproval($this->manager);
    $campaign->update(['status' => CampaignStatus::PendingApproval]);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id))
        ->assertSessionHasErrors('send');

    expect(MessageDelivery::count())->toBe(0);
});

it('approves without sending, because permission to spend is not an instruction to', function () {
    contactIn($this->business->id, $this->group);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);
    $campaign->submitForApproval($this->manager);
    $campaign->update(['status' => CampaignStatus::PendingApproval]);

    $this->actingAs($this->owner)->post(route('crm.campaigns.approve', $campaign->id))->assertRedirect();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Approved)
        ->and($campaign->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(MessageDelivery::count())->toBe(0);
});

it('will not let the person who sends also be the person who approves', function () {
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);
    $campaign->submitForApproval($this->manager);

    // ⚠ The same preparer/approver split payroll already uses.
    $this->actingAs($this->manager)->post(route('crm.campaigns.approve', $campaign->id))->assertForbidden();
});

it('refuses to send with no active provider', function () {
    contactIn($this->business->id, $this->group);

    $this->provider->update(['is_active' => false]);
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id))
        ->assertSessionHasErrors('send');
});

// ─── Sending ─────────────────────────────────────────────────────────────────

it('records one row per person and marks them sent, never delivered', function () {
    contactIn($this->business->id, $this->group, ['name' => 'A', 'phone' => '01711000001']);
    contactIn($this->business->id, $this->group, ['name' => 'B', 'phone' => '01711000002']);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id))->assertRedirect();

    // ⚠⚠ `sent` means the provider accepted it. `delivered` is a different fact
    // that only arrives if the provider says so, and several never do.
    expect(MessageDelivery::count())->toBe(2)
        ->and(MessageDelivery::where('status', 'sent')->count())->toBe(2)
        ->and(MessageDelivery::whereNotNull('delivered_at')->count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('does not message somebody who opted out after the preview was read', function () {
    contactIn($this->business->id, $this->group, ['name' => 'A', 'phone' => '01711000001']);
    contactIn($this->business->id, $this->group, ['name' => 'B', 'phone' => '01711000002']);

    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id));

    // ⚠⚠ Somebody asks to be left alone AFTER the preview was read. A preview is
    // not a licence to ignore a later request.
    MessageOptOut::create(['business_id' => $this->business->id, 'channel' => 'sms', 'address' => '01711000002']);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id))->assertRedirect();

    // ⚠ The guarantee is that they were NOT messaged, not which of the two
    // guards caught them. Here the re-plan at send time excluded them before
    // the loop began; the per-recipient re-check in SendCampaign covers the
    // narrower case of somebody opting out WHILE a long batch is going out.
    expect(MessageDelivery::count())->toBe(1)
        ->and(MessageDelivery::first()->to_address)->toBe('01711000001');
});

it('keeps the address it actually sent to, whatever happens to the contact later', function () {
    $contact = contactIn($this->business->id, $this->group, ['phone' => '01711000001']);
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id));

    $contact->update(['phone' => '01799999999']);

    // ⚠ A contact whose number changes next month must not rewrite the history
    // of where a message actually went.
    expect(MessageDelivery::first()->to_address)->toBe('01711000001');
});

it('refuses to send the same campaign twice', function () {
    contactIn($this->business->id, $this->group);
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id));
    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id))->assertSessionHasErrors('send');

    expect(MessageDelivery::count())->toBe(1);
});

it('will not let a sent campaign be edited afterwards', function () {
    contactIn($this->business->id, $this->group);
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);

    $this->actingAs($this->owner)->post(route('crm.campaigns.send', $campaign->id));

    // ⚠ Editing would rewrite what was approved and what went out, and the
    // delivery rows would describe a message nobody can read any more.
    $this->actingAs($this->owner)->put(route('crm.campaigns.update', $campaign->id), [
        'name' => 'Changed', 'body' => 'Something else', 'audience_type' => 'group',
        'contact_group_id' => $this->group->id, 'recipient_cap' => 500,
    ])->assertForbidden();

    expect($campaign->fresh()->name)->toBe('Eid greeting');
});

// ─── Opt-outs and providers ──────────────────────────────────────────────────

it('records an opt-out against the number, so re-importing cannot undo it', function () {
    $contact = contactIn($this->business->id, $this->group, ['phone' => '01711000001']);

    $this->actingAs($this->owner)->post(route('crm.messages.opt-out', 'sms'), [
        'address' => '+880 1711-000001', 'reason' => 'Asked on the phone',
    ])->assertRedirect();

    // ⚠⚠ Normalised, so however it was typed it matches. And it survives the
    // contact: deleting the row and importing the file again must not start
    // messaging them once more.
    expect(MessageOptOut::first()->address)->toBe('01711000001');

    $contact->delete();

    expect(MessageOptOut::count())->toBe(1);
});

it('keeps provider credentials encrypted and never hands them back', function () {
    $this->actingAs($this->owner)->post(route('crm.settings.messaging.store'), [
        'name' => 'Real SMS', 'channel' => 'sms', 'driver' => 'recording_sms',
        'priority' => 10, 'cost_per_part' => '0.45',
        'credentials' => ['api_key' => 'super-secret-key-1234'],
    ])->assertRedirect();

    $provider = MessageProvider::where('name', 'Real SMS')->firstOrFail();

    // ⚠⚠ Ciphertext at rest.
    $raw = DB::table('message_providers')->where('id', $provider->id)->value('credentials');
    expect($raw)->not->toContain('super-secret-key-1234')
        ->and($provider->credentials['api_key'])->toBe('super-secret-key-1234')
        // ⚠ Only the last four ever reach a screen.
        ->and($provider->maskedCredentials()['api_key'])->toBe('••••••1234')
        // ⚠ And it arrives switched OFF.
        ->and($provider->is_active)->toBeFalse();

    $this->actingAs($this->owner)->get(route('crm.settings.messaging'))
        ->assertOk()
        ->assertDontSee('super-secret-key-1234');
});

it('treats a blank credential on edit as leave it alone, not erase it', function () {
    $provider = MessageProvider::create([
        'business_id' => $this->business->id, 'channel' => 'sms', 'driver' => 'recording_sms',
        'name' => 'Keeps its key', 'credentials' => ['api_key' => 'keep-me'], 'cost_per_part' => 0,
    ]);

    // ⚠⚠ The screen can never show the secret, so it always arrives blank
    // unless somebody typed a new one. Erasing on blank would wipe the key
    // every time anybody renamed a provider.
    $this->actingAs($this->owner)->put(route('crm.settings.messaging.update', $provider->id), [
        'name' => 'Renamed', 'channel' => 'sms', 'driver' => 'recording_sms',
        'priority' => 100, 'cost_per_part' => '0', 'credentials' => ['api_key' => ''],
    ])->assertRedirect();

    expect($provider->fresh()->credentials['api_key'])->toBe('keep-me')
        ->and($provider->fresh()->name)->toBe('Renamed');
});

it('refuses a driver that cannot send the channel it was filed under', function () {
    // ⚠ Otherwise a send picks a provider that cannot send it, and the failure
    // appears per recipient rather than at the settings screen.
    $this->actingAs($this->owner)->post(route('crm.settings.messaging.store'), [
        'name' => 'Wrong', 'channel' => 'sms', 'driver' => 'smtp_mail',
        'priority' => 100, 'cost_per_part' => '0', 'credentials' => [],
    ])->assertRedirect()->assertSessionHasErrors('driver'); // CRM-015 (14 Oct 2026): under the field
});

it('never shows one business the campaigns of another', function () {
    campaignFor($this->business->id, $this->group, $this->provider->id, ['name' => 'Ours']);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-send-co']);
    MessageCampaign::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'channel' => 'sms', 'name' => 'Theirs', 'body' => 'x',
    ]);

    $this->actingAs($this->owner)->get('/crm/messages/sms')
        ->assertInertia(fn ($page) => $page->where('campaigns', fn ($c) => count($c) === 1 && $c[0]['name'] === 'Ours'));
});

it('refuses the send pages to a login that cannot send', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/crm/messages/sms')->assertForbidden();
});

// ─── Audit of 14 Oct 2026: approval is one decision, on what will be sent ───────

/** Sixty dealers and a campaign above the threshold, raised for approval by the Manager. */
function campaignAwaitingApproval(): MessageCampaign
{
    foreach (range(1, 60) as $i) {
        contactIn(test()->business->id, test()->group, ['name' => "P{$i}", 'phone' => '017110'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
    }

    $campaign = campaignFor(test()->business->id, test()->group, test()->provider->id, ['recipient_cap' => 1000]);
    test()->actingAs(test()->manager)->post(route('crm.campaigns.send', $campaign->id))->assertSessionHasNoErrors();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::PendingApproval);

    return $campaign->fresh();
}

// CRM-001: a second Approve on a campaign that already went out re-armed it,
// and the next Send messaged all sixty people again.
it('refuses a second approve on a sent campaign, and nothing goes out twice', function () {
    $campaign = campaignAwaitingApproval();

    $this->actingAs($this->owner)->post(route('crm.campaigns.approve', $campaign->id))->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))->assertSessionHasNoErrors();
    expect(MessageDelivery::count())->toBe(60);

    // The stale approvals tab.
    $this->actingAs($this->owner)->post(route('crm.campaigns.approve', $campaign->id))->assertSessionHasErrors('approval');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent);

    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))->assertSessionHasErrors('send');
    expect(MessageDelivery::count())->toBe(60);
});

it('refuses to approve a rejected campaign back to life, or to reject an approved one', function () {
    $campaign = campaignAwaitingApproval();

    $this->actingAs($this->owner)->post(route('crm.campaigns.reject', $campaign->id))->assertSessionHasNoErrors();
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Cancelled);

    $this->actingAs($this->owner)->post(route('crm.campaigns.approve', $campaign->id))->assertSessionHasErrors('approval');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Cancelled)
        ->and($campaign->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Rejected);

    // And a cancelled campaign cannot be sent by any route.
    expect(fn () => app(SendCampaign::class)->handle($campaign->fresh()))
        ->toThrow(RuntimeException::class);
    expect(MessageDelivery::count())->toBe(0);
});

// CRM-002: what the approver approves is what goes out. A campaign waiting for
// approval could be rewritten (text, audience, cap) and the card still showed
// the figures it was submitted with.
it('returns an edited campaign to draft and needs a fresh approval before it can go out', function () {
    $campaign = campaignAwaitingApproval();

    $this->actingAs($this->manager)->put(route('crm.campaigns.update', $campaign->id), [
        'name' => 'Eid greeting', 'body' => 'A very different message', 'audience_type' => 'all',
        'recipient_cap' => 100000, 'message_provider_id' => $this->provider->id,
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'approval'));

    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->approvalRequest->status)->not->toBe(ApprovalStatus::Pending);

    // The old request cannot be approved any more.
    $this->actingAs($this->owner)->post(route('crm.campaigns.approve', $campaign->id))->assertSessionHasErrors('approval');

    // Send raises approval again, with the new figures, and sends nothing.
    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'gone for approval'));

    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::PendingApproval)
        ->and($campaign->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($campaign->body)->toBe('A very different message')
        ->and(MessageDelivery::count())->toBe(0);
});

// CRM-003: two presses of Send both read "draft" before either wrote "sending".
it('sends a campaign once even when a second press read it before the first finished', function () {
    contactIn($this->business->id, $this->group, ['phone' => '01711000001']);
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id);
    $stale = MessageCampaign::find($campaign->id);

    app(SendCampaign::class)->handle($campaign);

    expect(fn () => app(SendCampaign::class)->handle($stale))
        ->toThrow(RuntimeException::class);
    expect(MessageDelivery::count())->toBe(1);
});

// CRM-008 (audit 14 Oct 2026): a campaign's group and provider are this
// business's own, the C-CRM-1 rule every other CRM form follows.
it('refuses another business\'s contact group or provider on a campaign', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $theirGroup = ContactGroup::create(['business_id' => $other->id, 'name' => 'Theirs']);
    $theirProvider = MessageProvider::create([
        'business_id' => $other->id, 'channel' => 'sms', 'driver' => 'recording_sms',
        'name' => 'Their SMS', 'is_active' => true, 'cost_per_part' => 0.50,
    ]);
    app(BusinessContext::class)->set($this->business->id);

    $this->actingAs($this->manager)->post(route('crm.messages.store', 'sms'), [
        'name' => 'Probe', 'body' => 'Hello', 'audience_type' => 'group',
        'contact_group_id' => $theirGroup->id, 'message_provider_id' => $theirProvider->id, 'recipient_cap' => 10,
    ])->assertSessionHasErrors(['contact_group_id', 'message_provider_id']);

    expect(MessageCampaign::withoutGlobalScopes()->count())->toBe(0);
});

// ─── CRM-004 (audit 14 Oct 2026): a send that died part-way ─────────────────

/**
 * Three dealers, a send that got as far as the first one and then died: the
 * campaign row says Sending and one delivery row exists, both 20 minutes old.
 */
function campaignStuckSending(int $minutesAgo = 20): MessageCampaign
{
    contactIn(test()->business->id, test()->group, ['name' => 'A', 'phone' => '01711000001']);
    contactIn(test()->business->id, test()->group, ['name' => 'B', 'phone' => '01711000002']);
    contactIn(test()->business->id, test()->group, ['name' => 'C', 'phone' => '01711000003']);

    $campaign = campaignFor(test()->business->id, test()->group, test()->provider->id, ['status' => CampaignStatus::Sending]);

    MessageDelivery::create([
        'business_id' => test()->business->id, 'message_campaign_id' => $campaign->id,
        'to_address' => '01711000001', 'status' => 'sent', 'parts' => 1, 'cost' => 0.5, 'sent_at' => now(),
    ]);

    test()->travel($minutesAgo)->minutes();

    return $campaign->fresh();
}

it('resumes a stuck send for the people it never reached, and messages nobody twice', function () {
    $campaign = campaignStuckSending();

    $this->actingAs($this->manager)->post(route('crm.campaigns.resume', $campaign->id))->assertSessionHasNoErrors();

    expect(MessageDelivery::count())->toBe(3)
        ->and(MessageDelivery::where('to_address', '01711000001')->count())->toBe(1)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->fresh()->estimated_recipients)->toBe(3);

    // And a second press finds nothing left to resume.
    $this->actingAs($this->manager)->post(route('crm.campaigns.resume', $campaign->id))->assertSessionHasErrors('send');
    expect(MessageDelivery::count())->toBe(3);
});

it('will not resume a send that is still making progress', function () {
    $campaign = campaignStuckSending(minutesAgo: 2);

    $this->actingAs($this->manager)->post(route('crm.campaigns.resume', $campaign->id))->assertSessionHasErrors('send');
    $this->actingAs($this->manager)->post(route('crm.campaigns.stop', $campaign->id), ['reason' => 'Gateway down'])
        ->assertSessionHasErrors('send');

    expect(MessageDelivery::count())->toBe(1)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('will not resume to an audience that grew past what was approved', function () {
    $campaign = campaignStuckSending();
    $campaign->update(['estimated_recipients' => 3]);
    $campaign->submitForApproval($this->manager);
    $campaign->approvalRequest->update(['status' => ApprovalStatus::Approved]);
    $this->travel(20)->minutes();

    // Somebody added two dealers to the group after the approval.
    contactIn($this->business->id, $this->group, ['name' => 'D', 'phone' => '01711000004']);
    contactIn($this->business->id, $this->group, ['name' => 'E', 'phone' => '01711000005']);

    $this->actingAs($this->manager)->post(route('crm.campaigns.resume', $campaign->id))
        ->assertSessionHasErrors('send');

    expect(MessageDelivery::count())->toBe(1);
});

it('marks a stuck send stopped with a reason, keeps every delivery row, and logs the counts', function () {
    $campaign = campaignStuckSending();

    $this->actingAs($this->manager)->post(route('crm.campaigns.stop', $campaign->id), [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->manager)->post(route('crm.campaigns.stop', $campaign->id), ['reason' => 'Gateway went down'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 sent') && str_contains($m, '2 not sent'));

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Stopped)
        ->and(MessageDelivery::count())->toBe(1);

    $log = DB::table('activity_log')->where('subject_id', $campaign->id)
        ->where('description', 'like', 'campaign stopped%')->first();
    expect($log)->not->toBeNull()
        ->and(json_decode($log->properties, true))->toMatchArray(['reason' => 'Gateway went down', 'sent' => 1, 'unsent' => 2]);

    // Stopped is final: no send, no resume.
    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))->assertSessionHasErrors('send');
    $this->actingAs($this->manager)->post(route('crm.campaigns.resume', $campaign->id))->assertSessionHasErrors('send');
    expect(MessageDelivery::count())->toBe(1);
});

it('offers resume and stop only on a stuck send, and only to a login that can send', function () {
    $campaign = campaignStuckSending();

    $this->actingAs($this->manager)->get(route('crm.messages.index', 'sms'))
        ->assertInertia(fn ($page) => $page->where('campaigns.0.stuck', true)->where('campaigns.0.unsent_count', 2));

    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);

    $this->actingAs($clerk)->post(route('crm.campaigns.resume', $campaign->id))->assertForbidden();
    $this->actingAs($clerk)->post(route('crm.campaigns.stop', $campaign->id), ['reason' => 'x'])->assertForbidden();
});

// The do-not-contact entry is a popup over its list since 14 Oct 2026 (the two
// boxes no longer sit above it). The compose form stays a page form: preview,
// cap and approval are its money brakes. The page tells the popup whether this
// login may add, and the popup saves through the same route, normalised.
it('carries what the do-not-contact popup needs, saves through the same route, and leaves the compose form alone', function () {
    $this->actingAs($this->manager)->get(route('crm.messages.index', 'sms'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('crm/messaging/index')
            ->where('channel', 'sms')
            ->where('can.send', true)
            ->where('optOuts', []));

    $this->actingAs($this->manager)->post(route('crm.messages.opt-out', 'sms'), [
        'address' => '+880 1711-000002', 'reason' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->get(route('crm.messages.index', 'sms'))
        ->assertInertia(fn ($page) => $page
            ->has('optOuts', 1)
            ->where('optOuts.0.address', '01711000002')
            ->where('optOuts.0.reason', null));

    $page = file_get_contents(resource_path('js/pages/crm/messaging/index.tsx'));
    expect($page)->toContain('<OptOutDialog')
        ->and($page)->not->toContain('optOutForm')
        ->and($page)->toContain("route('crm.campaigns.preview'");
});

// ⚠ Bug hunt 16 Oct 2026 (03 C1): an approved campaign went to the audience as
// it is NOW, so a group grown by an import after approval was messaged in full.
it('refuses to send an approved campaign to more people than were approved', function () {
    $add = fn (int $from, int $to) => collect(range($from, $to))->each(fn ($i) => contactIn($this->business->id, $this->group, ['name' => "P{$i}", 'phone' => '017110'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]));
    $add(1, 60);
    $campaign = campaignFor($this->business->id, $this->group, $this->provider->id, ['recipient_cap' => 1000]);

    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))->assertRedirect();
    $this->actingAs($this->owner)->post(route('crm.campaigns.approve', $campaign->id))->assertRedirect();
    expect((int) $campaign->fresh()->estimated_recipients)->toBe(60);

    $add(61, 75);

    // A stale preview tab must not rewrite what was approved either.
    $this->actingAs($this->manager)->post(route('crm.campaigns.preview', $campaign->id));
    expect((int) $campaign->fresh()->estimated_recipients)->toBe(60);

    $this->actingAs($this->manager)->post(route('crm.campaigns.send', $campaign->id))
        ->assertSessionHasErrors('send');

    expect(MessageDelivery::count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Approved);
});

// ⚠ Bug hunt 16 Oct 2026 (03 C2): changing one key replaced the whole set, so
// the keys left blank were erased.
it('keeps the other credentials when only one of them is changed', function () {
    $provider = MessageProvider::create([
        'business_id' => $this->business->id, 'channel' => 'sms', 'driver' => 'recording_sms',
        'name' => 'Two keys', 'credentials' => ['username' => 'bdg', 'password' => 'old'], 'cost_per_part' => 0,
    ]);

    $this->actingAs($this->owner)->put(route('crm.settings.messaging.update', $provider->id), [
        'name' => 'Two keys', 'channel' => 'sms', 'driver' => 'recording_sms',
        'priority' => 100, 'cost_per_part' => '0', 'credentials' => ['username' => '', 'password' => 'new'],
    ])->assertRedirect();

    expect($provider->fresh()->credentials)->toBe(['username' => 'bdg', 'password' => 'new']);
});
