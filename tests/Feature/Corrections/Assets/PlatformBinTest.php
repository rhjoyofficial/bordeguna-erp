<?php

use App\Models\User;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Notifications\ConfirmNewEmailNotification;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\ThingsWorld;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): files, device logins and logins go
 * to the Recycle bin instead of being destroyed; a login's email is fixed
 * safely; Deactivate and Reactivate say why; the bin shows every type, for
 * the Owner only, and only this business's rows (FINAL-PLAN 3.5, 6.3).
 */
beforeEach(function () {
    Storage::fake('local');
    $this->t = ThingsWorld::make();
    $this->w = $this->t->w;
    (new FoundationPermissionsSeeder)->run();
    $this->w->enter($this->w->business);
});

afterEach(fn () => Carbon::setTestNow());

it('moves a library file to the bin with a reason; it cannot be downloaded until the Owner restores it, and the file stays on disk', function () {
    $this->actingAs($this->w->owner)->post(route('files.store'), ['file' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf')])->assertRedirect();
    $file = Attachment::query()->sole();

    $this->actingAs($this->w->owner)->delete(route('files.destroy', $file->id))->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->owner)->delete(route('files.destroy', $file->id), ['reason_text' => 'Uploaded twice'])->assertSessionHas('success');

    expect(Attachment::query()->count())->toBe(0)
        ->and(Attachment::onlyTrashed()->sole()->deleted_by)->toBe($this->w->owner->id);
    Storage::disk('local')->assertExists($file->path);
    $this->actingAs($this->w->owner)->get(route('attachments.show', $file->id))->assertNotFound();

    $this->actingAs($this->w->owner)->post("/settings/recycle-bin/file/{$file->id}/restore", ['reason_text' => 'Needed after all'])->assertSessionHas('success');
    $this->actingAs($this->w->owner)->get(route('attachments.show', $file->id))->assertOk();
});

it('moves a device login to the bin, hides its secret at once, and the Owner restores it', function () {
    $laptop = $this->t->thing();
    $login = AssetCredential::create([
        'business_id' => $this->w->business->id, 'credentialable_type' => $laptop->getMorphClass(), 'credentialable_id' => $laptop->id,
        'label' => 'Windows login', 'kind' => 'user_login', 'username' => 'rakib', 'secret' => 'Sup3rSecret!', 'hint' => 'ret!', 'created_by' => $this->w->owner->id,
    ]);

    $this->actingAs($this->t->custodian)->delete(route('assets.credentials.destroy', $login), [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->t->custodian)->delete(route('assets.credentials.destroy', $login), ['reason_text' => 'Router replaced'])
        ->assertSessionHas('success', 'Login "Windows login" moved to the Recycle bin. Change the password at the provider too.');

    expect(AssetCredential::query()->count())->toBe(0);
    $this->actingAs($this->t->custodian)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('assets.credentials.reveal', $login->id))->assertNotFound();

    $entry = app(RecycleBin::class)->entries($this->w->owner)->firstWhere('type', 'device_login');
    expect($entry['label'])->toBe('Device login Windows login (rakib)')
        ->and(json_encode($entry))->not->toContain('Sup3rSecret');

    $this->actingAs($this->w->owner)->post("/settings/recycle-bin/device_login/{$login->id}/restore", ['reason_text' => 'Still in use'])->assertSessionHas('success');
    expect(AssetCredential::query()->sole()->secret)->toBe('Sup3rSecret!');
});

it('deletes a login that never signed in, refuses one that did, and keeps its email reserved', function () {
    $typo = $this->w->member('Sales', 'New hire');

    $this->actingAs($this->w->owner)->post(route('users.delete-login', $typo), [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->sales)->post(route('users.delete-login', $typo), ['reason_text' => 'Wrong person'])->assertForbidden();
    $this->actingAs($this->w->owner)->post(route('users.delete-login', $typo), ['reason_text' => 'Created by mistake'])->assertSessionHas('success');

    expect(User::query()->whereKey($typo->id)->exists())->toBeFalse()
        ->and(User::onlyTrashed()->whereKey($typo->id)->exists())->toBeTrue();

    // Its email stays taken, with the way out in the sentence.
    $this->actingAs($this->w->owner)->post(route('users.store'), ['name' => 'Again', 'email' => $typo->email, 'password' => 'Password-12345!', 'roles' => ['Sales']])
        ->assertSessionHasErrors(['email' => 'A deleted login has this email. Ask the Owner to restore it from the Recycle bin.']);

    // A login that signed in is deactivated instead.
    $used = $this->w->member('Sales', 'Worker');
    event(new Login('web', $used, false));
    expect($used->fresh()->first_signed_in_at)->not->toBeNull();
    $this->actingAs($this->w->owner)->post(route('users.delete-login', $used), ['reason_text' => 'Left the company'])
        ->assertSessionHasErrors(['reason_text' => 'Worker has signed in before, so the login cannot be deleted. Deactivate it instead.']);

    // An employee's login is fixed, not deleted.
    $linked = $this->w->member('Sales', 'Linked');
    Employee::query()->whereKey($this->t->rakib->id)->update(['user_id' => $linked->id]);
    $this->actingAs($this->w->owner)->post(route('users.delete-login', $linked), ['reason_text' => 'Wrong email typed'])
        ->assertSessionHasErrors(['reason_text' => 'This is the login of employee Rakib Test. Fix its email, or deactivate it.']);

    // The bin shows this team's logins only.
    $elsewhere = $this->w->member('Sales', 'Other team', $this->w->other);
    $elsewhere->forceFill(['deleted_at' => now()])->saveQuietly();
    expect(app(RecycleBin::class)->entries($this->w->owner)->where('type', 'user')->pluck('id')->all())->toBe([$typo->id]);
});

it('fixes a typo in an email before the first sign-in at once, and stops links sent to the wrong address', function () {
    $this->w->manager->givePermissionTo('foundation.users.manage');
    $person = $this->w->member('Sales', 'Shuvo');
    DB::table('password_reset_tokens')->insert(['email' => $person->email, 'token' => 'x', 'created_at' => now()]);

    $this->actingAs($this->w->manager)->post(route('users.email', $person), ['name' => 'Shuvo Ahmed', 'email' => 'shuvo@bordeguna.com'])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->manager)->post(route('users.email', $person), ['name' => 'Shuvo Ahmed', 'email' => 'shuvo@bordeguna.com', 'reason_text' => 'Typo in the email'])->assertSessionHas('success');

    expect($person->fresh()->email)->toBe('shuvo@bordeguna.com')
        ->and($person->fresh()->name)->toBe('Shuvo Ahmed')
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);

    $this->actingAs($this->w->manager)->post(route('users.email', $person), ['name' => 'Shuvo', 'email' => $this->w->owner->email, 'reason_text' => 'Typo in the email'])
        ->assertSessionHasErrors(['email' => 'This email already belongs to another login.']);
});

