<?php

use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\User;
use App\Modules\Foundation\Models\AiCredential;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AnthropicDriver;
use App\Modules\Foundation\Support\Ai\AssistantUnavailable;
use App\Modules\Foundation\Support\Ai\OpenAiDriver;
use App\Modules\Foundation\Support\AiCredentialResolver;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ErpAssistant;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hudhud on OpenAI (21 Sep 2026): the same assistant, reading data, through
 * the Responses API.
 *
 * ⚠⚠ UNTIL THIS FILE, THE OPENAI DRIVER COULD NOT USE A SINGLE TOOL. Its
 * chatWithTools() fell back to a plain stream, so on OpenAI Hudhud could only
 * recite its guide: "how many products do we have" had no way to be answered.
 * These tests pin the loop that makes it the same assistant on either provider.
 *
 * ⚠ No real call is made. Every answer below is shaped after OpenAI's own
 * documentation of the Responses API, read on 21 Sep 2026
 * (H:/WORK/BorDeGuna/HUDHUD-OPENAI-PLAN-2026-09-21.md section 2).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Hudhud Co', 'slug' => 'hudhud-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->business->update(['ai_assistant_enabled' => true]);
    Permission::firstOrCreate(['name' => 'foundation.ai.use', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.ai.use');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->driver = app(OpenAiDriver::class);

    $this->readData = [
        'name' => 'read_data',
        'description' => 'Read business data.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['table'],
        ],
    ];
});

/** A Responses API answer holding these output items. */
function oaiResponse(array $output, string $status = 'completed'): array
{
    return [
        'id' => 'resp_test',
        'object' => 'response',
        'status' => $status,
        'output' => $output,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function oaiText(string $text): array
{
    return [
        'type' => 'message',
        'id' => 'msg_test',
        'role' => 'assistant',
        'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
    ];
}

function oaiCall(string $callId, string $name, array $arguments): array
{
    return [
        'type' => 'function_call',
        'id' => 'fc_'.$callId,
        'call_id' => $callId,
        'name' => $name,
        'arguments' => json_encode($arguments),
        'status' => 'completed',
    ];
}

function oaiReasoning(): array
{
    return ['type' => 'reasoning', 'id' => 'rs_test', 'summary' => [], 'encrypted_content' => 'gAAAA-opaque-reasoning'];
}

function oaiError(int $status, ?string $code, ?string $type): PromiseInterface
{
    return Http::response(['error' => ['message' => 'Provider said no.', 'type' => $type, 'code' => $code]], $status);
}

it('runs the tool the model asks for and sends the result back under the same call id', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(oaiResponse([oaiReasoning(), oaiCall('call_1', 'read_data', ['table' => 'products', 'limit' => 5])]))
        ->push(oaiResponse([oaiText('You have 153 products.')])),
    ]);

    $ran = [];
    $said = '';
    $statuses = [];

    $answer = $this->driver->chatWithTools(
        'sk-test',
        'gpt-5.6-terra',
        [['type' => 'text', 'text' => 'You are Hudhud.']],
        [['role' => 'user', 'content' => 'How many products do we have?']],
        [$this->readData],
        function (string $tool, array $input) use (&$ran): string {
            $ran[] = [$tool, $input];

            return json_encode(['total' => 153]);
        },
        function (string $text) use (&$said): void {
            $said .= $text;
        },
        function (string $status) use (&$statuses): void {
            $statuses[] = $status;
        },
    );

    expect($answer)->toBe('You have 153 products.')
        ->and($said)->toBe('You have 153 products.')
        ->and($ran)->toBe([['read_data', ['table' => 'products', 'limit' => 5]]])
        ->and($statuses)->toBe(['Looking that up']);

    Http::assertSentCount(2);

    // ⚠⚠ The second request replays what the model produced (its reasoning
    // included, which OpenAI asks for) and answers the call by its call_id.
    $input = collect(Http::recorded()[1][0]->data()['input']);

    expect($input->firstWhere('type', 'reasoning')['encrypted_content'])->toBe('gAAAA-opaque-reasoning')
        ->and($input->firstWhere('type', 'function_call')['call_id'])->toBe('call_1')
        ->and($input->firstWhere('type', 'function_call_output'))->toBe([
            'type' => 'function_call_output',
            'call_id' => 'call_1',
            'output' => '{"total":153}',
        ]);
});

