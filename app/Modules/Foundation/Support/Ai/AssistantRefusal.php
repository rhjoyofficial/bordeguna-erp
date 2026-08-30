<?php

namespace App\Modules\Foundation\Support\Ai;

use RuntimeException;

/**
 * A refusal written for a person to read: "You have no note with that number."
 *
 * ⚠⚠ THIS CLASS EXISTS SO THAT "SAFE TO REPEAT" IS A TYPE AND NOT A GUESS. The
 * assistant hands a failed tool's message back to the model, which is free to
 * say it out loud - so the code that catches must be able to tell a sentence
 * meant for a person from an internal that merely happens to be a string.
 *
 * ⛔ CATCHING RuntimeException DOES NOT DO THIS JOB, and the mistake is easy to
 * make: Illuminate\Database\QueryException extends PDOException, which extends
 * RuntimeException. A catch written that way swallows a database error and
 * repeats it complete with the SQL, the connection name and the schema. Found
 * 30 Aug 2026 by the test that pins it, which went red on the first attempt at
 * this fix.
 *
 * ⚠ Every message on one of these is read by somebody who may not be allowed to
 * know the table exists. Name what they did wrong, never what the system is.
 */
class AssistantRefusal extends RuntimeException {}
