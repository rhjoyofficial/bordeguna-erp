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
    /** ⚠ A model that keeps asking for one more lookup would bill forever
     * and never answer. Six is generous for the questions this is for. */
    private const MAX_TOOL_ROUNDS = 6;

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

    /**
     * One turn that may call tools before answering.
     *
     * ⚠⚠ THE LOOP IS NOT STREAMED, and the answer is emitted at the end. A
     * streamed response cannot be inspected for tool calls until it has
     * finished, so a tool-using turn would have to be buffered anyway. Instead
     * each tool call announces itself through $onText, which is better than a
     * silent panel: a question that reads three tables takes a few seconds, and
     * silence is indistinguishable from a hang.
     *
     * ⚠⚠ A HARD CEILING ON ITERATIONS. Without it a model that keeps asking for
     * one more lookup bills indefinitely and the panel never answers. Six is
     * generous for the questions this assistant is for; hitting it is reported
     * rather than hidden.
     *
     * ⚠ camelCase throughout - `inputSchema`, `toolUseID`, `stopReason`. The
     * PHP SDK maps these to the API's snake_case on the wire, and the
     * snake_case forms are accepted silently while doing nothing.
     */
    public function chatWithTools(
        string $key,
        string $model,
        array $system,
        array $messages,
        array $tools,
        callable $runTool,
        callable $onText,
    ): string {
        $client = new Client(apiKey: $key);
        $rounds = 0;

        try {
            $response = $client->messages->create(
                maxTokens: 4096,
                messages: $messages,
                model: $model,
                system: $system,
                tools: $tools,
            );

            while ($response->stopReason === 'tool_use') {
                if (++$rounds > self::MAX_TOOL_ROUNDS) {
                    $onText("\n\nI stopped after looking things up several times without reaching an answer. Try asking for one thing at a time.");

                    return 'stopped after too many lookups';
                }

                $results = [];

                foreach ($response->content as $block) {
                    if (($block->type ?? null) !== 'tool_use') {
                        continue;
                    }

                    // ⚠ Announced before it runs, so the wait has a reason
                    // attached to it on screen.
                    $onText($this->announce((string) $block->name));

                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $runTool((string) $block->name, (array) $block->input),
                    ];
                }

                if ($results === []) {
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $results];

                $response = $client->messages->create(
                    maxTokens: 4096,
                    messages: $messages,
                    model: $model,
                    system: $system,
                    tools: $tools,
                );
            }
        } catch (APIStatusException $e) {
            throw $this->translate($e, $model);
        }

        if ($response->stopReason === 'refusal') {
            return 'I cannot help with that. Ask me something about using this ERP.';
        }

        $answer = '';

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'text') {
                $answer .= $block->text;
            }
        }

        $onText($answer);

        return $answer;
    }

    /**
     * What to show while a tool runs.
     *
     * ⚠ Describes the ACTION, never the table or the arguments. "Looking that
     * up" is honest; echoing `read_data {"table":"sales_invoices"}` would put
     * the schema on screen for somebody who may not be allowed to know it
     * exists.
     */
    private function announce(string $tool): string
    {
        return match ($tool) {
            'read_data' => "Looking that up...\n",
            'list_todos', 'list_notes' => "Checking your list...\n",
            'create_todo' => "Adding that to your list...\n",
            'complete_todo' => "Updating your list...\n",
            'create_note' => "Writing that note...\n",
            default => "Working on it...\n",
        };
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
