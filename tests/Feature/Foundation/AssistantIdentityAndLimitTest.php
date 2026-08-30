<?php

use App\Models\User;
use App\Modules\Foundation\Models\AssistantMessage;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantIdentity;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ErpAssistant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The assistant's name, its face, and the ceiling on one message.
 *
 * ⚠⚠ THE LIMIT IS THE ONLY ONE OF THE THREE THAT DOES ANYTHING. A name and a
 * picture are labels; the character limit is money. Every character is billed,
 * and the box is a place somebody can paste two pages by accident.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Identity Co', 'slug' => 'identity-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->business->update(['ai_assistant_enabled' => true]);

    foreach (['foundation.ai.use', 'foundation.organization.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['foundation.ai.use', 'foundation.organization.manage']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    config(['services.anthropic.key' => 'test-key']);
});

it('falls back to the built-in name and limit when nobody has chosen', function () {
    expect(AssistantIdentity::nameFor($this->business))->toBe(AssistantIdentity::DEFAULT_NAME)
        ->and(AssistantIdentity::charLimitFor($this->business))->toBe(AssistantIdentity::DEFAULT_CHAR_LIMIT)
        ->and(AssistantIdentity::avatarUrlFor($this->business))->toBeNull();
});

/**
 * ⚠⚠ CLAMPED ON THE WAY OUT, NOT ONLY ON THE WAY IN. A row written before the
 * ceiling existed, or by a seeder that never saw the validator, must not be
 * able to widen what the request will accept.
 */
it('never lets a stored limit exceed what the request itself allows', function () {
    $this->business->forceFill(['ai_message_char_limit' => 999999])->save();

    expect(AssistantIdentity::charLimitFor($this->business->fresh()))->toBe(AssistantIdentity::MAX_CHAR_LIMIT);

    $this->business->forceFill(['ai_message_char_limit' => 1])->save();

    expect(AssistantIdentity::charLimitFor($this->business->fresh()))->toBe(AssistantIdentity::MIN_CHAR_LIMIT);
});

it('lets an owner name the assistant and set how long a message may be', function () {
    $this->actingAs($this->owner)
        ->post(route('ai-providers.identity'), ['name' => 'Bondhu', 'char_limit' => 500])
        ->assertRedirect();

    $business = $this->business->fresh();

    expect(AssistantIdentity::nameFor($business))->toBe('Bondhu')
        ->and(AssistantIdentity::charLimitFor($business))->toBe(500);
});

/** An empty name means the default, not a chat header with no title in it. */
it('treats an emptied name as a return to the default', function () {
    $this->business->forceFill(['ai_assistant_name' => 'Bondhu'])->save();

    $this->actingAs($this->owner)
        ->post(route('ai-providers.identity'), ['name' => '   ', 'char_limit' => 800])
        ->assertRedirect();

    expect(AssistantIdentity::nameFor($this->business->fresh()))->toBe(AssistantIdentity::DEFAULT_NAME);
});

/**
 * ⚠⚠ THE PRIVATE DISK, SERVED THROUGH A ROUTE. The first version of this put
 * the picture on the `public` disk, which needs `storage:link` - a command this
 * deployment does not run and whose symlink an image rebuild would discard. The
 * upload would have succeeded, the row would have been written, and the picture
 * would have 404'd in production only.
 */
it('keeps the uploaded face on the private disk and serves it through a route', function () {
    Storage::fake(AssistantIdentity::AVATAR_DISK);

    $this->actingAs($this->owner)
        ->post(route('ai-providers.identity'), [
            'name' => 'Bondhu',
            'avatar' => UploadedFile::fake()->image('face.png', 64, 64),
        ])
        ->assertRedirect();

    $path = $this->business->fresh()->ai_assistant_avatar_path;

    expect($path)->toStartWith(AssistantIdentity::AVATAR_DIRECTORY);
    Storage::disk(AssistantIdentity::AVATAR_DISK)->assertExists($path);

    // The panel is handed a URL it can put in an <img>, not a filesystem path.
    expect(AssistantIdentity::avatarUrlFor($this->business->fresh()))->toContain('assistant/avatar');

    $this->actingAs($this->owner)->get(route('assistant.avatar'))->assertOk();
});

it('serves nothing when no face has been uploaded', function () {
    $this->actingAs($this->owner)->get(route('assistant.avatar'))->assertNotFound();
});

