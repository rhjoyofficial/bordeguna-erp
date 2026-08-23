<?php

namespace App\Http\Controllers;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Modules\Foundation\Support\AiCredentialResolver;
use App\Modules\Foundation\Support\ErpAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AssistantController extends Controller
{
    /**
     * One chat turn. The client sends the visible transcript (bounded below);
     * the server adds who the user is and what they may reach - see
     * ErpAssistant for why the prompt is built server-side.
     */
    public function chat(Request $request, ErpAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:24'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);

        // ⚠ Asks whether a key exists, never for the key itself: a controller
        // that holds a secret to make a yes/no decision is a controller that
        // can leak one into an exception trace.
        if (! app(AiCredentialResolver::class)->isConfigured('anthropic')) {
            return response()->json([
                'reply' => 'The assistant is not configured yet. An Owner can add a key under Settings, AI providers.',
            ], 503);
        }

        // The transcript must end with the user's new message and alternate
        // sensibly; rather than policing alternation strictly, require only
        // the last-message rule the API itself cares about.
        $last = end($data['messages']);
        if ($last['role'] !== 'user') {
            return response()->json(['reply' => 'The last message must be yours.'], 422);
        }

        try {
            $reply = $assistant->reply(
                $request->user(),
                array_map(fn (array $m) => ['role' => $m['role'], 'content' => $m['content']], $data['messages']),
            );
        } catch (APIStatusException $e) {
            // Rate limits and outages become a readable sentence in the
            // panel - the module error-surface rule, applied to the one
            // screen that talks back.
            Log::warning('ERP assistant API error', ['type' => $e->type?->value, 'message' => $e->getMessage()]);

            return response()->json([
                'reply' => 'The assistant is temporarily unavailable. Please try again in a minute.',
            ], 503);
        }

        return response()->json(['reply' => $reply]);
    }
}
