<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\Storage;

/**
 * What the assistant is called, what it looks like, and how much one message
 * may contain - all three set per business in Settings, AI providers.
 *
 * ⚠⚠ A DEFAULT LIVES HERE, NEVER IN THE DATABASE. Every column behind this
 * class is nullable and null means "whatever the code says today". Writing the
 * default into each row instead would freeze it: change the default next month
 * and four businesses keep the old one, with nothing on any screen to explain
 * why.
 *
 * ⚠ The name is only ever a LABEL. Nothing about identity changes what the
 * assistant may read - that is ReadableData's job and it never sees this class.
 * Renaming it "Admin Bot" grants precisely nothing, which is the property that
 * makes the field safe to hand to an admin.
 */
class AssistantIdentity
{
    /**
     * The name it answers to until somebody changes it.
     *
     * ⚠ Deliberately not "Claude" or "ChatGPT". Which provider answers is a
     * setting an Owner can switch (AiProviders), and a name that tracked the
     * provider would change under people's feet the day it was switched.
     */
    public const DEFAULT_NAME = 'Guna';

    /** How many characters one message may hold when nobody has chosen. */
    public const DEFAULT_CHAR_LIMIT = 1000;

    /**
     * ⚠⚠ THE CEILING NO SETTING MAY EXCEED, and it is not arbitrary: it is the
     * same 4000 AssistantController validates against. An admin who could set
     * 50000 here would be setting a limit the request itself then refuses, and
     * the box would let somebody type 40000 characters only to be rejected on
     * send. The two numbers must agree, so one of them is derived.
     */
    public const MAX_CHAR_LIMIT = 4000;

    /** Below this the box stops being usable for a real question. */
    public const MIN_CHAR_LIMIT = 100;

    /**
     * Where an uploaded face is kept.
     *
     * ⚠⚠ THE PRIVATE DISK, SERVED THROUGH A ROUTE - like every other file in
     * this system. The `public` disk needs `storage:link`, which this
     * deployment does not run and whose symlink would not survive an image
     * rebuild anyway: the picture would upload cleanly, be recorded cleanly,
     * and 404 in production only. Attachments already take the route approach
     * and this follows them.
     */
    public const AVATAR_DISK = 'local';

    public const AVATAR_DIRECTORY = 'assistant-avatars';

    public static function nameFor(?Business $business): string
    {
        $name = trim((string) ($business?->ai_assistant_name ?? ''));

        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** The URL of the uploaded face, or null to let the panel draw its own. */
    public static function avatarUrlFor(?Business $business): ?string
    {
        $path = trim((string) ($business?->ai_assistant_avatar_path ?? ''));

        if ($path === '') {
            return null;
        }

        // ⚠ Checked, not assumed. A row can outlive the file it names - a
        // restored database against a fresh disk is the ordinary way that
        // happens - and a broken <img> is worse than no picture at all.
        if (! Storage::disk(self::AVATAR_DISK)->exists($path)) {
            return null;
        }

        // ⚠ `v` is the cache-buster. The URL is otherwise identical for every
        // picture this business ever uses, so a replaced face would keep
        // showing the old one until somebody cleared their browser cache.
        return route('assistant.avatar', ['v' => substr(sha1($path), 0, 12)]);
    }

    public static function charLimitFor(?Business $business): int
    {
        $limit = (int) ($business?->ai_message_char_limit ?? 0);

        if ($limit <= 0) {
            $limit = self::DEFAULT_CHAR_LIMIT;
        }

        // ⚠ Clamped on the way OUT as well as on the way in. A row written
        // before the ceiling existed, or by a seeder, must not be able to
        // widen what the request will accept.
        return max(self::MIN_CHAR_LIMIT, min($limit, self::MAX_CHAR_LIMIT));
    }

    /**
     * The identity for the business in context, in the shape the panel reads.
     *
     * @return array{name: string, avatar: ?string, charLimit: int}
     */
    public static function current(): array
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        return [
            'name' => self::nameFor($business),
            'avatar' => self::avatarUrlFor($business),
            'charLimit' => self::charLimitFor($business),
        ];
    }
}
