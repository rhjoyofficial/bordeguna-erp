<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Models\AssistantMessage;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AiProviders;
use App\Modules\Foundation\Support\Ai\AssistantIdentity;
use App\Modules\Foundation\Support\Ai\AssistantUnavailable;
use App\Modules\Foundation\Support\AssistantAvailability;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ErpAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantController extends Controller
{
    /**
     * One chat turn, streamed.
     *
     * ⚠⚠ THE RESPONSE IS SERVER-SENT EVENTS, NOT JSON. The panel used to sit
     * silent for the whole generation and then paint a finished answer, which
     * on a long reply is indistinguishable from a hung screen. Text now
     * arrives as it is produced.
     *
     * ⚠ Errors are sent as an `error` event INSIDE the stream, not as an HTTP
     * status. Headers are already on the wire by the time the provider fails,
     * so a status code cannot be set any more; the panel reads the event.
     */
    public function chat(Request $request, ErpAssistant $assistant, AiProviders $providers): StreamedResponse|JsonResponse
    {
        // ⚠⚠ THE ACTUAL SECURITY BOUNDARY, and until 30 Aug 2026 there was
        // none: this endpoint checked only that somebody was logged in, so any
        // user at all could spend money on the API key by POSTing to it. The
        // floating button being hidden is not a control - a hidden button is
        // still a URL. Both halves are required, exactly as the shared prop
        // that decides whether to draw the button.
        abort_unless(
            $request->user()?->can('foundation.ai.use')
                && AssistantAvailability::enabledForCurrentBusiness(),
            403,
            'The assistant is switched off for this business, or you do not have permission to use it.',
        );

        // ⚠⚠ ANSWERED BEFORE THE STREAM OPENS, so it can still be a real status
        // code. Once the first byte is written the headers are gone and every
        // failure has to travel as an in-stream event; "no key at all" is
        // knowable now, and deserves the honest 503 it always had.
        if (! $providers->isConfigured()) {
            return response()->json([
                'reply' => 'The assistant is not configured yet. An Owner can add a key under Settings, AI providers.',
            ], 503);
        }

        // ⚠ Resolved BEFORE validation, because the admin's character limit is
        // one of the rules. Same row the cap below is read from.
        $business = Business::withoutGlobalScopes()
            ->find(app(BusinessContext::class)->id());

        // ⚠⚠ THE ADMIN'S CEILING, ENFORCED HERE AND NOT ONLY IN THE BOX. The
        // panel stops typing at this number, but the panel is a client and the
        // endpoint is a URL - a limit that lives only in the input is a limit
        // anybody can post past. AssistantIdentity clamps it to 4000, so this
        // rule can never be looser than the one it replaced.
        $charLimit = AssistantIdentity::charLimitFor($business);

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:24'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            // ⚠ The limit applies to what somebody TYPES. Older turns already
            // in the transcript are checked against the hard ceiling instead,
            // so lowering the setting cannot make an existing conversation
            // unsendable - it would strand the person mid-thread with no way
            // out but clearing their history.
            'messages.*.content' => ['required', 'string', 'max:'.AssistantIdentity::MAX_CHAR_LIMIT],
            // ⚠ Validated against the catalogue, and AssistantLanguages checks
            // again that it is one of the TWO this business has switched on -
            // the chat box offers two, but the request is only JSON.
            'language' => ['nullable', 'string', 'max:8'],
        ]);

        // The newest message is the one somebody just typed, so it is the one
        // the admin's limit is about.
        $typed = (string) ($data['messages'][count($data['messages']) - 1]['content'] ?? '');

        if (mb_strlen($typed) > $charLimit) {
            return response()->json([
                'reply' => "That message is longer than the {$charLimit} characters this business allows. Please shorten it.",
            ], 422);
        }

        // ⚠⚠ THE SPENDING BRAKE. Every message bills a metered key and nothing
        // else in the ERP could stop it. Checked BEFORE the stream opens so it
        // can still be an honest status code rather than an in-stream event.
        $cap = $business?->ai_monthly_message_cap;

        if ($cap !== null && AssistantMessage::usedThisMonth((int) $business->id) >= $cap) {
            return response()->json([
                'reply' => "This business has used its {$cap} assistant messages for this month. An Owner can raise the limit under Settings, AI providers.",
            ], 429);
        }

        // The transcript must end with the user's new message; rather than
        // policing alternation strictly, require only the last-message rule
        // the providers themselves care about. Checked before the stream
        // opens, so this one can still be an honest status code.
        $last = end($data['messages']);

        if ($last['role'] !== 'user') {
            return response()->json(['reply' => 'The last message must be yours.'], 422);
        }

        $user = $request->user();
        $messages = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $data['messages'],
        );

        $language = $data['language'] ?? null;
        $businessId = (int) ($business?->id ?? 0);

        // ⚠⚠ RECORDED BEFORE THE ANSWER, NOT AFTER. A turn that fails halfway
        // has still been paid for, and a counter that only increments on success
        // is a cap that a run of errors walks straight through.
        $record = AssistantMessage::create([
            'business_id' => $businessId,
            'user_id' => $user->id,
            'question' => (string) ($messages[count($messages) - 1]['content'] ?? ''),
            'language' => $language,
            'answered' => false,
        ]);

        return response()->stream(function () use ($assistant, $user, $messages, $language, $record) {
            try {
                $assistant->streamReply(
                    $user,
                    $messages,
                    function (string $text) {
                        $this->send('text', ['text' => $text]);
                    },
                    $language,
                    // ⚠⚠ ITS OWN EVENT, NOT TEXT. "Looking that up" used to
                    // arrive as `text` and was therefore appended to the answer
                    // and left there. A status is true while you wait and false
                    // once the answer lands; the panel shows it as the typing
                    // indicator's caption and drops it.
                    function (string $status) {
                        $this->send('status', ['status' => $status]);
                    },
                );

                // ⚠ Names only, and only now - the turn is over, so this is the
                // complete list. See the migration for why the arguments are
                // not here.
                $record->update(['answered' => true, 'tools_used' => $assistant->toolsUsed()]);

                $this->send('done', []);
            } catch (AssistantUnavailable $e) {
                // The driver has already reduced this to a sentence safe to
                // show: which of the key or the model is wrong, never why the
                // provider said so.
                // ⚠ Still recorded. A turn that read three tables and then died
                // read three tables, and `answered` stays false to say so.
                $record->update(['tools_used' => $assistant->toolsUsed()]);

                $this->send('error', ['message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::warning('ERP assistant failed', ['message' => $e->getMessage()]);

                $record->update(['tools_used' => $assistant->toolsUsed()]);

                $this->send('error', ['message' => 'The assistant is temporarily unavailable. Please try again in a minute.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // ⚠ Without this, nginx buffers the whole response and the
            // streaming is invisible to the browser: the feature works
            // perfectly in local dev and does nothing in production.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The assistant's face.
     *
     * ⚠⚠ SERVED, NOT LINKED. The file is on the private disk, as every upload in
     * this system is; there is no `storage:link` in the deployment and a symlink
     * would not survive an image rebuild in any case. A picture that uploads
     * cleanly and 404s only in production is the worst shape this could take.
     *
     * ⚠ Authenticated, and scoped to the business in context - a picture is not
     * secret, but the URL is stable and there is no reason to serve one
     * business's branding to somebody who is not in it.
     *
     * ⚠ `nosniff` for the same reason AttachmentController sets it: this is a
     * file somebody uploaded, and the browser must not be free to decide it is
     * something more interesting than an image.
     */
    public function avatar(Request $request): StreamedResponse
    {
        abort_if($request->user() === null, 403);

        $business = Business::withoutGlobalScopes()
            ->find(app(BusinessContext::class)->id());

        $path = (string) ($business?->ai_assistant_avatar_path ?? '');
        $disk = Storage::disk(AssistantIdentity::AVATAR_DISK);

        abort_unless($path !== '' && $disk->exists($path), 404);

        return $disk->response($path, 'assistant-avatar', [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** One server-sent event, flushed immediately. */
    private function send(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
