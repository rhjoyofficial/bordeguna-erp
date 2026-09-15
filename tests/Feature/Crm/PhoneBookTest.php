<?php

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The phone book: everyone the business can reach, and the groups a send aims at.
 *
 * ⚠⚠ THE POINT OF THIS MODULE IS THAT IT WILL LATER SPEND MONEY. Nothing here
 * sends anything yet, and every rule below exists because the row it protects
 * becomes an SMS once phase 2 lands. A duplicate in a group is a duplicate
 * message; a contact quietly overwritten is a message to the wrong person.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Book Co', 'slug' => 'book-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['crm.contacts.view', 'crm.contacts.manage', 'crm.leads.view.all'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    // ⚠ `crm.leads.view.all` since 15 Sep 2026. Pulling leads into the book
    // pulls only the leads the puller may see - otherwise the phone book, which
    // Staff can read, would be a back door around lead visibility. This file
    // proves the book; LeadVisibilityTest proves that door is shut.
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['crm.contacts.view', 'crm.contacts.manage', 'crm.leads.view.all']);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['crm.contacts.view']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function csvFile(string $body): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'book').'.csv';
    file_put_contents($path, $body);

    return new UploadedFile($path, 'contacts.csv', 'text/csv', null, true);
}

it('shows the phone book with its groups and reachability totals', function () {
    $reachable = Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);
    Contact::create(['business_id' => $this->business->id, 'name' => 'Karim', 'email' => 'karim@example.com']);
    Contact::create(['business_id' => $this->business->id, 'name' => 'Retired', 'phone' => '01711000002', 'is_active' => false]);

    $group = ContactGroup::create(['business_id' => $this->business->id, 'name' => 'Dealers']);
    $group->contacts()->attach($reachable->id);

    $this->actingAs($this->manager)->get('/crm/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('crm/contacts/index')
            ->where('totals.all', 3)
            ->where('totals.active', 2)
            // ⚠ Karim has an email and no phone, so he is NOT reachable by SMS.
            // A total that counted him would promise an audience that does not
            // exist, and the first send would silently be smaller than the
            // number on the screen.
            ->where('totals.reachable_by_sms', 1)
            ->where('totals.reachable_by_email', 1)
            ->where('groups.0.contacts_count', 1)
        );
});

it('files the number as typed and matches on the normalised form', function () {
    $this->actingAs($this->manager)->post('/crm/contacts', [
        'name' => 'Rahim',
        'phone' => '+880 1711-000000',
    ])->assertRedirect();

    $contact = Contact::first();

    // ⚠⚠ The original is never rewritten. Joy's rule from the supplier
    // decision: we do not silently correct somebody's data.
    expect($contact->phone)->toBe('+880 1711-000000')
        ->and($contact->phone_normalized)->toBe('01711000000');

    // And the search finds it by the plain form anyway, which is the entire
    // reason the second column exists.
    $this->actingAs($this->manager)->get('/crm/contacts?search=01711000000')
        ->assertInertia(fn ($page) => $page->where('contacts.total', 1));
});

it('warns about a shared number and files the person anyway', function () {
    Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);

    $this->actingAs($this->manager)->post('/crm/contacts', [
        'name' => 'Rahim wife',
        'phone' => '8801711000000',
    ])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'also filed under: Rahim'));

    // ⚠⚠ BOTH ROWS EXIST. A household, a shop and its owner legitimately share
    // one number (C-CRM-12, C-CRM-13). Refusing the second entry would lose a
    // real person in order to keep a table tidy.
    expect(Contact::count())->toBe(2);
});

it('refuses a contact nobody could ever be sent anything', function () {
    $this->actingAs($this->manager)->post('/crm/contacts', ['name' => 'Ghost'])
        ->assertSessionHasErrors(['phone', 'email']);

    expect(Contact::count())->toBe(0);
});

