<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Models\ChatChannel;
use App\Modules\Foundation\Models\ChatMessage;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal chat.
 *
 * ⚠⚠ NO REAL-TIME PUSH, AND THAT IS DELIBERATE. This project has no websocket
 * server and adding one is infrastructure nobody asked for, so the screen polls
 * a small JSON endpoint. Messages arrive within seconds rather than instantly,
 * and no workflow should be built that depends on the difference.
 *
 * ⚠ The poll endpoint returns only messages NEWER than the last id the client
 * holds. Re-sending the whole channel every few seconds would work perfectly on
 * a channel with forty messages and fall over on the first busy one.
 */
class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $channels = ChatChannel::query()
            ->visibleTo($user)
            ->where('is_archived', false)
            ->withCount('messages')
            ->orderBy('name')
            ->get();

        $current = $channels->firstWhere('id', (int) $request->query('channel')) ?? $channels->first();

        return Inertia::render('chat/index', [
            'channels' => $channels->map(fn (ChatChannel $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'topic' => $c->topic,
                'is_private' => $c->is_private,
                'messages_count' => $c->messages_count,
                'unread' => $this->unreadCount($c, $user),
            ])->values(),
            'current' => $current ? [
                'id' => $current->id,
                'name' => $current->name,
                'topic' => $current->topic,
                'is_private' => $current->is_private,
                'members' => $current->members()->get(['users.id', 'users.name'])
                    ->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ] : null,
            'messages' => $current ? $this->messagesFor($current, $user) : [],
            // Everybody in this business, so a private channel can be given
            // members without a second screen.
            'people' => User::query()
                ->whereHas('businesses', fn ($q) => $q->where('businesses.id', app(BusinessContext::class)->id()))
                ->orderBy('name')->get(['id', 'name']),
            'me' => $user->id,
        ]);
    }

    /**
     * The polling endpoint. Returns JSON, never a page.
     *
     * ⚠ Declared with a JsonResponse return type on purpose: the module
     * reachability guard reads controller return types to decide what is a PAGE,
     * and a polling endpoint must not be expected to appear in the sidebar.
     */
    public function poll(Request $request, ChatChannel $channel): JsonResponse
    {
        $user = $request->user();

        abort_unless($channel->allows($user), 403);

        $after = (int) $request->query('after', 0);

        $messages = $channel->messages()
            ->with('author:id,name')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (ChatMessage $m) => $this->present($m))
            ->values();

        $this->markRead($channel, $user);

        return response()->json(['messages' => $messages]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('chat_channels', 'name')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'topic' => ['nullable', 'string', 'max:255'],
            'is_private' => ['boolean'],
            'members' => ['array'],
            'members.*' => [Rule::exists('users', 'id')],
        ]);

        $channel = ChatChannel::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'topic' => $data['topic'] ?? null,
            'is_private' => (bool) ($data['is_private'] ?? false),
            'created_by' => $request->user()->id,
        ]);

        // ⚠⚠ The creator is ALWAYS a member of a private channel they made.
        // Without this it is possible to create a private channel and instantly
        // lose access to it, which looks exactly like the feature being broken.
        $members = collect($data['members'] ?? [])->push($request->user()->id)->unique()->all();

        if ($channel->is_private) {
            $channel->members()->sync($members);
        }

        return back()->with('success', "#{$channel->name} created.");
    }

    public function storeMessage(Request $request, ChatChannel $channel): RedirectResponse
    {
        $user = $request->user();

        // ⚠ Checked here as well as in the query that listed the channel. The
        // list decides what is shown; this decides what is written, and a client
        // can post an id it was never shown.
        abort_unless($channel->allows($user), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = ChatMessage::create([
            'business_id' => $channel->business_id,
            'chat_channel_id' => $channel->id,
            'user_id' => $user->id,
            'body' => $data['body'],
        ]);

        $channel->members()->updateExistingPivot($user->id, ['last_read_message_id' => $message->id]);

        return back();
    }

    /**
     * ⚠⚠ Removing leaves the row and stamps it. See the model note: a message
     * that vanishes without trace turns a later disagreement into one person's
     * word against another's.
     */
    public function destroyMessage(Request $request, ChatMessage $message): RedirectResponse
    {
        // ⚠ Only the author. Somebody else quietly removing your words is worse
        // than the words.
        abort_unless($message->user_id === $request->user()->id, 403);

        $message->update(['deleted_at' => now()]);

        return back();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function messagesFor(ChatChannel $channel, User $user): array
    {
        $messages = $channel->messages()->with('author:id,name')->orderBy('id')->limit(200)->get();

        $this->markRead($channel, $user);

        return $messages->map(fn (ChatMessage $m) => $this->present($m))->values()->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            // ⚠ The body is withheld once removed, rather than the row being
            // hidden, so the conversation still reads honestly.
            'body' => $message->isRemoved() ? null : $message->body,
            'removed' => $message->isRemoved(),
            'edited' => $message->edited_at !== null,
            'author' => $message->author?->name,
            'author_id' => $message->user_id,
            'at' => $message->created_at?->format('j M, g:i a'),
        ];
    }

    private function markRead(ChatChannel $channel, User $user): void
    {
        $lastId = $channel->messages()->max('id');

        if ($lastId === null) {
            return;
        }

        // ⚠ An open channel has no membership row until somebody reads it, so
        // this creates one rather than assuming it exists.
        $channel->members()->syncWithoutDetaching([$user->id => ['last_read_message_id' => $lastId]]);
    }

    private function unreadCount(ChatChannel $channel, User $user): int
    {
        $member = $channel->members()->where('users.id', $user->id)->first();
        $lastRead = $member?->pivot?->last_read_message_id ?? 0;

        return $channel->messages()->where('id', '>', $lastRead)->count();
    }
}
