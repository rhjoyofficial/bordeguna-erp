<?php

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Crm\database\seeders\MessageCampaignWorkflowSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DEAD ENGINE #9: a preview that was computed, flashed, tested and never shown.
 *
 * ⚠⚠ THE BUG THESE TESTS EXIST FOR. Three controllers answered a dry run with
 * `back()->with('campaignPreview', ...)` and the matching React pages read
 * `usePage().props.campaignPreview`. A flashed session key IS NOT an Inertia
 * prop: only what HandleInertiaRequests shares, or what Inertia::render is
 * given, ever reaches a page. So the preview panel rendered nothing, on both the
 * contact importer and the campaign screen.
 *
 * ⚠⚠ AND EVERY EXISTING TEST WAS GREEN, because they all asserted
 * `assertSessionHas(...)` - the server half, which always worked. This is the
 * same shape as dead engines #7 and #8: the machinery is real, the tests are
 * real, and nothing checks that the two ends are joined.
 *
 * ⛔ SO THESE ASSERT THE PROP, through a real page load, and they go red the
 * moment the sharing is removed. Any future preview added by flashing gets a
 * test here or it is invisible too.
 *
 * Why it matters more than a missing panel: the contact import preview is Joy's
 * standing rule that an importer must show a dry run before it writes anything,
 * and the campaign preview is the FIRST of the three brakes on the only module
 * in this ERP that spends money by itself.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Preview Co', 'slug' => 'preview-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['crm.campaigns.send', 'crm.contacts.view', 'crm.contacts.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['crm.campaigns.send', 'crm.contacts.view', 'crm.contacts.manage']);

    (new MessageCampaignWorkflowSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
});

it('hands the campaign preview to the screen as a prop, not only to the session', function () {
    $group = ContactGroup::create(['business_id' => $this->business->id, 'name' => 'Dealers']);

    $contact = Contact::create([
        'business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000',
        'phone_normalized' => '8801711000000', 'is_active' => true,
    ]);
    $contact->groups()->attach($group->id);

    $provider = MessageProvider::create([
        'business_id' => $this->business->id, 'channel' => 'sms', 'driver' => 'recording_sms',
        'name' => 'Test SMS', 'is_active' => true, 'cost_per_part' => 0.50,
    ]);

    $campaign = MessageCampaign::create([
        'business_id' => $this->business->id, 'channel' => 'sms', 'name' => 'Eid offer',
        'body' => 'Eid Mubarak from Bor de Guna', 'audience_type' => 'group',
        'contact_group_id' => $group->id, 'message_provider_id' => $provider->id,
        'recipient_cap' => 500, 'status' => 'draft',
    ]);

    $this->actingAs($this->owner)->post(route('crm.campaigns.preview', $campaign->id));

    // ⚠⚠ THE ASSERTION THAT MATTERS: follow the redirect the way a browser does
    // and demand the figure on the PAGE. Asserting the session here would pass
    // against the bug this test was written for.
    $this->actingAs($this->owner)->get(route('crm.messages.index', 'sms'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('campaignPreview.count', 1)
            ->where('campaignPreview.campaign_id', $campaign->id)
        );
});

it('hands the contact import dry run to the screen as a prop', function () {
    // Joy's standing rule: an importer previews before it writes. A preview
    // nobody can see is an importer that appears to apply blind.
    $csv = "name,phone,email\nRahim,01711000000,rahim@example.com\n";

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $this->actingAs($this->owner)->post(route('crm.contacts.import.review'), ['file' => $file]);

    $this->actingAs($this->owner)->get(route('crm.contacts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('contactImportPlan.creates', 1));
});
