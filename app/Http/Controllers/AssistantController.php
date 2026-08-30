<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Support\Ai\AiProviders;
use App\Modules\Foundation\Support\Ai\AssistantUnavailable;
use App\Modules\Foundation\Support\ErpAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
                && \App\Modules\Foundation\Support\AssistantAvailability::enabledForCurrentBusiness(),
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

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:24'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
            // ⚠ Validated against the catalogue, and AssistantLanguages checks
            // again that it is one of the TWO this business has switched on -
            // the chat box offers two, but the request is only JSON.
            'language' => ['nullable', 'string', 'max:8'],
        ]);

        // ⚠⚠ THE SPENDING BRAKE. Every message bills a metered key and nothing
        // else in the ERP could stop it. Checked BEFORE the stream opens so it
        // can still be an honest status code rather than an in-stream event.
        $business = \App\Modules\Foundation\Models\Business::withoutGlobalScopes()
            ->find(app(\App\Modules\Foundation\Support\BusinessContext::class)->id());

        $cap = $business?->ai_monthly_message_cap;

        if ($cap !== null && \App\Modules\Foundation\Models\AssistantMessage::usedThisMonth((int) $business->id) >= $cap) {
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
        $record = \App\Modules\Foundation\Models\AssistantMessage::create([
            'business_id' => $businessId,
            'user_id' => $user->id,
            'question' => (string) ($messages[count($messages) - 1]['content'] ?? ''),
            'language' => $language,
            'answered' => false,
        ]);

        return response()->stream(function () use ($assistant, $user, $messages, $language, $record) {
            try {
                $assistant->streamReply($user, $messages, function (string $text) {
                    $this->send('text', ['text' => $text]);
                }, $language);

                $record->update(['answered' => true]);

                $this->send('done', []);
            } catch (AssistantUnavailable $e) {
                // The driver has already reduced this to a sentence safe to
                // show: which of the key or the model is wrong, never why the
                // provider said so.
                $this->send('error', ['message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::warning('ERP assistant failed', ['message' => $e->getMessage()]);

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

    /** One server-sent event, flushed immediately. */
    private function send(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
