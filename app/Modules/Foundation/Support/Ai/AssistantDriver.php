<?php

namespace App\Modules\Foundation\Support\Ai;

/**
 * One AI provider, reduced to the three things this ERP asks of it.
 *
 * ⚠⚠ THE POINT OF THIS INTERFACE IS THAT ADDING A KEY IS THE WHOLE SETUP.
 * Joy's ask was "when I add the ChatGPT key it should just work". That is only
 * true if nothing outside these implementations knows which provider is in
 * play - so the assistant, the controller and the settings screen all speak in
 * terms of this interface, and a new provider is one new class plus one line
 * in AiProviders.
 *
 * ⚠ `chat()` returns plain text, not a provider response object. Two providers
 * do not agree on the shape of a message, and every caller here wants the same
 * thing: the words to show in the panel.
 */
interface AssistantDriver
{
    /**
     * One completed turn, as text.
     *
     * @param  array<int, array{type: string, text: string}>  $system  Ordered system blocks.
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws AssistantUnavailable when the provider refuses or cannot be reached.
     */
    public function chat(string $key, string $model, array $system, array $messages): string;

    /**
     * The same turn, streamed. Each chunk of text is passed to $onText as it
     * arrives; the full reply is returned when the turn ends.
     *
     * @param  array<int, array{type: string, text: string}>  $system
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onText
     *
     * @throws AssistantUnavailable
     */
    public function stream(string $key, string $model, array $system, array $messages, callable $onText): string;

    /**
     * Models this key may actually use, as value => label.
     *
     * ⚠⚠ ASKED OF THE PROVIDER, NEVER HARD-CODED. A hand-written list is how
     * `claude-haiku-4.5` got saved and returned 404 on every message for four
     * days: the field accepted anything, and nothing checked. The screen offers
     * what the provider says exists, so a model id is picked, never typed.
     *
     * @return array<string, string>
     */
    public function models(string $key): array;

    /** The label shown to a human, e.g. "Anthropic (Claude)". */
    public function label(): string;

    /** The model to use when none has been chosen. */
    public function defaultModel(): string;
}
