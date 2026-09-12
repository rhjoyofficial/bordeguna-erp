<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead redesign, slice 1: what kind of lead this is, and who checked it.
 *
 * ⭐⭐ TWO OF JOY'S DECISIONS ARE ON TRIAL HERE, both taken on 12 Sep 2026.
 *
 * The first is that a SHOP'S PHONE NUMBER IS THE SHOP. His instruction was that
 * a lead's phone must be unique; the existing code says in capitals that it must
 * never be, because households share a number. Both are right about different
 * things, so uniqueness is scoped by lead type - and these tests are what prove
 * the narrowing did not quietly break the older rule.
 *
 * The second is VERIFICATION INSTEAD OF APPROVAL ON EVERY EDIT. A field officer
 * must never be blocked doing their job, and must never be able to certify their
 * own record or make it disappear.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Lead Co', 'slug' => 'lead-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->officer = User::factory()->create();
    $this->officer->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->officer->assignRole('Staff');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);

    $this->asOfficer = fn () => $this->actingAs($this->officer)
        ->withHeader('X-Business-Id', (string) $this->business->id);

    $this->capture = fn (array $data) => ($this->asOfficer)()->post(route('crm.leads.store'), array_merge([
        'status' => LeadStatus::New->value,
    ], $data));
});

/* -------------------------------------------------------------------------
 | A shop's number is the shop
 | ---------------------------------------------------------------------- */

it('refuses a second retailer on one phone number, and names the shop that has it', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasNoErrors();

    ($this->capture)(['name' => 'Karim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        // ⚠⚠ THE NAME IS THE POINT. "Already exists" tells an officer standing
        // in a shop nothing they can act on; the name tells them whether they
        // are looking at their own earlier visit or somebody else's
        // relationship.
        ->assertSessionHasErrors(['phone' => 'This number already belongs to Rahim Store.']);

    expect(Lead::where('name', 'Karim Store')->exists())->toBeFalse();
});

it('still lets two people share one household number', function () {
    // ⚠ The older rule, unchanged. Refusing the second member of a family would
    // be refusing a real customer. C-CRM-12 still holds wherever the lead is a
    // person.
    ($this->capture)(['name' => 'Fatima', 'phone' => '01711000000'])->assertSessionHasNoErrors();
    ($this->capture)(['name' => 'Fatima brother', 'phone' => '01711000000'])->assertSessionHasNoErrors();

    expect(Lead::where('phone_normalized', '01711000000')->count())->toBe(2);
});

it('lets a shop and a person share a number, because a shop landline is often the owner mobile', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasNoErrors();
    ($this->capture)(['name' => 'Rahim himself', 'phone' => '01711000000'])
        ->assertSessionHasNoErrors();

    expect(Lead::count())->toBe(2);
});

it('treats every spelling of one number as the same shop', function (string $written) {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasNoErrors();

    ($this->capture)(['name' => 'Karim Store', 'phone' => $written, 'lead_type' => 'retailer'])
        ->assertSessionHasErrors('phone');
})->with([
    '+8801711000000',
    '8801711000000',
    '01711-000000',
    // The spreadsheet case: a 10-digit number that lost its leading zero.
    '1711000000',
]);

it('starts and stops holding the number when the type is corrected', function () {
    $person = Lead::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);

    expect($person->shop_phone_key)->toBeNull();

    // Corrected to a shop: it becomes an identity from that moment.
    $person->update(['lead_type' => LeadType::Retailer]);
    expect($person->fresh()->shop_phone_key)->toBe('01711000000');

    // And corrected back: it stops being one, and the number is free again.
    $person->update(['lead_type' => LeadType::Customer]);
    expect($person->fresh()->shop_phone_key)->toBeNull();

    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasNoErrors();
});

it('lets two businesses each hold the same shop number', function () {
    // ⚠ Scoped by business like every other index on this table. Two tenants
    // selling to the same Dokandar is not a duplicate.
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasNoErrors();

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    app(BusinessContext::class)->set($other->id);

    expect(fn () => Lead::create([
        'business_id' => $other->id, 'name' => 'Rahim Store', 'phone' => '01711000000',
        'lead_type' => LeadType::Retailer,
    ]))->not->toThrow(Exception::class);
});

it('defaults a lead with no type stated to a customer', function () {
    // ⚠ Nullable in validation and defaulted everywhere, so every capture path
    // that predates the selector keeps meaning exactly what it meant before.
    ($this->capture)(['name' => 'Somebody', 'phone' => '01711000000'])->assertSessionHasNoErrors();

    expect(Lead::first()->lead_type)->toBe(LeadType::Customer);
});

/* -------------------------------------------------------------------------
 | Verification: the officer works, the ASM certifies
 | ---------------------------------------------------------------------- */