it('answers every call of a turn that asks for two at once', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(oaiResponse([
            oaiCall('call_a', 'read_data', ['table' => 'products']),
            oaiCall('call_b', 'read_data', ['table' => 'customers']),
        ]))
        ->push(oaiResponse([oaiText('Both read.')])),
    ]);

    $ran = [];

    $this->driver->chatWithTools('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Both?']], [$this->readData],
        function (string $tool, array $input) use (&$ran): string {
            $ran[] = $input['table'];

            return '{}';
        },
        fn () => null,
    );

    $outputs = collect(Http::recorded()[1][0]->data()['input'])->where('type', 'function_call_output');

    expect($ran)->toBe(['products', 'customers'])
        ->and($outputs->pluck('call_id')->values()->all())->toBe(['call_a', 'call_b']);
});

it('stops after six rounds of lookups and says so, instead of billing forever', function () {
    Http::fake(['api.openai.com/v1/responses' => fn () => Http::response(
        oaiResponse([oaiCall('call_again', 'read_data', ['table' => 'products'])]),
    )]);

    $runs = 0;
    $said = '';

    $answer = $this->driver->chatWithTools('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Loop']], [$this->readData],
        function () use (&$runs): string {
            $runs++;

            return '{}';
        },
        function (string $text) use (&$said): void {
            $said .= $text;
        },
    );

    expect($runs)->toBe(6)
        ->and($answer)->toBe('stopped after too many lookups')
        ->and($said)->toContain('I stopped after looking things up several times');

    Http::assertSentCount(7);
});

it('keeps the progress line out of the answer', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(oaiResponse([oaiCall('call_1', 'create_todo', ['title' => 'Count the oil'])]))
        ->push(oaiResponse([oaiText('Added.')])),
    ]);

    $said = '';
    $statuses = [];

    $this->driver->chatWithTools('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Remind me']], [$this->readData],
        fn () => '{"id":1}',
        function (string $text) use (&$said): void {
            $said .= $text;
        },
        function (string $status) use (&$statuses): void {
            $statuses[] = $status;
        },
    );

    expect($said)->toBe('Added.')
        ->and($statuses)->toBe(['Adding that to your list']);
});

it('asks OpenAI not to store anything, on every request, through the Responses API', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(oaiResponse([oaiText('ready')]))
        ->push("event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"hi\"}\n\n")
        ->push(oaiResponse([oaiCall('call_1', 'read_data', ['table' => 'products'])]))
        ->push(oaiResponse([oaiText('done')])),
    ]);

    $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Say ready']]);
    $this->driver->stream('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hi']], fn () => null);
    $this->driver->chatWithTools('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Read']], [$this->readData], fn () => '{}', fn () => null);

    Http::assertSentCount(4);

    foreach (Http::recorded() as [$request]) {
        expect($request->url())->toBe('https://api.openai.com/v1/responses')
            ->and($request->data()['store'])->toBeFalse()
            ->and($request->hasHeader('Authorization', 'Bearer sk-test'))->toBeTrue();
    }
});

it('sends the fixed guide once as a cached developer message and the personal part after it', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(oaiResponse([oaiText('ok')]))]);

    $this->driver->chat('sk-test', 'gpt-5.6-terra', [
        ['type' => 'text', 'text' => 'THE ERP GUIDE', 'cacheControl' => ['type' => 'ephemeral']],
        ['type' => 'text', 'text' => 'WHAT THIS PERSON MAY DO'],
    ], [['role' => 'user', 'content' => 'Hello']]);

    $body = Http::recorded()[0][0]->data();

    expect($body['reasoning'])->toBe(['effort' => 'low'])
        ->and($body['prompt_cache_options'])->toBe(['mode' => 'explicit'])
        ->and($body['input'][0])->toBe(['role' => 'developer', 'content' => [[
            'type' => 'input_text',
            'text' => 'THE ERP GUIDE',
            'prompt_cache_breakpoint' => ['mode' => 'explicit'],
        ]]])
        ->and($body['input'][1])->toBe(['role' => 'developer', 'content' => [[
            'type' => 'input_text',
            'text' => 'WHAT THIS PERSON MAY DO',
        ]]])
        ->and($body['input'][2])->toBe(['role' => 'user', 'content' => 'Hello']);
});

it('takes the reasoning effort from config', function () {
    config(['services.openai.reasoning_effort' => 'medium']);
    Http::fake(['api.openai.com/v1/responses' => Http::response(oaiResponse([oaiText('ok')]))]);

    $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hello']]);

    expect(Http::recorded()[0][0]->data()['reasoning'])->toBe(['effort' => 'medium']);
});