it('after the first sign-in only the Owner changes the email, and the new address confirms it first', function () {
    Notification::fake();
    $this->w->manager->givePermissionTo('foundation.users.manage');
    $person = $this->w->member('Sales', 'Shuvo');
    $old = $person->email;
    event(new Login('web', $person, false));
    DB::table('password_reset_tokens')->insert(['email' => $old, 'token' => 'x', 'created_at' => now()]);

    $this->actingAs($this->w->manager)->post(route('users.email', $person), ['name' => 'Shuvo', 'email' => 'new@bordeguna.com', 'reason_text' => 'Changed address'])
        ->assertSessionHasErrors(['email' => 'Shuvo has signed in before, so only the Owner can change the email.']);

    $this->actingAs($this->w->owner)->post(route('users.email', $person), ['name' => 'Shuvo', 'email' => 'new@bordeguna.com', 'reason_text' => 'Changed address'])->assertSessionHas('success');

    expect($person->fresh()->email)->toBe($old)->and($person->fresh()->pending_email)->toBe('new@bordeguna.com');

    $url = null;
    Notification::assertSentOnDemand(ConfirmNewEmailNotification::class, function ($notification, $channels, $notifiable) use (&$url) {
        $url = (fn () => $this->url)->call($notification);

        return $notifiable->routes['mail'] === 'new@bordeguna.com';
    });

    // A forged token or an unsigned link changes nothing.
    $this->get(URL::temporarySignedRoute('users.email.confirm', now()->addHour(), ['user' => $person->id, 'token' => 'forged']))->assertRedirect(route('login'));
    expect($person->fresh()->email)->toBe($old);

    $this->get($url)->assertRedirect(route('login'));
    expect($person->fresh()->email)->toBe('new@bordeguna.com')
        ->and($person->fresh()->pending_email)->toBeNull()
        ->and(DB::table('password_reset_tokens')->where('email', $old)->exists())->toBeFalse();

    // Used once: the second click changes nothing.
    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('error');
});

it('deactivates and reactivates with a reason, and never switches off the last active Owner', function () {
    $worker = $this->w->member('Sales', 'Worker');

    $this->actingAs($this->w->owner)->patch(route('users.toggle-active', $worker))->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->owner)->patch(route('users.toggle-active', $worker), ['reason_text' => 'Left the company'])->assertSessionHas('success');
    expect($worker->fresh()->is_active)->toBeFalse();

    $page = $this->actingAs($this->w->owner)->get('/settings/recycle-bin')->assertOk()->original->getData()['page']['props'];
    expect(collect($page['switched_off'])->pluck('id')->all())->toBe([$worker->id]);

    $this->actingAs($this->w->owner)->patch(route('users.toggle-active', $worker), ['reason_text' => 'Came back'])->assertSessionHas('success');
    expect($worker->fresh()->is_active)->toBeTrue();

    $second = $this->w->member('Owner', 'Second owner');
    $this->actingAs($second)->patch(route('users.toggle-active', $this->w->owner), ['reason_text' => 'Testing the guard'])->assertSessionHas('success');
    expect($second->fresh()->whyNotDeactivatable())->toBe('Second owner is the only active Owner. Make someone else an Owner first.');
});