it('will not let the phone book rewrite a customer record through the back door', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Shop A', 'phone' => '01711000000',
    ]);

    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'customer'])->assertRedirect();

    $shadow = Contact::where('linked_type', Customer::class)->firstOrFail();

    $this->actingAs($this->manager)->put("/crm/contacts/{$shadow->id}", [
        'name' => 'Renamed behind the scenes',
        'phone' => '01711000000',
    ])->assertSessionHasErrors('name');

    expect($shadow->fresh()->name)->toBe('Shop A')
        ->and($customer->fresh()->name)->toBe('Shop A');
});

it('builds the book from leads and customers, and pulling twice changes nothing', function () {
    Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Shop A', 'phone' => '01711000001']);
    Lead::create(['business_id' => $this->business->id, 'name' => 'Prospect', 'phone' => '01711000002']);
    // ⚠ Neither a phone nor an email, so there is no way to reach them.
    Lead::create(['business_id' => $this->business->id, 'name' => 'Unreachable']);

    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'customer'])->assertRedirect();
    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'lead'])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($m) => str_contains($m, '1 skipped'));

    expect(Contact::count())->toBe(2);

    // Idempotent, and guaranteed by the unique index rather than by the code.
    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'customer'])->assertRedirect();
    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'lead'])->assertRedirect();

    expect(Contact::count())->toBe(2);
});

it('follows the master record when its number changes', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Shop A', 'phone' => '01711000001']);
    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'customer']);

    $customer->update(['phone' => '01711000009']);
    $this->actingAs($this->manager)->post('/crm/contacts/pull', ['type' => 'customer']);

    // ⚠⚠ Otherwise the phone book keeps messaging a number the business itself
    // no longer believes in.
    expect(Contact::first()->phone)->toBe('01711000009')
        ->and(Contact::first()->phone_normalized)->toBe('01711000009');
});

it('adds people to a group and does not choke when one is already in it', function () {
    $a = Contact::create(['business_id' => $this->business->id, 'name' => 'A', 'phone' => '01711000001']);
    $b = Contact::create(['business_id' => $this->business->id, 'name' => 'B', 'phone' => '01711000002']);
    $group = ContactGroup::create(['business_id' => $this->business->id, 'name' => 'Dealers']);
    $group->contacts()->attach($a->id);

    // ⚠ A bulk action that fails because one of forty people was already a
    // member is a bad tool, and the unique index makes a plain attach throw.
    $this->actingAs($this->manager)->post('/crm/contact-groups/add', [
        'group_id' => $group->id,
        'contact_ids' => [$a->id, $b->id],
    ])->assertRedirect();

    expect($group->fresh()->contacts()->count())->toBe(2);

    $this->actingAs($this->manager)->post('/crm/contact-groups/remove', [
        'group_id' => $group->id,
        'contact_ids' => [$a->id],
    ])->assertRedirect();

    expect($group->fresh()->contacts()->count())->toBe(1);
});

it('deactivates rather than deletes, so a past send still has an audience', function () {
    $contact = Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);

    $this->actingAs($this->manager)->post("/crm/contacts/{$contact->id}/deactivate")->assertRedirect();

    expect(Contact::count())->toBe(1)
        ->and($contact->fresh()->is_active)->toBeFalse();
});

it('lets a viewer read the book and refuses to let them change it', function () {
    $contact = Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);

    $this->actingAs($this->staff)->get('/crm/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($this->staff)->post('/crm/contacts', ['name' => 'X', 'phone' => '01711000003'])->assertForbidden();
    $this->actingAs($this->staff)->post("/crm/contacts/{$contact->id}/deactivate")->assertForbidden();
});

it('never shows one business the phone book of another', function () {
    Contact::create(['business_id' => $this->business->id, 'name' => 'Ours', 'phone' => '01711000000']);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    Contact::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Theirs', 'phone' => '01711000001']);

    $this->actingAs($this->manager)->get('/crm/contacts')
        ->assertInertia(fn ($page) => $page->where('contacts.total', 1)->where('contacts.data.0.name', 'Ours'));
});