it('says the account has no credit when OpenAI refuses for billing, and never that it is busy', function (?string $code, ?string $type) {
    Http::fake(['api.openai.com/v1/responses' => oaiError(429, $code, $type)]);

    try {
        $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hello']]);
        $this->fail('expected AssistantUnavailable');
    } catch (AssistantUnavailable $e) {
        expect($e->getMessage())->toBe(AssistantUnavailable::noCredit()->getMessage())
            ->and($e->getMessage())->not->toContain('busy');
    }
})->with([
    'credit exhausted' => ['credit_balance_exhausted', 'requests'],
    'organisation spend limit' => ['organization_spend_limit_exceeded', 'requests'],
    'project spend limit' => ['project_spend_limit_exceeded', 'requests'],
    'organisation usage limit' => ['organization_usage_limit_exceeded', 'requests'],
    'insufficient quota' => ['insufficient_quota', 'insufficient_quota'],
    'insufficient quota type only' => [null, 'insufficient_quota'],
]);

it('tells an Owner what to do about no credit, in words', function () {
    $message = AssistantUnavailable::noCredit()->getMessage();

    expect($message)->toContain('credit')
        ->and($message)->toContain('limit')
        ->and($message)->toContain('Owner')
        ->and($message)->not->toContain('sk-');
});

it('still calls a throttled provider busy', function () {
    Http::fake(['api.openai.com/v1/responses' => oaiError(429, 'rate_limit_exceeded', 'requests')]);

    expect(fn () => $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hello']]))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::rateLimited()->getMessage());
});

it('blames the key for a 401 and the model for a 404', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(['error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 401)
        ->push(['error' => ['message' => 'The model does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 404),
    ]);

    expect(fn () => $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hello']]))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::badKey()->getMessage());

    expect(fn () => $this->driver->chat('sk-test', 'gpt-5.6-nope', [], [['role' => 'user', 'content' => 'Hello']]))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::badModel('gpt-5.6-nope')->getMessage());
});

it('hands the tools over as OpenAI function tools, strict only when the schema allows it', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(oaiResponse([oaiText('ok')]))]);

    $closed = [
        'name' => 'closed_tool',
        'description' => 'Every field required.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
    ];
    // ⚠ The assistant declares run_report with the snake_case key; the
    // conversion must still find its schema.
    $snake = [
        'name' => 'run_report',
        'description' => 'Run a report.',
        'input_schema' => ['type' => 'object', 'properties' => ['report' => ['type' => 'string']], 'required' => ['report']],
    ];
    $empty = ['name' => 'list_notes', 'description' => 'List notes.', 'inputSchema' => ['type' => 'object', 'properties' => []]];

    $this->driver->chatWithTools('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hi']],
        [$this->readData, $closed, $snake, $empty], fn () => '{}', fn () => null);

    $request = Http::recorded()[0][0];
    $tools = collect($request->data()['tools'])->keyBy('name');

    expect($tools->keys()->all())->toBe(['read_data', 'closed_tool', 'run_report', 'list_notes'])
        ->and($tools['read_data']['type'])->toBe('function')
        ->and($tools['read_data']['description'])->toBe('Read business data.')
        ->and($tools['read_data']['parameters']['required'])->toBe(['table'])
        ->and($tools['read_data']['strict'])->toBeFalse()
        ->and($tools['closed_tool']['strict'])->toBeTrue()
        ->and($tools['run_report']['parameters']['properties'])->toHaveKey('report')
        ->and($tools['list_notes']['strict'])->toBeFalse();

    // ⚠⚠ PHP writes an empty array as `[]`, and a JSON schema's properties
    // must be an object. It has to leave as `{}`.
    expect($request->body())->toContain('"properties":{}')
        ->and($request->body())->not->toContain('"properties":[]');
});

it('offers the chat models the key can use and none of the others', function () {
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [
        ['id' => 'gpt-5.6-terra'],
        ['id' => 'gpt-5.6-luna'],
        ['id' => 'gpt-5.6-sol'],
        ['id' => 'text-embedding-3-large'],
        ['id' => 'whisper-1'],
        ['id' => 'gpt-4o-transcribe'],
        ['id' => 'gpt-4o-mini-tts'],
        ['id' => 'gpt-image-1'],
        ['id' => 'gpt-realtime'],
        ['id' => 'gpt-4o-audio-preview'],
        ['id' => 'gpt-4o-search-preview'],
        ['id' => 'dall-e-3'],
    ]])]);

    expect(array_keys($this->driver->models('sk-test')))->toBe(['gpt-5.6-luna', 'gpt-5.6-sol', 'gpt-5.6-terra']);
});

