<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the assistant is allowed to ask for, and what happens when it does.
 *
 * ⚠⚠ THIS LIST IS THE ASSISTANT'S ENTIRE VOCABULARY. It cannot call anything
 * that is not defined here, so "it can read but never write" is not a rule the
 * model is asked to follow - it is the absence of any tool that writes. The only
 * two exceptions are the asker's own notes and to-dos, and they say so in their
 * names.
 *
 * ⚠⚠ THE TOOL LIST IS BUILT PER PERSON, AND EVERYTHING IS OFF BY DEFAULT. Each
 * group of tools needs its own grant, held by no role until somebody gives it.
 * `read_data` additionally needs the asker's module permissions to allow at
 * least one table, and its description names only those tables - so somebody is
 * never told data exists that they could not have read anyway.
 *
 * ⚠ Every dispatch runs through DataQuery or PersonalItems, which re-check
 * permission and ownership themselves. Nothing here is trusted to have got the
 * gating right on the model's behalf; this class only routes.
 */
class AssistantTools
{
    /**
     * ⚠⚠ EVERY CAPABILITY IS OFF UNTIL SOMEBODY GRANTS IT (Joy, 30 Aug 2026).
     * `foundation.ai.use` opens the panel and nothing more. Reading data,
     * touching notes and touching to-dos are three further grants, held by NO
     * ROLE by default - not even Owner. The assistant a business has not
     * configured can do precisely nothing.
     *
     * ⚠⚠ AN AI PERMISSION ONLY EVER NARROWS, NEVER WIDENS. `ai.read` grants the
     * ABILITY TO USE the reading tool; it does not grant access to any table.
     * ReadableData still requires the module permission for every table, so the
     * two are AND, never OR. Somebody holding `ai.read` and nothing else reads
     * nothing at all, and that is the intended behaviour rather than a bug.
     */
    public const READ = 'foundation.ai.read';

    public const NOTES = 'foundation.ai.notes';

    public const TODOS = 'foundation.ai.todos';

    /** Which permission each tool needs. ⛔ A tool absent from this map is
     * callable by nobody - default closed, like every other map here. */
    private const TOOL_PERMISSIONS = [
        'read_data' => self::READ,
        'list_todos' => self::TODOS,
        'create_todo' => self::TODOS,
        'complete_todo' => self::TODOS,
        'list_notes' => self::NOTES,
        'create_note' => self::NOTES,
    ];

    /**
     * The tools actually run this turn, by name, in order.
     *
     * ⚠⚠ THE AUDIT RECORD THE MIGRATION PROMISED AND NOBODY WROTE. The column
     * has existed since the table was created and was NULL on every row,
     * including turns that read the whole product list - so "what has the
     * assistant been reading" had no answer at all. Names only, never
     * arguments: those name tables, and a log recording which table somebody
     * queried is a log that discloses the schema to whoever can read logs.
     *
     * @var list<string>
     */
    private array $used = [];

    public function __construct(
        private readonly ReadableData $readable,
        private readonly DataQuery $query,
        private readonly PersonalItems $personal,
    ) {}

    /**
     * Which tools ran this turn, for the caller that records it.
     *
     * @return list<string>
     */
    public function used(): array
    {
        return $this->used;
    }