// ─── The importer ────────────────────────────────────────────────────────────

it('reviews a file without writing a single row', function () {
    $file = csvFile("name,phone\nRahim,01711000000\nKarim,01711000001\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file])
        ->assertRedirect()
        ->assertSessionHas('contactImportPlan', fn ($plan) => count($plan['creates']) === 2 && $plan['errors'] === []);

    // ⚠⚠ THE WHOLE POINT. Review is not a checkbox on a write; it is a
    // different request that writes nothing.
    expect(Contact::count())->toBe(0);
});

it('applies the file the person has just read', function () {
    $file = csvFile("name,phone,company\nRahim,01711000000,Rahim Store\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file]);
    $this->actingAs($this->manager)->post('/crm/contacts/import/apply')->assertRedirect();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->company)->toBe('Rahim Store')
        ->and(Contact::first()->phone_normalized)->toBe('01711000000');
});

it('updates the person a number already belongs to instead of making a second one', function () {
    Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);

    $file = csvFile("name,phone,company\nRahim,+8801711000000,New Shop\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file]);
    $this->actingAs($this->manager)->post('/crm/contacts/import/apply')->assertRedirect();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->company)->toBe('New Shop');
});

it('refuses a row when the number already belongs to two people', function () {
    Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000']);
    Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim wife', 'phone' => '01711000000']);

    $file = csvFile("name,phone\nSomebody,01711000000\n");

    // ⚠⚠ REFUSES rather than guessing. Overwriting the wrong person's name is
    // not recoverable; refusing one row is.
    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file])
        ->assertSessionHas('contactImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'cannot say which one'));
});

it('refuses a file that says two different things about one number', function () {
    $file = csvFile("name,phone\nRahim,01711000000\nRahim again,+8801711000000\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file])
        ->assertSessionHas('contactImportPlan', fn ($plan) => count($plan['errors']) === 1);

    $this->actingAs($this->manager)->post('/crm/contacts/import/apply')->assertSessionHasErrors('file');

    expect(Contact::count())->toBe(0);
});

it('warns that a row with no number can never be matched again', function () {
    $file = csvFile("name,email\nKarim,karim@example.com\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file])
        ->assertSessionHas('contactImportPlan', fn ($plan) => count($plan['warnings']) === 1
            && str_contains($plan['warnings'][0], 'a second time'));
});

it('treats a blank cell as nothing to say, not as an instruction to erase', function () {
    Contact::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'phone' => '01711000000', 'note' => 'Pays on time']);

    $file = csvFile("name,phone,note\nRahim,01711000000,\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file]);
    $this->actingAs($this->manager)->post('/crm/contacts/import/apply');

    // ⚠⚠ Otherwise exporting, clearing a column by accident and re-importing
    // would wipe every note in the book.
    expect(Contact::first()->note)->toBe('Pays on time');
});

it('rejects a file whose column names it does not recognise', function () {
    // ⚠⚠ A file headed `mobile` would import perfectly and silently drop every
    // phone number, which in a phone book is the entire content of the file.
    $file = csvFile("name,mobile\nRahim,01711000000\n");

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => $file])
        ->assertSessionHasErrors('file');
});

it('exports a file its own importer accepts back', function () {
    Contact::create(['business_id' => $this->business->id, 'name' => 'রহিম', 'phone' => '+8801711000000', 'note' => 'Bangla name']);

    $csv = $this->actingAs($this->manager)->get('/crm/contacts/export')->streamedContent();

    // ⚠ The number the human typed, never the normalised one. Handing back our
    // tidied form and re-importing it would overwrite everybody's real number.
    expect($csv)->toContain('+8801711000000')->toContain('রহিম');

    $this->actingAs($this->manager)->post('/crm/contacts/import/review', ['file' => csvFile($csv)])
        ->assertSessionHas('contactImportPlan', fn ($plan) => $plan['errors'] === [] && $plan['unchanged'] === 1);
});