it('answers a refusal with the same sentence the Anthropic driver uses', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(oaiResponse([[
        'type' => 'message',
        'id' => 'msg_refused',
        'role' => 'assistant',
        'status' => 'completed',
        'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that request.']],
    ]]))]);

    expect($this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Something odd']]))
        ->toBe('I cannot help with that. Ask me something about using this ERP.');
});

it('streams the words as they arrive', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(
        "event: response.created\ndata: {\"type\":\"response.created\"}\n\n"
        ."event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hel\"}\n\n"
        ."event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"lo\"}\n\n"
        ."event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\"}}\n\n",
    )]);

    $chunks = [];

    $full = $this->driver->stream('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hi']],
        function (string $text) use (&$chunks): void {
            $chunks[] = $text;
        });

    expect($chunks)->toBe(['Hel', 'lo'])
        ->and($full)->toBe('Hello')
        ->and(Http::recorded()[0][0]->data()['stream'])->toBeTrue();
});

it('treats a failure event in the stream as unavailable, not as an empty answer', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(
        "event: response.failed\ndata: {\"type\":\"response.failed\",\"response\":{\"status\":\"failed\",\"error\":{\"code\":\"server_error\"}}}\n\n",
    )]);

    expect(fn () => $this->driver->stream('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hi']], fn () => null))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::unreachable()->getMessage());
});

it('defaults to gpt-5.6-terra', function () {
    expect($this->driver->defaultModel())->toBe('gpt-5.6-terra');
});

it('answers on the driver default when an OpenAI key is saved without a model, never on a Claude model', function () {
    // ⚠⚠ Until 21 Sep 2026 the resolver fell back to `claude-opus-5` for ANY
    // provider without a stored model, so an OpenAI key saved with "Provider
    // default" sent a Claude model id to OpenAI on every message (a 404),
    // while the Test button used the driver default and said it worked.
    config(['services.anthropic.key' => null]);
    AiCredential::create([
        'provider' => 'openai',
        'secret' => 'sk-not-a-real-key-0000000000',
        'hint' => '0000',
        'is_active' => true,
    ]);
    Http::fake(['api.openai.com/v1/responses' => Http::response(oaiResponse([oaiText('Hello from OpenAI.')]))]);

    $answer = app(ErpAssistant::class)->reply($this->owner, [['role' => 'user', 'content' => 'Hello']]);

    expect($answer)->toBe('Hello from OpenAI.')
        ->and(Http::recorded()[0][0]->data()['model'])->toBe('gpt-5.6-terra');
});

it('says the Anthropic account has no credit instead of calling it temporarily unavailable', function () {
    // The exact answer Anthropic gives an account with no credit: a 400, not a 429.
    $exception = APIStatusException::from(
        new PsrRequest('POST', 'https://api.anthropic.com/v1/messages'),
        new PsrResponse(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => [
            'type' => 'invalid_request_error',
            'message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.',
        ]])),
    );

    $translate = (new ReflectionClass(AnthropicDriver::class))->getMethod('translate');
    $translate->setAccessible(true);

    $result = $translate->invoke(app(AnthropicDriver::class), $exception, 'claude-sonnet-4-5-20250929');

    expect($result->getMessage())->toBe(AssistantUnavailable::noCredit()->getMessage());
});

it('keeps an ordinary Anthropic bad request as unavailable', function () {
    $exception = APIStatusException::from(
        new PsrRequest('POST', 'https://api.anthropic.com/v1/messages'),
        new PsrResponse(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => [
            'type' => 'invalid_request_error',
            'message' => 'messages: at least one message is required',
        ]])),
    );

    $translate = (new ReflectionClass(AnthropicDriver::class))->getMethod('translate');
    $translate->setAccessible(true);

    expect($translate->invoke(app(AnthropicDriver::class), $exception, 'claude-sonnet-4-5-20250929')->getMessage())
        ->toBe(AssistantUnavailable::unreachable()->getMessage());
});

