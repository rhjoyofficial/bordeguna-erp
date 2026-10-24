<?php

use App\Models\User;
use App\Modules\Crm\Corrections\ContactActionPolicy;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactImportBatch;
use App\Modules\Crm\Models\ContactImportBatchRow;
use App\Modules\Foundation\Corrections\ActionReasons;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 3, the phone book (24 Oct 2026; FINAL-PLAN 3.2 "Contact
 * import: Reverse import with preview, only rows not changed since" and 6.3):
 *
 *   a contact nobody messaged    Delete, to the Recycle bin (Owner restores)
 *   a contact somebody messaged  Switch off, never Delete
 *   an applied file              kept as a batch; Reverse import bins what it
 *                                created and puts back what it changed, but
 *                                only rows nobody touched since
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new FoundationPermissionsSeeder)->run();
    (new CrmPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    ActionReasons::seedFor($this->business->id);
    Role::where('name', 'Owner')->where('business_id', $this->business->id)->first()->givePermissionTo('recycle_bin.manage');

    $this->person = function (string $role, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->person)('Owner', 'Joy');
    $this->manager = ($this->person)('Manager', 'Moin');
    $this->staff = ($this->person)('Staff', 'Shuvo');

    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $this->import = function (string $csv, string $name = 'book.csv') {
        $file = UploadedFile::fake()->createWithContent($name, $csv);
        ($this->as)($this->manager)->post('/crm/contacts/import/review', ['file' => $file])->assertSessionHasNoErrors();

        return ($this->as)($this->manager)->post('/crm/contacts/import/apply')->assertSessionHasNoErrors();
    };
});

afterEach(fn () => Carbon::setTestNow());

