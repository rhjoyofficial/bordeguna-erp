<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\ChatChannel;
use App\Modules\Foundation\Models\ChatMessage;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Internal chat.
 *
 * ⚠⚠ SAID PLAINLY: the team already uses WhatsApp and this does not beat it at
 * chatting. It exists for the one thing WhatsApp cannot do, which is keep a
 * conversation about the business inside the business, surviving somebody
 * leaving. There is NO real-time push on this project; the screen polls.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Chat Co', 'slug' => 'chat-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'chat.use', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['chat.use']);

    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->alice->businesses()->attach($this->business->id);
    $this->alice->assignRole('Staff');

    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->bob->businesses()->attach($this->business->id);
    $this->bob->assignRole('Staff');
});

function openChannel(int $businessId, int $creator, string $name = 'general'): ChatChannel
{
    return ChatChannel::create([
        'business_id' => $businessId, 'name' => $name, 'created_by' => $creator,
    ]);
}

it('shows an open channel to everybody who may use chat', function () {
    openChannel($this->business->id, $this->alice->id);

    foreach ([$this->alice, $this->bob] as $user) {
        $this->actingAs($user)->get('/chat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('chat/index')->where('channels', fn ($c) => count($c) === 1));
    }
});

it('keeps a private channel away from somebody who is not in it', function () {
    $private = ChatChannel::create([
        'business_id' => $this->business->id, 'name' => 'owners', 'is_private' => true, 'created_by' => $this->alice->id,
    ]);
    $private->members()->attach($this->alice->id);

    $this->actingAs($this->alice)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('channels', fn ($c) => count($c) === 1));

    $this->actingAs($this->bob)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('channels', fn ($c) => count($c) === 0));
});

it('refuses to read or write a private channel through a hand-typed id', function () {
    $private = ChatChannel::create([
        'business_id' => $this->business->id, 'name' => 'owners', 'is_private' => true, 'created_by' => $this->alice->id,
    ]);
    $private->members()->attach($this->alice->id);
    ChatMessage::create([
        'business_id' => $this->business->id, 'chat_channel_id' => $private->id,
        'user_id' => $this->alice->id, 'body' => 'Something private',
    ]);

    // ⚠⚠ The channel LIST decides what is shown; these decide what is read and
    // written. A client can always post an id it was never shown, so the check
    // lives in both places.
    $this->actingAs($this->bob)->get(route('chat.poll', $private->id))->assertForbidden();
    $this->actingAs($this->bob)->post(route('chat.messages.store', $private->id), ['body' => 'sneaking in'])->assertForbidden();

    expect(ChatMessage::count())->toBe(1);
});

it('always puts the creator inside a private channel they made', function () {
    // ⚠⚠ Without this it is possible to create a private channel and instantly
    // lose access to it, which looks exactly like the feature being broken.
    $this->actingAs($this->alice)->post(route('chat.channels.store'), [
        'name' => 'owners', 'is_private' => true, 'members' => [],
    ])->assertRedirect();

    $channel = ChatChannel::first();

    expect($channel->allows($this->alice))->toBeTrue()
        ->and($channel->allows($this->bob))->toBeFalse();
});

it('refuses two channels with the same name in one business', function () {
    openChannel($this->business->id, $this->alice->id, 'general');

    $this->actingAs($this->alice)->post(route('chat.channels.store'), ['name' => 'general'])
        ->assertSessionHasErrors('name');

    // ⚠ Scoped to the business: another business naming a channel "general"
    // must not stop this one.
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-chat-co']);
    ChatChannel::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'name' => 'warehouse', 'created_by' => $this->alice->id,
    ]);

    $this->actingAs($this->alice)->post(route('chat.channels.store'), ['name' => 'warehouse'])->assertRedirect();

    expect(ChatChannel::count())->toBe(2);
});

it('returns only messages newer than the one the client already holds', function () {
    $channel = openChannel($this->business->id, $this->alice->id);

    $first = ChatMessage::create([
        'business_id' => $this->business->id, 'chat_channel_id' => $channel->id, 'user_id' => $this->alice->id, 'body' => 'One',
    ]);
    ChatMessage::create([
        'business_id' => $this->business->id, 'chat_channel_id' => $channel->id, 'user_id' => $this->alice->id, 'body' => 'Two',
    ]);

    // ⚠⚠ Re-sending the whole channel every five seconds works perfectly on a
    // channel with forty messages and falls over on the first busy one.
    $this->actingAs($this->bob)->get(route('chat.poll', $channel->id).'?after='.$first->id)
        ->assertOk()
        ->assertJson(fn ($json) => $json->has('messages', 1)->where('messages.0.body', 'Two'));
});