it('lets a field officer create and work a lead with nobody approving anything', function () {
    // ⭐ The reason verification was chosen over approval on every edit. If this
    // test ever needs a manager, the feature has become the thing it replaced.
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasNoErrors();

    $lead = Lead::first();

    expect($lead->isVerified())->toBeFalse();

    ($this->asOfficer)()->put(route('crm.leads.update', $lead), [
        'name' => 'Rahim Store', 'phone' => '01711000001',
        'lead_type' => 'retailer', 'status' => LeadStatus::Contacted->value,
    ])->assertSessionHasNoErrors();

    expect($lead->fresh()->phone)->toBe('01711000001');
});

it('does not let the officer who created a lead certify it', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);

    ($this->asOfficer)()->post(route('crm.leads.verify', Lead::first()))->assertForbidden();

    expect(Lead::first()->isVerified())->toBeFalse();
});

it('lets the ASM verify, and records who and when', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);

    ($this->asOwner)()->post(route('crm.leads.verify', Lead::first()))->assertSessionHasNoErrors();

    $lead = Lead::first();

    expect($lead->isVerified())->toBeTrue()
        ->and($lead->verified_by_user_id)->toBe($this->owner->id)
        ->and($lead->verified_at)->not->toBeNull();
});

it('refuses to stamp a verification twice', function () {
    // ⚠ Overwriting would replace the name of the person who actually checked
    // it, and the date they did, with somebody who pressed a button again.
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);

    ($this->asOwner)()->post(route('crm.leads.verify', Lead::first()))->assertSessionHasNoErrors();
    ($this->asOwner)()->post(route('crm.leads.verify', Lead::first()))->assertSessionHasErrors('lead');
});

it('clears both columns together when a verification is withdrawn', function () {
    // ⚠ A verified_by left behind with no verified_at says somebody verified
    // this at no particular time, which no screen could render honestly.
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);
    ($this->asOwner)()->post(route('crm.leads.verify', Lead::first()));

    ($this->asOwner)()->delete(route('crm.leads.unverify', Lead::first()))->assertSessionHasNoErrors();

    $lead = Lead::first();

    expect($lead->verified_at)->toBeNull()->and($lead->verified_by_user_id)->toBeNull();
});

/* -------------------------------------------------------------------------
 | Archiving, because there is no delete
 | ---------------------------------------------------------------------- */

it('takes an archived lead out of the working list without destroying it', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);

    ($this->asOwner)()->post(route('crm.leads.archive', Lead::first()))->assertSessionHasNoErrors();

    expect(Lead::workable()->count())->toBe(0)
        // ⛔ Still there. Its history, its creator and its attribution survive,
        // which is the whole point of never deleting.
        ->and(Lead::count())->toBe(1)
        ->and(Lead::first()->archived_by_user_id)->toBe($this->owner->id);

    ($this->asOwner)()->delete(route('crm.leads.restore', Lead::first()))->assertSessionHasNoErrors();

    expect(Lead::workable()->count())->toBe(1);
});

it('keeps the shop number taken while the lead is archived, and says so', function () {
    // ⛔⛔ If archiving released the number, one officer could archive and
    // re-create the same shop endlessly and no duplicate would ever be seen.
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);
    ($this->asOwner)()->post(route('crm.leads.archive', Lead::first()));

    ($this->capture)(['name' => 'Karim Store', 'phone' => '01711000000', 'lead_type' => 'retailer'])
        ->assertSessionHasErrors([
            'phone' => 'This number already belongs to Rahim Store, which has been archived. Restore it instead of creating a second one.',
        ]);
});

it('does not let an officer archive a lead out of sight', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);

    ($this->asOfficer)()->post(route('crm.leads.archive', Lead::first()))->assertForbidden();

    expect(Lead::first()->isArchived())->toBeFalse();
});

it('refuses to archive a lead that became a real customer', function () {
    // ⚠ Every conversion figure the business reads derives from it. What to do
    // with the CUSTOMER is not a question this action may answer on somebody's
    // behalf. Same reasoning as marking one a duplicate.
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);

    Lead::first()->forceFill(['status' => LeadStatus::Converted, 'converted_at' => now()])->save();

    ($this->asOwner)()->post(route('crm.leads.archive', Lead::first()))->assertSessionHasErrors('lead');
});

it('refuses to verify a lead that has been archived', function () {
    ($this->capture)(['name' => 'Rahim Store', 'phone' => '01711000000', 'lead_type' => 'retailer']);
    ($this->asOwner)()->post(route('crm.leads.archive', Lead::first()));

    ($this->asOwner)()->post(route('crm.leads.verify', Lead::first()))->assertSessionHasErrors('lead');
});
