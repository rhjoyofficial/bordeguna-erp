<?php

namespace App\Modules\Foundation\Support\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * OpenAI (ChatGPT), over its Responses API.
 *
 * ⚠⚠ NOTHING HERE RUNS UNTIL A KEY EXISTS. Joy asked that adding the ChatGPT
 * key be the entire setup, and it is: AiProviders picks whichever provider has
 * an active key, so saving one on Settings > AI providers is what turns this
 * class on. No deploy, no config change, no code path to switch by hand. With
 * an Anthropic key also active, Anthropic answers first (AiProviders' order);
 * switching that row off is how OpenAI takes over.
 *
 * ⚠⚠ THE SAME ASSISTANT ON EITHER PROVIDER (21 Sep 2026). Until then this
 * driver had no tools: chatWithTools() fell back to a plain stream, so on
 * OpenAI Hudhud could only recite its guide and could not read one row. It now
 * runs the same loop as AnthropicDriver, with the same ceiling, the same
 * progress labels and the same rule that the driver never decides what a tool
 * may do. The plan and the documentation it rests on, read that day:
 * H:/WORK/BorDeGuna/HUDHUD-OPENAI-PLAN-2026-09-21.md.
 *
 * ⚠⚠ `store: false` ON EVERY REQUEST. The Responses API stores responses unless
 * told not to, and a tool-using turn carries business data the tools read
 * (customers, invoices, stock). Nothing here needs OpenAI to keep it: the
 * conversation is replayed in full on each call, reasoning included.
 *
 * ⚠ Plain HTTP rather than an SDK on purpose. The whole surface used here is
 * two endpoints; pulling in a second vendor SDK, with its own transport,
 * exceptions and release cadence, would cost more than it saves. The Anthropic
 * driver keeps its SDK because that dependency is already carried.
 */
class OpenAiDriver implements AssistantDriver
{
    use AnnouncesToolUse;

    private const BASE = 'https://api.openai.com/v1';

    /** ⚠ The same ceiling as AnthropicDriver, for the same reason: a model that
     * keeps asking for one more lookup would bill forever and never answer. */
    private const MAX_TOOL_ROUNDS = 6;

    /** As Anthropic's maxTokens. On OpenAI it also covers the reasoning tokens. */
    private const MAX_OUTPUT_TOKENS = 4096;

    /**
     * ⚠⚠ A 429 IS TWO DIFFERENT THINGS ON OPENAI. Throttling ("try again") and
     * billing ("no credit, or a spend limit reached", where retrying never
     * helps) share the status; the error's code or type says which. These are
     * the billing ones, from OpenAI's error-codes guide, read 21 Sep 2026.
     */
    private const BILLING_CODES = [
        'credit_balance_exhausted',
        'organization_spend_limit_exceeded',
        'project_spend_limit_exceeded',
        'organization_usage_limit_exceeded',
        'insufficient_quota',
    ];

    private const REFUSAL = 'I cannot help with that. Ask me something about using this ERP.';

    private const NO_ANSWER = 'I could not produce an answer. Please try rephrasing your question.';

    public function label(): string
    {
        return 'OpenAI (ChatGPT)';
    }

    /**
     * The mid tier of the family, the like-for-like of the Claude Sonnet model
     * this assistant ran on ($2 in, $12 out per million tokens, against $3 and
     * $15). `gpt-5.6-luna` is the cheap choice, picked on the settings screen.
     */
    public function defaultModel(): string
    {
        return 'gpt-5.6-terra';
    }

    public function chat(string $key, string $model, array $system, array $messages): string
    {
        return $this->answerOf($this->respond($key, $this->body($model, $this->input($system, $messages))));
    }

    /**
     * One turn that may call tools before answering, the same contract as
     * AnthropicDriver::chatWithTools().
     *
     * ⚠⚠ EVERY OUTPUT ITEM IS REPLAYED, NOT ONLY THE CALLS. OpenAI's guide: "If
     * the model calls multiple functions consecutively, you should pass back all
     * reasoning items, function call items, and function call output items,
     * since the last `user` message." With `store: false` the reasoning item
     * carries its own `encrypted_content`, so replaying the item as it arrived
     * is the whole job; dropping it makes the model reason from scratch each
     * round.
     *
     * ⚠ Each result goes back under the `call_id` of the call it answers, and
     * every call of a round is answered, including two asked for at once.
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
        $input = $this->input($system, $messages);
        $functions = $this->functions($tools);
        $rounds = 0;

        $response = $this->respond($key, $this->body($model, $input, $functions));

        while (($calls = $this->callsIn($response)) !== []) {
            if (++$rounds > self::MAX_TOOL_ROUNDS) {
                $onText("\n\nI stopped after looking things up several times without reaching an answer. Try asking for one thing at a time.");

                return 'stopped after too many lookups';
            }

            foreach ((array) ($response['output'] ?? []) as $item) {
                $input[] = $item;
            }

            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? '');

                // ⚠ Announced before it runs, as a STATUS the panel replaces
                // when the answer comes, never as text in the answer.
                if ($onStatus !== null) {
                    $onStatus($this->announce($name));
                }

                $arguments = json_decode((string) ($call['arguments'] ?? ''), true);

                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($call['call_id'] ?? ''),
                    'output' => $runTool($name, is_array($arguments) ? $arguments : []),
                ];
            }

            $response = $this->respond($key, $this->body($model, $input, $functions));
        }

        // ⚠ Emitted even when it is the refusal or the "no answer" sentence: the
        // panel shows only what reaches $onText, never the return value.
        $answer = $this->answerOf($response);
        $onText($answer);

        return $answer;
    }

    public function stream(string $key, string $model, array $system, array $messages, callable $onText): string
    {
        $response = $this->send($key, $this->body($model, $this->input($system, $messages)) + ['stream' => true], true);

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $full = '';
        $refused = false;

        // Server-sent events arrive in arbitrary chunks, so a line is only
        // complete once a newline has been seen. Reassembling here rather than
        // assuming one read equals one event is what stops a delta being split
        // down the middle and silently dropped.
        while (! $body->eof() || $buffer !== '') {
            if (! $body->eof()) {
                $buffer .= $body->read(1024);
            } elseif (! str_contains($buffer, "\n")) {
                $buffer .= "\n"; // the last line, when the stream did not end on a newline
            }

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $event = json_decode(trim(substr($line, 5)), true);

                if (! is_array($event)) {
                    continue;
                }

                $type = (string) ($event['type'] ?? '');

                if ($type === 'response.output_text.delta') {
                    $text = (string) ($event['delta'] ?? '');

                    if ($text !== '') {
                        $full .= $text;
                        $onText($text);
                    }
                } elseif ($type === 'response.refusal.delta') {
                    $refused = true;
                } elseif ($type === 'error' || $type === 'response.failed') {
                    // ⚠ A failure mid-stream is not an empty answer. Said as
                    // such, with the billing case named when the event names it.
                    $code = (string) ($event['code'] ?? data_get($event, 'response.error.code', ''));

                    throw in_array($code, self::BILLING_CODES, true)
                        ? AssistantUnavailable::noCredit()
                        : AssistantUnavailable::unreachable();
                }
            }
        }

        if ($full === '') {
            $full = $refused ? self::REFUSAL : self::NO_ANSWER;
            $onText($full);
        }

        return $full;
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

            if ($id !== '' && $this->isChatModel($id)) {
                $models[$id] = $id;
            }
        }

        ksort($models);

        return $models;
    }

    /**
     * Only the models that can answer a message. The account also lists
     * embedding, speech, transcription, image, realtime and search models, and
     * an Owner picking one from the dropdown would get a puzzling failure.
     */
    private function isChatModel(string $id): bool
    {
        if (! str_starts_with($id, 'gpt-') && ! str_starts_with($id, 'o1') && ! str_starts_with($id, 'o3')) {
            return false;
        }

        foreach (['transcribe', 'tts', 'audio', 'realtime', 'image', 'search'] as $family) {
            if (str_contains($id, $family)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The request, with the settings every call shares.
     *
     * ⚠ `prompt_cache_options` explicit: without it OpenAI's implicit caching
     * writes the whole prompt, up to the latest message, at 1.25x the input
     * price on every call. Explicit mode writes only at the breakpoint placed
     * after the fixed guide in input(), which is then read back at a tenth.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @param  array<int, array<string, mixed>>  $functions
     * @return array<string, mixed>
     */
    private function body(string $model, array $input, array $functions = []): array
    {
        $body = [
            'model' => $model,
            'input' => $input,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'store' => false,
            'prompt_cache_options' => ['mode' => 'explicit'],
        ];

        // ⚠ Only a model that reasons takes the setting. The settings screen
        // still offers older ids (gpt-4o and the like), and OpenAI refuses a
        // request that names a reasoning effort for them, which the panel
        // would show as "temporarily unavailable" on every message.
        if ($this->reasons($model)) {
            $body['reasoning'] = ['effort' => $this->reasoningEffort()];
        }

        if ($functions !== []) {
            $body['tools'] = $functions;
        }

        return $body;
    }

    /** GPT-5 and later, and the o-series, reason; the GPT-4 family does not. */
    private function reasons(string $model): bool
    {
        return str_starts_with($model, 'gpt-5') || preg_match('/^o\d/', $model) === 1;
    }

    private function reasoningEffort(): string
    {
        $effort = (string) config('services.openai.reasoning_effort', 'low');

        return $effort !== '' ? $effort : 'low';
    }

    /**
     * The system blocks as developer messages, then the conversation.
     *
     * ⚠ The system prompt arrives as ordered blocks (Anthropic's shape, which is
     * what the assistant builds). The block the assistant marks cacheable (the
     * fixed ERP guide, byte-identical for everyone) becomes its own developer
     * message carrying the one explicit cache breakpoint; the rest (who this
     * person is, the language line) follows in a second one. "Top-level
     * `instructions` cannot contain an explicit breakpoint", which is why none
     * of it is sent as instructions.
     *
     * @param  array<int, array<string, mixed>>  $system
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function input(array $system, array $messages): array
    {
        $cached = [];
        $rest = [];

        foreach ($system as $block) {
            $text = trim((string) ($block['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if (isset($block['cacheControl']) && $cached === [] && $rest === []) {
                $cached[] = ['type' => 'input_text', 'text' => $text, 'prompt_cache_breakpoint' => ['mode' => 'explicit']];
            } else {
                $rest[] = ['type' => 'input_text', 'text' => $text];
            }
        }

        $input = [];

        if ($cached !== []) {
            $input[] = ['role' => 'developer', 'content' => $cached];
        }

        if ($rest !== []) {
            $input[] = ['role' => 'developer', 'content' => $rest];
        }

        foreach ($messages as $message) {
            $input[] = $message;
        }

        return $input;
    }

    /**
     * The assistant's tool definitions as OpenAI function tools.
     *
     * ⚠ The assistant writes its schemas in the Anthropic SDK's shape, mostly as
     * `inputSchema` but `run_report` as `input_schema`; either is read.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function functions(array $tools): array
    {
        $functions = [];

        foreach ($tools as $tool) {
            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? ['type' => 'object', 'properties' => []];
            $schema = is_array($schema) ? $schema : ['type' => 'object', 'properties' => []];

            $functions[] = [
                'type' => 'function',
                'name' => (string) ($tool['name'] ?? ''),
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => $this->objectsAsObjects($schema),
                // ⚠ Strict only inside OpenAI's documented subset: "If you turn on
                // Structured Outputs by supplying `strict: true` and call the API
                // with an unsupported JSON Schema, you will receive an error."
                // The assistant's schemas have optional fields, so they go
                // non-strict and each tool keeps checking its own input.
                'strict' => $this->isStrictCompatible($schema),
            ];
        }

        return $functions;
    }

    /**
     * ⚠⚠ PHP WRITES AN EMPTY ARRAY AS `[]`, and a JSON schema's `properties`
     * must be an object. `list_notes` takes no arguments (`'properties' => []`),
     * which would leave as `"properties":[]` and be rejected as a schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function objectsAsObjects(array $schema): array
    {
        if (array_key_exists('properties', $schema)) {
            $properties = (array) $schema['properties'];

            $schema['properties'] = $properties === []
                ? new stdClass
                : array_map(fn ($property) => is_array($property) ? $this->objectsAsObjects($property) : $property, $properties);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->objectsAsObjects($schema['items']);
        }

        return $schema;
    }

    /**
     * Whether OpenAI can enforce this schema: "`additionalProperties` must be
     * set to `false` for each object" and "All fields in `properties` must be
     * marked as `required`", every value typed, every array describing items.
     *
     * @param  array<string, mixed>  $schema
     */
    private function isStrictCompatible(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            $properties = (array) ($schema['properties'] ?? []);

            if (($schema['additionalProperties'] ?? null) !== false) {
                return false;
            }

            if (array_diff(array_keys($properties), (array) ($schema['required'] ?? [])) !== []) {
                return false;
            }

            foreach ($properties as $property) {
                if (! is_array($property) || ! $this->isStrictCompatible($property)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'array') {
            return is_array($schema['items'] ?? null) && $this->isStrictCompatible($schema['items']);
        }

        return $type !== null || isset($schema['enum']) || isset($schema['anyOf']);
    }

    /**
     * The function calls a response asks for, in the order it asked.
     *
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function callsIn(array $response): array
    {
        return array_values(array_filter(
            (array) ($response['output'] ?? []),
            fn ($item) => is_array($item) && ($item['type'] ?? null) === 'function_call',
        ));
    }

    /**
     * The visible text of a response: every `output_text` part of every message,
     * in order. A reply made only of `refusal` parts is the refusal sentence.
     *
     * @param  array<string, mixed>  $response
     */
    private function answerOf(array $response): string
    {
        $text = '';
        $refused = false;

        foreach ((array) ($response['output'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $part) {
                $partType = is_array($part) ? ($part['type'] ?? null) : null;

                if ($partType === 'output_text') {
                    $text .= (string) ($part['text'] ?? '');
                } elseif ($partType === 'refusal') {
                    $refused = true;
                }
            }
        }

        if (trim($text) !== '') {
            return $text;
        }

        return $refused ? self::REFUSAL : self::NO_ANSWER;
    }

    /**
     * One non-streamed request, as the decoded response.
     *
     * ⚠ A 200 whose `status` is `failed` is a failure, not an empty answer.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function respond(string $key, array $payload): array
    {
        $response = (array) $this->send($key, $payload)->json();

        if (($response['status'] ?? null) === 'failed') {
            $code = (string) data_get($response, 'error.code', '');

            throw in_array($code, self::BILLING_CODES, true)
                ? AssistantUnavailable::noCredit()
                : AssistantUnavailable::unreachable();
        }

        return $response;
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $key, array $payload, bool $stream = false): Response
    {
        try {
            $request = Http::withToken($key)->timeout(120);

            if ($stream) {
                $request = $request->withOptions(['stream' => true]);
            }

            $response = $request->post(self::BASE.'/responses', $payload);
        } catch (Throwable) {
            throw AssistantUnavailable::unreachable();
        }

        if ($response->failed()) {
            throw $this->translate($response, (string) ($payload['model'] ?? ''));
        }

        return $response;
    }

    /**
     * ⚠⚠ A 404 FROM THIS ENDPOINT MEANS THE MODEL, NOT THE URL, as for
     * Anthropic. And a 429 means billing when its code or type says so; only
     * the rest of the 429s are "busy".
     */
    private function translate(Response $response, string $model): AssistantUnavailable
    {
        $status = $response->status();
        $code = (string) $response->json('error.code', '');
        $type = (string) $response->json('error.type', '');

        return match (true) {
            $status === 404 => AssistantUnavailable::badModel($model),
            $status === 401, $status === 403 => AssistantUnavailable::badKey(),
            $status === 429 && (in_array($code, self::BILLING_CODES, true) || $type === 'insufficient_quota') => AssistantUnavailable::noCredit(),
            $status === 429 => AssistantUnavailable::rateLimited(),
            default => AssistantUnavailable::unreachable(),
        };
    }
}
