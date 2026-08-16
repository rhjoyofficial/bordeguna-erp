<?php

namespace App\Modules\Hrm\Support;

use Illuminate\Support\Carbon;

/**
 * What counts as a usable date of birth.
 *
 * ⚠⚠ Written 2026-08-16 after "11 April 2026" was offered for a serving
 * employee, which would have made him four months old. The rule in force was
 * `before:today`, and that date IS before today, so nothing would have stopped
 * it: the record would have been saved, and it would have been believed.
 *
 * A date of birth is not a soft field here. It is an identity record, and
 * `PayslipPassword` turns it into the key to a person's own payslip (SAL-07 /
 * C9b), so a plausible-looking wrong date locks somebody out of their pay
 * document with no error message anywhere. A range is the cheapest guard that
 * turns a typed year into a question instead of a fact.
 *
 * The bounds are deliberately wide. This is not an employment-eligibility check
 * and must never quietly become one: it exists to catch a slipped digit, and
 * anything inside human possibility is for HR to judge, not for validation to
 * refuse.
 */
class DateOfBirthRule
{
    /** Nobody in employment is younger than this. */
    public const MINIMUM_AGE = 14;

    /** Nor older than this; beyond it, a typo is likelier than a birthday. */
    public const MAXIMUM_AGE = 80;

    /**
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'date',
            'before:'.Carbon::today()->subYears(self::MINIMUM_AGE)->toDateString(),
            'after:'.Carbon::today()->subYears(self::MAXIMUM_AGE)->toDateString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute = 'date_of_birth'): array
    {
        return [
            $attribute.'.before' => 'That date of birth makes the employee under '.self::MINIMUM_AGE.'. Please check the year.',
            $attribute.'.after' => 'That date of birth makes the employee over '.self::MAXIMUM_AGE.'. Please check the year.',
        ];
    }
}