it('bins a contact nobody messaged, refuses one that was messaged, and the Owner restores', function () {
    $fresh = Contact::create(['business_id' => $this->business->id, 'name' => 'Typo Person', 'phone' => '01711000001']);
    $messaged = Contact::create(['business_id' => $this->business->id, 'name' => 'Real Person', 'phone' => '01711000002']);
    DB::table('message_deliveries')->insert([
        'business_id' => $this->business->id, 'message_campaign_id' => DB::table('message_campaigns')->insertGetId([
            'business_id' => $this->business->id, 'channel' => 'sms', 'name' => 'Eid', 'body' => 'Eid Mubarak', 'status' => 'sent',
            'created_by' => $this->manager->id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'contact_id' => $messaged->id, 'to_address' => '01711000002', 'status' => 'sent',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $policy = app(ContactActionPolicy::class);
    expect(array_map(fn ($a) => $a->action, $policy->actionsFor($fresh, $this->manager)->actions))->toBe([DocumentAction::Delete])
        ->and(array_map(fn ($a) => $a->action, $policy->actionsFor($messaged, $this->manager)->actions))->toBe([DocumentAction::Deactivate])
        ->and($policy->actionsFor($fresh, $this->staff)->actions)->toBe([]);

    ($this->as)($this->staff)->post("/crm/contacts/{$fresh->id}/delete", ['reason_text' => 'Typed by mistake'])->assertForbidden();
    ($this->as)($this->manager)->post("/crm/contacts/{$fresh->id}/delete", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->manager)->post("/crm/contacts/{$fresh->id}/delete", ['reason_text' => 'Typed by mistake'])->assertSessionHasNoErrors();
    ($this->as)($this->manager)->post("/crm/contacts/{$messaged->id}/delete", ['reason_text' => 'Not needed now'])
        ->assertSessionHasErrors(['contact' => 'Messages were sent to this contact. Switch it off instead.']);

    expect(Contact::query()->pluck('name')->all())->toBe(['Real Person'])
        ->and(Contact::onlyTrashed()->sole()->delete_reason)->toBe('Typed by mistake');

    ($this->as)($this->owner)->post("/settings/recycle-bin/crm.contact/{$fresh->id}/restore", ['reason_text' => 'A real customer'])->assertSessionHas('success');

    expect(Contact::query()->count())->toBe(2);
});

it('keeps each applied file as a batch and reverses only the rows nobody touched since', function () {
    $kept = Contact::create(['business_id' => $this->business->id, 'name' => 'Karim', 'phone' => '01711000010', 'company' => 'Old Co']);
    $changedLater = Contact::create(['business_id' => $this->business->id, 'name' => 'Selim', 'phone' => '01711000011']);

    ($this->import)("name,phone,company\nNew One,01711000020,\nNew Two,01711000021,\nKarim,01711000010,New Co\nSelim,01711000011,Selim Traders\n", 'october.csv');

    $batch = ContactImportBatch::query()->sole();

    expect($batch->file_name)->toBe('october.csv')
        ->and($batch->created_count)->toBe(2)
        ->and($batch->updated_count)->toBe(2)
        ->and($batch->rows()->where('kind', ContactImportBatchRow::UPDATED)->where('contact_id', $kept->id)->sole()->before)->toBe(['company' => 'Old Co'])
        ->and($kept->fresh()->company)->toBe('New Co');

    // Somebody works on two rows after the import, a few minutes later.
    Carbon::setTestNow('2026-10-24 10:05:00');
    $changedLater->fresh()->update(['company' => 'Selim and Sons']);
    $newTwo = Contact::query()->where('name', 'New Two')->sole();
    $newTwo->update(['note' => 'Called him, wants the price list']);

    $preview = ($this->as)($this->manager)->getJson("/crm/contacts/imports/{$batch->id}/reverse")->assertOk()->json();

    expect(collect($preview['remove'])->pluck('name')->all())->toBe(['New One'])
        ->and(collect($preview['restore'])->pluck('name')->all())->toBe(['Karim'])
        ->and(collect($preview['kept'])->pluck('name')->sort()->values()->all())->toBe(['New Two', 'Selim'])
        ->and(collect($preview['kept'])->pluck('why')->unique()->values()->all())->toBe(['It was changed after the import.']);

    // Nothing was written by the preview.
    expect(Contact::query()->count())->toBe(4);

    ($this->as)($this->manager)->post("/crm/contacts/imports/{$batch->id}/reverse", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->manager)->post("/crm/contacts/imports/{$batch->id}/reverse", ['reason_text' => 'Imported the wrong file'])->assertSessionHasNoErrors();

    $batch->refresh();

    expect(Contact::query()->where('name', 'New One')->exists())->toBeFalse()
        ->and(Contact::onlyTrashed()->where('name', 'New One')->sole()->delete_reason)->toBe('Import #'.$batch->id.' reversed: Imported the wrong file')
        ->and($kept->fresh()->company)->toBe('Old Co')
        ->and($changedLater->fresh()->company)->toBe('Selim and Sons')
        ->and($newTwo->fresh())->not->toBeNull()
        ->and($batch->reversed_at)->not->toBeNull()
        ->and($batch->reversed_count)->toBe(2)
        ->and($batch->kept_count)->toBe(2)
        ->and($batch->rows()->pluck('outcome')->sort()->values()->all())->toBe(['kept', 'kept', 'removed', 'restored']);

    // The second click is refused; nothing changes twice.
    ($this->as)($this->manager)->post("/crm/contacts/imports/{$batch->id}/reverse", ['reason_text' => 'Clicked twice'])
        ->assertSessionHasErrors(['import' => 'This import was already reversed.']);

    ($this->as)($this->staff)->getJson("/crm/contacts/imports/{$batch->id}/reverse")->assertForbidden();
});

it('never deletes an import batch or its rows', function () {
    ($this->import)("name,phone\nNew One,01711000030\n");

    expect(fn () => ContactImportBatch::query()->sole()->delete())->toThrow(LogicException::class)
        ->and(fn () => ContactImportBatchRow::query()->sole()->delete())->toThrow(LogicException::class);
});