    /**
     * The tool definitions for this person, in the PHP SDK's shape.
     *
     * ⚠ `inputSchema`, camelCase. The SDK maps it to the API's `input_schema`
     * on the wire; writing the snake_case form here silently sends no schema.
     *
     * @return list<array<string,mixed>>
     */
    public function definitions(User $user): array
    {
        $tools = [];
        $tables = $this->readable->forUser($user);

        // ⚠ TWO grants required: the AI reading permission AND at least one
        // table their module permissions already allow. Either alone is nothing.
        if ($tables !== [] && $user->can(self::READ)) {
            $describe = collect($tables)
                ->map(fn (array $spec, string $table) => sprintf(
                    '%s (%s): columns %s',
                    $table,
                    $spec['label'],
                    implode(', ', $spec['columns']),
                ))
                ->values()
                ->implode("\n");

            $tools[] = [
                'name' => 'read_data',
                'description' => 'Read business data to answer a question. You may ONLY use the tables below; '
                    ."anything else is refused. Results are already limited to this user's business and to what "
                    ."they are allowed to see, so you never need to filter for that yourself.\n\n"
                    // ⚠⚠ SAYING THIS IS WHAT STOPS "50 products". The result
                    // carries `total` (how many rows match in full) and `rows`
                    // (only a page of them). Counting the page and reporting it
                    // as the total is the exact mistake this sentence prevents.
                    ."The result gives 'total' - how many rows match in full - and 'rows', which is only "
                    ."the first page of them. ALWAYS answer 'how many' from 'total', NEVER by counting "
                    ."'rows'. When 'truncated' is true, say you are showing the first few of 'total'.\n\n".$describe,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => ['type' => 'string', 'description' => 'One of the table names listed above.'],
                        'columns' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Which columns to return. Omit for all readable columns.',
                        ],
                        'filters' => [
                            'type' => 'array',
                            'description' => 'Conditions, all combined with AND.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'column' => ['type' => 'string'],
                                    'operator' => [
                                        'type' => 'string',
                                        'enum' => ['=', '!=', '>', '>=', '<', '<=', 'like', 'in', 'is null', 'is not null'],
                                    ],
                                    'value' => ['description' => 'The value to compare against.'],
                                ],
                                'required' => ['column', 'operator'],
                            ],
                        ],
                        'limit' => ['type' => 'integer', 'description' => 'Rows to return, at most 200.'],
                    ],
                    'required' => ['table'],
                ],
            ];
        }

        // ⚠ The writing tools. Both act on the asker's own rows only, and
        // neither takes a parameter naming a person - there is nothing for the
        // model to put somebody else's id into. Each group needs its own grant.
        if ($user->can(self::TODOS)) {
            $tools[] = [
                'name' => 'list_todos',
                'description' => "List the current user's own to-do items. Nobody else's list can be read.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'include_done' => ['type' => 'boolean', 'description' => 'Include items already completed.'],
                    ],
                ],
            ];

            $tools[] = [
                'name' => 'create_todo',
                'description' => "Add an item to the current user's own to-do list.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                        'due_on' => ['type' => 'string', 'description' => 'A date, YYYY-MM-DD.'],
                    ],
                    'required' => ['title'],
                ],
            ];

            $tools[] = [
                'name' => 'complete_todo',
                'description' => "Mark one of the current user's own to-do items done, or put it back.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'done' => ['type' => 'boolean', 'description' => 'True to complete, false to reopen.'],
                    ],
                    'required' => ['id'],
                ],
            ];

        }

        if ($user->can(self::NOTES)) {
            $tools[] = [
                'name' => 'list_notes',
                'description' => "List the current user's own notes. Nobody else's notes can be read.",
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ];

            $tools[] = [
                'name' => 'create_note',
                'description' => 'Write a private note for the current user.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                    ],
                    'required' => ['title'],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Run one tool call and return what the model should be told.
     *
     * ⚠⚠ A REFUSAL IS AN ANSWER, NOT AN EXCEPTION. When somebody asks for data
     * they may not see, the model needs to be able to say so in words - so the
     * refusal comes back as ordinary tool output. Letting it throw would abort
     * the whole turn and show a broken panel instead of "you cannot see that".
     *
     * @param  array<string,mixed>  $input
     */
    public function run(User $user, string $tool, array $input): string
    {
        // ⚠⚠ RE-CHECKED HERE, NOT JUST WHEN THE LIST WAS BUILT. A model can name
        // a tool it was never offered - it has seen thousands of APIs and will
        // guess. Trusting definitions() to have gated it would make the tool
        // list a suggestion rather than a boundary.
        $required = self::TOOL_PERMISSIONS[$tool] ?? null;

        if ($required === null || ! $user->can($required)) {
            return (string) json_encode([
                'refused' => 'You do not have permission to use that.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // ⚠ Recorded before it runs, not after. A tool that throws still read
        // something, and a log that lists only the successes answers "what did
        // it read" wrongly.
        $this->used[] = $tool;

        try {
            $result = match ($tool) {
                'read_data' => $this->query->run($user, $input),
                'list_todos' => $this->personal->listTodos($user, (bool) ($input['include_done'] ?? false)),
                'create_todo' => $this->personal->createTodo(
                    $user,
                    (string) ($input['title'] ?? ''),
                    isset($input['notes']) ? (string) $input['notes'] : null,
                    isset($input['due_on']) ? (string) $input['due_on'] : null,
                ),
                'complete_todo' => $this->personal->completeTodo(
                    $user,
                    (int) ($input['id'] ?? 0),
                    (bool) ($input['done'] ?? true),
                ),
                'list_notes' => $this->personal->listNotes($user),
                'create_note' => $this->personal->createNote(
                    $user,
                    (string) ($input['title'] ?? ''),
                    isset($input['body']) ? (string) $input['body'] : null,
                ),
                default => throw new AssistantRefusal("There is no tool called '{$tool}'."),
            };

            return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (AssistantRefusal $e) {
            // ⚠⚠ ONLY AssistantRefusal, which is the type that MEANS "this
            // string was written for a person to read". Catching
            // RuntimeException here instead looks equivalent and is not:
            // QueryException extends PDOException extends RuntimeException, so
            // that version leaked the SQL it was written to hide.
            return (string) json_encode(['refused' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            // ⚠⚠ EVERYTHING ELSE IS AN INTERNAL AND ITS MESSAGE NEVER LEAVES THE
            // SERVER. This used to return $e->getMessage() for any throwable at
            // all, which handed the model text like "Unknown column
            // 'business_id' ... (Connection: mysql, Host: db, Port: 3306,
            // Database: bordeguna_erp, SQL: select ...)" - the schema, the
            // database host and the query, in a string the model is free to
            // repeat to whoever asked. Found 30 Aug 2026, when a real question
            // about the chart of accounts took exactly that path.
            Log::warning('Assistant tool failed', ['tool' => $tool, 'message' => $e->getMessage()]);

            return (string) json_encode([
                'refused' => 'That lookup did not work. Say you could not read it, and do not guess a number.',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Opening suggestions, built from what this person may actually see.
     *
     * ⚠ Never offer a question that would only be refused. Somebody in sales is
     * asked about invoices; a storeman is not asked anything about them.
     *
     * @return list<string>
     */
    public function suggestions(User $user): array
    {
        // ⚠ Never suggest what the person cannot do. Without the reading grant
        // there is nothing to suggest about data at all.
        $tables = $user->can(self::READ) ? array_keys($this->readable->forUser($user)) : [];

        $byTable = [
            'stock_batches' => 'What stock do we have left?',
            'products' => 'Which products have no barcode?',
            'sales_invoices' => 'Which invoices are still unpaid?',
            'customers' => 'Which customers have no price group?',
            'purchase_orders' => 'What purchase orders are still open?',
            'suppliers' => 'List our active suppliers.',
            'accounts' => 'Show me the expense accounts.',
        ];

        $suggestions = [];

        foreach ($byTable as $table => $question) {
            if (in_array($table, $tables, true)) {
                $suggestions[] = $question;
            }
        }

        if ($user->can(self::TODOS)) {
            $suggestions[] = 'What is on my to-do list?';
            $suggestions[] = 'Remind me to check the stock count tomorrow.';
        }

        if ($user->can(self::NOTES)) {
            $suggestions[] = 'Show me my notes.';
        }

        return array_slice($suggestions, 0, 5);
    }
}