it('marks a removed message rather than making it disappear', function () {
    $channel = openChannel($this->business->id, $this->alice->id);
    $message = ChatMessage::create([
        'business_id' => $this->business->id, 'chat_channel_id' => $channel->id,
        'user_id' => $this->alice->id, 'body' => 'Something regrettable',
    ]);

    $this->actingAs($this->alice)->delete(route('chat.messages.destroy', $message->id))->assertRedirect();

    // ⚠⚠ The row stays. A message that vanished without trace would turn every
    // later disagreement about what was said into one person's word against
    // another's.
    expect(ChatMessage::count())->toBe(1)
        ->and($message->fresh()->deleted_at)->not->toBeNull();

    $this->actingAs($this->bob)->get('/chat?channel='.$channel->id)
        ->assertInertia(fn ($page) => $page->where('messages.0.removed', true)->where('messages.0.body', null));
});

it('will not let somebody remove another person"s words', function () {
    $channel = openChannel($this->business->id, $this->alice->id);
    $message = ChatMessage::create([
        'business_id' => $this->business->id, 'chat_channel_id' => $channel->id,
        'user_id' => $this->alice->id, 'body' => 'Mine',
    ]);

    $this->actingAs($this->bob)->delete(route('chat.messages.destroy', $message->id))->assertForbidden();

    expect($message->fresh()->deleted_at)->toBeNull();
});

it('counts what somebody has not read, and stops counting once they look', function () {
    $channel = openChannel($this->business->id, $this->alice->id);

    foreach (['One', 'Two', 'Three'] as $body) {
        ChatMessage::create([
            'business_id' => $this->business->id, 'chat_channel_id' => $channel->id,
            'user_id' => $this->alice->id, 'body' => $body,
        ]);
    }

    // ⚠ Bob has never opened it, so everything is unread. The read mark is a
    // message ID, not a count: a count would be wrong the moment two devices
    // read the same channel.
    $this->actingAs($this->bob)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('channels.0.unread', 3));

    // Opening the channel marks it read.
    $this->actingAs($this->bob)->get('/chat?channel='.$channel->id);

    $this->actingAs($this->bob)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('channels.0.unread', 0));
});

it('never shows one business the channels of another', function () {
    openChannel($this->business->id, $this->alice->id, 'ours');

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-chat-co-2']);
    ChatChannel::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'name' => 'theirs', 'created_by' => $this->alice->id,
    ]);

    $this->actingAs($this->alice)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('channels', fn ($c) => count($c) === 1 && $c[0]['name'] === 'ours'));
});

it('refuses chat to a login that does not hold the permission', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/chat')->assertForbidden();
});

// CRM-006 (audit 14 Oct 2026). A channel opened on the FIRST 200 messages ever
// written, so a busy channel showed month-old talk and the newest only crept in
// two hundred at a time through the poll.
it('opens a busy channel on its newest 200 messages, oldest of those first', function () {
    $channel = openChannel($this->business->id, $this->alice->id);

    foreach (range(1, 250) as $i) {
        ChatMessage::create([
            'business_id' => $this->business->id, 'chat_channel_id' => $channel->id, 'user_id' => $this->alice->id, 'body' => "m{$i}",
        ]);
    }

    $this->actingAs($this->bob)->get('/chat?channel='.$channel->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('messages', 200)
            ->where('messages.0.body', 'm51')
            ->where('messages.199.body', 'm250'));
});

// CRM-009 (audit 14 Oct 2026): a private channel's members are this business's.
it('refuses a login from another business as a member of a private channel', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($other->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->actingAs($this->alice)->post('/chat/channels', [
        'name' => 'secret', 'is_private' => true, 'members' => [$this->bob->id, $stranger->id],
    ])->assertSessionHasErrors('members.1');

    expect(ChatChannel::where('name', 'secret')->exists())->toBeFalse();
});
