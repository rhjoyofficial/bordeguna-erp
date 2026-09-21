<?php

namespace App\Modules\Foundation\Support\Ai;

/**
 * What the panel shows while a tool runs, shared by every driver.
 *
 * ⚠ One copy on purpose. Two drivers announcing the same tool in two wordings
 * would make the panel read differently depending on which provider happened
 * to answer, and a fix to one list would silently miss the other.
 */
trait AnnouncesToolUse
{
    /**
     * What to show while a tool runs.
     *
     * ⚠ Describes the ACTION, never the table or the arguments. "Looking that
     * up" is honest; echoing `read_data {"table":"sales_invoices"}` would put
     * the schema on screen for somebody who may not be allowed to know it
     * exists.
     *
     * ⚠ No trailing newline any more. These are labels for a transient status
     * line, not lines of an answer.
     */
    private function announce(string $tool): string
    {
        return match ($tool) {
            'read_data' => 'Looking that up',
            'list_todos', 'list_notes' => 'Checking your list',
            'create_todo' => 'Adding that to your list',
            'complete_todo' => 'Updating your list',
            'create_note' => 'Writing that note',
            default => 'Working on it',
        };
    }
}
