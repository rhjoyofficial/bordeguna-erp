<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Throwable;

/**
 * Which languages the assistant will answer in.
 *
 * Joy, 30 August 2026: the settings screen offers many; exactly two may be
 * active; those two appear as the toggle on the chat box, and the assistant
 * answers in whichever is selected.
 *
 * ⚠⚠ EXACTLY TWO, ENFORCED IN ONE PLACE. A toggle is a two-position control -
 * one language makes it pointless and three makes it something else that has to
 * be designed. The rule lives here so the settings screen, the chat panel and
 * the system prompt cannot each have their own opinion about it.
 *
 * ⚠ Bangla and English by default, because that is what this business writes.
 * Both are what the document circular already assumes, and the SMS engine
 * already measures Bangla separately because it costs three times as much.
 */
final class AssistantLanguages
{
    public const DEFAULTS = ['en', 'bn'];

    public const REQUIRED_ACTIVE = 2;

    /**
     * ⚠ The instruction is written out per language rather than assembled from
     * the name. "Reply in Bangla" and "reply in Bengali" are not equally well
     * understood, and a sentence in the target language itself is understood
     * best of all - so each carries its own.
     *
     * @var array<string,array{label:string, native:string, instruction:string}>
     */
    private const LANGUAGES = [
        'bn' => [
            'label' => 'Bangla',
            'native' => 'বাংলা',
            'instruction' => 'Reply only in Bangla (বাংলা). Write naturally, the way a Bangladeshi colleague would speak. '
                .'Keep product codes, invoice numbers, amounts and English proper nouns exactly as they are.',
        ],
        'en' => [
            'label' => 'English',
            'native' => 'English',
            'instruction' => 'Reply only in English. Keep it plain and direct.',
        ],
        'hi' => [
            'label' => 'Hindi',
            'native' => 'हिन्दी',
            'instruction' => 'Reply only in Hindi (हिन्दी). Keep codes, numbers and English proper nouns as they are.',
        ],
        'ur' => [
            'label' => 'Urdu',
            'native' => 'اردو',
            'instruction' => 'Reply only in Urdu (اردو). Keep codes, numbers and English proper nouns as they are.',
        ],
        'ar' => [
            'label' => 'Arabic',
            'native' => 'العربية',
            'instruction' => 'Reply only in Arabic (العربية). Keep codes, numbers and English proper nouns as they are.',
        ],
        'ms' => [
            'label' => 'Malay',
            'native' => 'Bahasa Melayu',
            'instruction' => 'Reply only in Malay. Keep codes, numbers and English proper nouns as they are.',
        ],
        'zh' => [
            'label' => 'Chinese',
            'native' => '中文',
            'instruction' => 'Reply only in Simplified Chinese (中文). Keep codes, numbers and English proper nouns as they are.',
        ],
    ];

    /** @return array<string,array<string,string>> every language on offer. */
    public static function all(): array
    {
        return self::LANGUAGES;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LANGUAGES);
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::LANGUAGES[$code]);
    }

    /**
     * The two this business has switched on.
     *
     * ⚠⚠ Called from shared props on every page, so it can never throw and never
     * assume a session - the same rule DocumentFont and AssistantAvailability
     * follow. Anything unexpected falls back to the defaults rather than leaving
     * the chat box with no toggle at all.
     *
     * @return list<string>
     */
    public static function activeFor(?Business $business = null): array
    {
        try {
            if ($business === null) {
                $id = app(BusinessContext::class)->id();
                $business = $id === null ? null : Business::withoutGlobalScopes()->find($id);
            }

            $stored = $business?->assistant_languages;

            if (! is_array($stored)) {
                return self::DEFAULTS;
            }

            // ⚠ Filtered against the catalogue, not trusted. A code removed from
            // the catalogue in a later release must not reach the chat box as a
            // toggle nobody can serve.
            $valid = array_values(array_filter($stored, fn ($code) => is_string($code) && self::isSupported($code)));

            return count($valid) === self::REQUIRED_ACTIVE ? $valid : self::DEFAULTS;
        } catch (Throwable) {
            return self::DEFAULTS;
        }
    }

    /**
     * The two, with their labels, for a toggle.
     *
     * @return list<array{code:string, label:string, native:string}>
     */
    public static function toggleFor(?Business $business = null): array
    {
        return array_map(
            fn (string $code) => [
                'code' => $code,
                'label' => self::LANGUAGES[$code]['label'],
                'native' => self::LANGUAGES[$code]['native'],
            ],
            self::activeFor($business),
        );
    }

    /**
     * The system-prompt line for the chosen language.
     *
     * ⚠⚠ A LANGUAGE NOT ACTIVE FOR THIS BUSINESS IS IGNORED, not honoured. The
     * chat box only offers two, but the request is just JSON and could name any
     * code - so the choice is validated against what is actually switched on
     * rather than taken at its word.
     */
    public static function instructionFor(?string $code, ?Business $business = null): string
    {
        $active = self::activeFor($business);
        $chosen = $code !== null && in_array($code, $active, true) ? $code : $active[0];

        return self::LANGUAGES[$chosen]['instruction'];
    }
}
