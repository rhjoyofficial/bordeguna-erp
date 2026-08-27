<?php

namespace App\Modules\Foundation\Support\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Throwable;

/**
 * Anthropic (Claude), through the official PHP SDK.
 *
 * ⚠ The SDK is used rather than raw HTTP because it is already a dependency
 * and it owns the retry, timeout and error-typing behaviour we would otherwise
 * write by hand. The OpenAI driver next to this one does use plain HTTP, for
 * the opposite reason: adding a second SDK to make one endpoint call would be
 * a heavier dependency than the call itself.
 */
class AnthropicDriver implements AssistantDriver
{
    public function label(): string
    {
        return 'Anthropic (Claude)';
    }

    public function defaultModel(): string
    {
        return 'claude-haiku-4-5';
    }

    public function chat(string $key, string $model, array $system, array $messages): string
    {
        try {
            $response = (new Client(apiKey: $key))->messages->create(
                model: $model,
                maxTokens: 4096,
                system: $system,
                messages: $messages,
            );
        } catch (APIStatusException $e) {
            throw $this->translate($e, $model);
        }

        if ($response->stopReason === 'refusal') {
            return 'I cannot help with that. Ask me something about using this ERP.';
        }

        foreach ($response->content as $block) {
            if ($block->type === 'text' && trim($block->text) !== '') {
                return $block->text;
            }
        }

        return 'I could not produce an answer. Please try rephrasing your question.';
    }

    public function stream(string $key, string $model, array $system, array $messages, callable $onText): string
    {
        $full = '';

        try {
            $stream = (new Client(apiKey: $key))->messages->createStream(
                maxTokens: 4096,
                messages: $messages,
                model: $model,
                system: $system,
            );

            foreach ($stream as $event) {
                // Only the text deltas are forwarded. Other events (message
                // start, block start, usage) carry nothing the panel shows,
                // and forwarding them would put the provider's wire format
                // into the browser.
                $text = $this->textOf($event);

                if ($text !== '') {
                    $full .= $text;
                    $onText($text);
                }
            }
        } catch (APIStatusException $e) {
            throw $this->translate($e, $model);
        }

        return $full !== '' ? $full : 'I could not produce an answer. Please try rephrasing your question.';
    }

    /** The text carried by one stream event, or '' if it carries none. */
    private function textOf(mixed $event): string
    {
        if (! is_object($event) || ($event->type ?? null) !== 'content_block_delta') {
            return '';
        }

        $delta = $event->delta ?? null;

        if (! is_object($delta) || ($delta->type ?? null) !== 'text_delta') {
            return '';
        }

        return (string) ($delta->text ?? '');
    }

    public function models(string $key): array
    {
        try {
            $page = (new Client(apiKey: $key))->models->list();
        } catch (Throwable) {
            // A models list that cannot be fetched must not stop the screen
            // rendering; the caller falls back to whatever is already stored.
            return [];
        }

        $models = [];

        foreach ($page->data as $model) {
            $models[(string) $model->id] = (string) ($model->displayName ?: $model->id);
        }

        return $models;
    }

    /**
     * ⚠⚠ A 404 FROM THIS ENDPOINT MEANS THE MODEL, NOT THE URL. That is the
     * exact failure that made the assistant answer "temporarily unavailable"
     * for four days while the key was fine and the model id had a dot in it.
     * Saying which of the two is wrong is the whole difference between a
     * message an Owner can act on and one they cannot.
     */
    private function translate(APIStatusException $e, string $model): AssistantUnavailable
    {
        return match (true) {
            $e->status === 404 => AssistantUnavailable::badModel($model),
            $e->status === 401, $e->status === 403 => AssistantUnavailable::badKey(),
            $e->status === 429 => AssistantUnavailable::rateLimited(),
            default => AssistantUnavailable::unreachable(),
        };
    }
}
