<?php

namespace App\Modules\Foundation\Support\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI (ChatGPT), over its HTTP API.
 *
 * ⚠⚠ NOTHING HERE RUNS UNTIL A KEY EXISTS. Joy asked that adding the ChatGPT
 * key be the entire setup, and it is: AiProviders picks whichever provider has
 * an active key, so saving one on Settings > AI providers is what turns this
 * class on. No deploy, no config change, no code path to switch by hand.
 *
 * ⚠ Plain HTTP rather than an SDK on purpose. The whole surface used here is
 * two endpoints; pulling in a second vendor SDK, with its own transport,
 * exceptions and release cadence, would cost more than it saves. The Anthropic
 * driver keeps its SDK because that dependency is already carried.
 *
 * ⚠ The system prompt arrives as ordered blocks (Anthropic's shape, which is
 * what the assistant builds). They are joined into the single system message
 * OpenAI expects. Cache-control markers are dropped: they mean nothing here,
 * and passing them through would be sending one provider's dialect to another.
 */
class OpenAiDriver implements AssistantDriver
{
    private const BASE = 'https://api.openai.com/v1';

    public function label(): string
    {
        return 'OpenAI (ChatGPT)';
    }

    public function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    /**
     * ⚠⚠ NO TOOL SUPPORT HERE, and it says so rather than pretending. This
     * driver answers from the system prompt alone, so a question about live
     * data gets a truthful "I cannot look that up" instead of a confident guess
     * assembled from nothing. Wiring tools for this provider is a separate
     * piece of work, not a line to sneak in.
     */
    public function chatWithTools(
        string $key,
        string $model,
        array $system,
        array $messages,
        array $tools,
        callable $runTool,
        callable $onText,
        ?callable $onStatus = null,
    ): string {
        return $this->stream($key, $model, $system, $messages, $onText);
    }

    public function chat(string $key, string $model, array $system, array $messages): string
    {
        $response = $this->post($key, [
            'model' => $model,
            'messages' => $this->body($system, $messages),
        ]);

        $text = (string) data_get($response, 'choices.0.message.content', '');

        return trim($text) !== '' ? $text : 'I could not produce an answer. Please try rephrasing your question.';
    }

    public function stream(string $key, string $model, array $system, array $messages, callable $onText): string
    {
        $full = '';

        try {
            $response = Http::withToken($key)
                ->timeout(120)
                ->withOptions(['stream' => true])
                ->post(self::BASE.'/chat/completions', [
                    'model' => $model,
                    'messages' => $this->body($system, $messages),
                    'stream' => true,
                ]);
        } catch (Throwable) {
            throw AssistantUnavailable::unreachable();
        }

        if ($response->failed()) {
            throw $this->translate($response->status(), $model);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        // Server-sent events arrive in arbitrary chunks, so a line is only
        // complete once a newline has been seen. Reassembling here rather than
        // assuming one read equals one event is what stops a delta being split
        // down the middle and silently dropped.
        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }

                $text = (string) data_get(json_decode($payload, true), 'choices.0.delta.content', '');

                if ($text !== '') {
                    $full .= $text;
                    $onText($text);
                }
            }
        }

        return $full !== '' ? $full : 'I could not produce an answer. Please try rephrasing your question.';
    }

    public function models(string $key): array
    {
        try {
            $response = Http::withToken($key)->timeout(20)->get(self::BASE.'/models');
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $models = [];

        foreach ((array) $response->json('data', []) as $model) {
            $id = (string) ($model['id'] ?? '');

            // Only the chat models. The account also lists embedding, audio
            // and image models, none of which can answer a message, and an
            // Owner picking one from a dropdown would get a puzzling failure.
            if ($id !== '' && (str_starts_with($id, 'gpt-') || str_starts_with($id, 'o1') || str_starts_with($id, 'o3'))) {
                $models[$id] = $id;
            }
        }

        ksort($models);

        return $models;
    }

    /**
     * @param  array<int, array{type: string, text: string}>  $system
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function body(array $system, array $messages): array
    {
        $prompt = trim(implode("\n\n", array_map(
            fn (array $block) => (string) ($block['text'] ?? ''),
            $system,
        )));

        return array_merge(
            $prompt === '' ? [] : [['role' => 'system', 'content' => $prompt]],
            $messages,
        );
    }

    /** @return array<string, mixed> */
    private function post(string $key, array $payload): array
    {
        try {
            $response = Http::withToken($key)->timeout(120)->post(self::BASE.'/chat/completions', $payload);
        } catch (Throwable) {
            throw AssistantUnavailable::unreachable();
        }

        if ($response->failed()) {
            throw $this->translate($response->status(), (string) ($payload['model'] ?? ''));
        }

        return (array) $response->json();
    }

    private function translate(int $status, string $model): AssistantUnavailable
    {
        return match (true) {
            $status === 404 => AssistantUnavailable::badModel($model),
            $status === 401, $status === 403 => AssistantUnavailable::badKey(),
            $status === 429 => AssistantUnavailable::rateLimited(),
            default => AssistantUnavailable::unreachable(),
        };
    }
}
