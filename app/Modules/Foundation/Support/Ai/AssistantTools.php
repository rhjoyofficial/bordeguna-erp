<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;
use RuntimeException;
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
 * ⚠⚠ THE TOOL LIST IS BUILT PER PERSON. `read_data` is only offered when the
 * asker can read something, and its description names only the tables they may
 * see. Somebody with no read permissions is never told the tool exists, so the
 * model cannot suggest a question that would only be refused.
 *
 * ⚠ Every dispatch runs through DataQuery or PersonalItems, which re-check
 * permission and ownership themselves. Nothing here is trusted to have got the
 * gating right on the model's behalf; this class only routes.
 */
class AssistantTools
{
    public function __construct(
        private readonly ReadableData $readable,
        private readonly DataQuery $query,
        private readonly PersonalItems $personal,
    ) {}

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

        if ($tables !== []) {
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
                'description' => "Read business data to answer a question. You may ONLY use the tables below; "
                    ."anything else is refused. Results are already limited to this user's business and to what "
                    ."they are allowed to see, so you never need to filter for that yourself.\n\n".$describe,
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

        // ⚠ The two writing tools. Both act on the asker's own rows only, and
        // neither takes a parameter naming a person - there is nothing for the
        // model to put somebody else's id into.
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

        $tools[] = [
            'name' => 'list_notes',
            'description' => "List the current user's own notes. Nobody else's notes can be read.",
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        $tools[] = [
            'name' => 'create_note',
            'description' => "Write a private note for the current user.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ],
        ];

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
                default => throw new RuntimeException("There is no tool called '{$tool}'."),
            };

            return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (Throwable $e) {
            // ⚠ The message, not the class or the stack. Whatever comes back
            // here may be repeated to the user by the model, so it must read as
            // a sentence and must never describe the system's internals.
            return (string) json_encode(['refused' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
        $tables = array_keys($this->readable->forUser($user));

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

        // Everybody has these two, because everybody has their own list.
        $suggestions[] = 'What is on my to-do list?';
        $suggestions[] = 'Remind me to check the stock count tomorrow.';

        return array_slice($suggestions, 0, 5);
    }
}