it('says no credit when a response comes back failed for billing, and unavailable for anything else', function () {
    // ⚠ A 200 whose status is "failed" is a failure, not an empty answer.
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(oaiResponse([], 'failed') + ['error' => ['code' => 'insufficient_quota', 'message' => 'No credit.']])
        ->push(oaiResponse([], 'failed') + ['error' => ['code' => 'server_error', 'message' => 'Oops.']]),
    ]);

    expect(fn () => $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hello']]))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::noCredit()->getMessage());

    expect(fn () => $this->driver->chat('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hello']]))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::unreachable()->getMessage());
});

it('says no credit when the stream fails for billing', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(
        "event: error\ndata: {\"type\":\"error\",\"code\":\"credit_balance_exhausted\",\"message\":\"No credit.\"}\n\n",
    )]);

    expect(fn () => $this->driver->stream('sk-test', 'gpt-5.6-terra', [], [['role' => 'user', 'content' => 'Hi']], fn () => null))
        ->toThrow(AssistantUnavailable::class, AssistantUnavailable::noCredit()->getMessage());
});

it('reads real data for the asker through the panel path, with every real tool sent as an OpenAI function', function () {
    // ⚠⚠ The whole point of the slice: on OpenAI, Hudhud reads rows through
    // the same closure ErpAssistant builds for the person asking, with the
    // assistant's REAL tool definitions (not a test double) converted.
    foreach (['inventory.view', 'foundation.ai.read'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::query()->where('name', 'Owner')->where('business_id', $this->business->id)->first()
        ->givePermissionTo(['inventory.view', 'foundation.ai.read']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->owner->refresh();

    config(['services.anthropic.key' => null]);
    AiCredential::create([
        'provider' => 'openai',
        'secret' => 'sk-not-a-real-key-0000000000',
        'hint' => '0000',
        'is_active' => true,
    ]);

    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(oaiResponse([oaiReasoning(), oaiCall('call_p', 'read_data', ['table' => 'products', 'limit' => 5])]))
        ->push(oaiResponse([oaiText('Here are your products.')])),
    ]);

    $said = '';
    $statuses = [];
    $assistant = app(ErpAssistant::class);

    $answer = $assistant->streamReply(
        $this->owner,
        [['role' => 'user', 'content' => 'Show me five products']],
        function (string $text) use (&$said): void {
            $said .= $text;
        },
        'en',
        function (string $status) use (&$statuses): void {
            $statuses[] = $status;
        },
    );

    expect($answer)->toBe('Here are your products.')
        ->and($said)->toBe('Here are your products.')
        ->and($statuses)->toBe(['Looking that up'])
        ->and($assistant->toolsUsed())->toContain('read_data');

    [$first] = Http::recorded()[0];
    $tools = collect($first->data()['tools']);

    expect($first->data()['model'])->toBe('gpt-5.6-terra')
        ->and($tools->pluck('name'))->toContain('read_data')
        ->and($tools->every(fn ($tool) => $tool['type'] === 'function' && is_array($tool['parameters'] ?? null) && ($tool['parameters']['type'] ?? null) === 'object'))->toBeTrue()
        ->and($first->body())->not->toContain('"properties":[]');

    // The tool really ran for the asker: its rows went back under the call id.
    $output = collect(Http::recorded()[1][0]->data()['input'])->firstWhere('type', 'function_call_output');

    expect($output['call_id'])->toBe('call_p')
        ->and(json_decode($output['output'], true))->toHaveKey('rows');
});

it('never names a Claude model for a provider with no model stored or configured', function () {
    // ⚠ Two guards close the same hole: the `openai` block in config/services.php
    // (its model is null, so the driver default applies) and the resolver's
    // empty fallback, for a provider with no block at all. Either alone would
    // pass the panel test above; this one pins the resolver.
    config(['services.openai' => ['key' => null]]);

    expect(app(AiCredentialResolver::class)->modelFor('openai'))->toBe('')
        ->and(app(AiCredentialResolver::class)->modelFor('anthropic'))->toBe(config('services.anthropic.model'));
});

it('sends a reasoning effort only to a model that reasons', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(oaiResponse([oaiText('ok')]))]);

    $this->driver->chat('sk-test', 'gpt-4o-mini', [], [['role' => 'user', 'content' => 'Hello']]);
    $this->driver->chat('sk-test', 'o3-mini', [], [['role' => 'user', 'content' => 'Hello']]);

    expect(Http::recorded()[0][0]->data())->not->toHaveKey('reasoning')
        ->and(Http::recorded()[1][0]->data()['reasoning'])->toBe(['effort' => 'low']);
});