/** ⚠ Replacing a face deletes the old file rather than leaving it on disk. */
it('does not leave the previous picture behind', function () {
    Storage::fake(AssistantIdentity::AVATAR_DISK);

    $this->actingAs($this->owner)
        ->post(route('ai-providers.identity'), ['avatar' => UploadedFile::fake()->image('first.png')]);

    $first = $this->business->fresh()->ai_assistant_avatar_path;

    $this->actingAs($this->owner)
        ->post(route('ai-providers.identity'), ['avatar' => UploadedFile::fake()->image('second.png')]);

    $second = $this->business->fresh()->ai_assistant_avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk(AssistantIdentity::AVATAR_DISK)->assertMissing($first);
    Storage::disk(AssistantIdentity::AVATAR_DISK)->assertExists($second);
});

it('keeps the naming screen away from somebody who does not manage the organisation', function () {
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($this->business->id);

    $this->actingAs($stranger)
        ->post(route('ai-providers.identity'), ['name' => 'Mine now'])
        ->assertForbidden();
});

/**
 * ⛔ THE LIMIT LIVES ON THE SERVER, NOT ONLY IN THE BOX. The panel stops the
 * typing, but the panel is a client and /assistant/chat is a URL - a limit
 * enforced only by a maxLength attribute is a limit anybody can post past.
 */
it('refuses a message longer than the limit this business set', function () {
    $this->business->forceFill(['ai_message_char_limit' => 200])->save();

    $this->actingAs($this->owner)
        ->postJson('/assistant/chat', ['messages' => [['role' => 'user', 'content' => str_repeat('a', 201)]]])
        ->assertStatus(422)
        ->assertJsonPath('reply', fn (string $reply) => str_contains($reply, '200'));
});

/**
 * ⚠⚠ LOWERING THE SETTING MUST NOT STRAND A CONVERSATION ALREADY IN PROGRESS.
 * The limit is about what somebody TYPES; older turns already in the transcript
 * are checked against the hard ceiling instead. Without this an admin who
 * tightened the limit would leave people unable to send anything at all until
 * they cleared their history, with nothing on screen to say why.
 */
it('judges only the newest message, so an old long turn does not block the thread', function () {
    $this->business->forceFill(['ai_message_char_limit' => 200])->save();

    $this->mock(ErpAssistant::class)
        ->shouldReceive('toolsUsed')->andReturn([])
        ->shouldReceive('streamReply')->once()->andReturnUsing(function ($user, $messages, $onText) {
            $onText('Fine.');

            return 'Fine.';
        });

    $this->actingAs($this->owner)
        ->postJson('/assistant/chat', ['messages' => [
            ['role' => 'user', 'content' => str_repeat('a', 900)],
            ['role' => 'assistant', 'content' => 'An answer from before the limit was tightened.'],
            ['role' => 'user', 'content' => 'And now a short one.'],
        ]])
        ->assertOk()
        // ⚠ The stream has to be consumed or the closure never runs and the
        // test passes on a response that did nothing.
        ->streamedContent();
});

/**
 * ⚠⚠ PROGRESS IS ITS OWN EVENT. "Looking that up..." used to be streamed as
 * reply text, so it was appended to the answer and stayed there once the answer
 * arrived - a status line welded onto the front of every looked-up reply.
 */
it('sends what it is doing as a status event, never as part of the answer', function () {
    $this->mock(ErpAssistant::class)
        ->shouldReceive('toolsUsed')->andReturn(['read_data'])
        ->shouldReceive('streamReply')
        ->once()
        ->andReturnUsing(function ($user, $messages, $onText, $language, $onStatus) {
            $onStatus('Looking that up');
            $onText('There are 153 products.');

            return 'There are 153 products.';
        });

    $body = $this->actingAs($this->owner)
        ->postJson('/assistant/chat', ['messages' => [['role' => 'user', 'content' => 'How many products?']]])
        ->assertOk()
        ->streamedContent();

    expect($body)->toContain('event: status')
        ->and($body)->toContain('Looking that up')
        // ⚠ The status must NOT have arrived as text; that is the whole bug.
        ->and(substr_count($body, 'event: text'))->toBe(1);
});

/** The audit column the migration promised, finally written. */
it('records which tools the turn ran', function () {
    $this->mock(ErpAssistant::class)
        ->shouldReceive('toolsUsed')->andReturn(['read_data', 'list_todos'])
        ->shouldReceive('streamReply')->once()->andReturnUsing(function ($user, $messages, $onText) {
            $onText('Done.');

            return 'Done.';
        });

    $this->actingAs($this->owner)
        ->postJson('/assistant/chat', ['messages' => [['role' => 'user', 'content' => 'What is on my list?']]])
        ->assertOk()
        ->streamedContent();

    expect(AssistantMessage::latest('id')->first()->tools_used)
        ->toBe(['read_data', 'list_todos']);
});
