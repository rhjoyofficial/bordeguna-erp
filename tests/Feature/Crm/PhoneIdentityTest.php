<?php

use App\Modules\Crm\Support\PhoneIdentity;

/**
 * ⚠⚠ The matching key, and nothing else. `phone` keeps exactly what the user
 * typed - this produces a SEPARATE value used only to find the same person
 * twice. C-CRM-12.
 *
 * ⚠ Why this exists when phone normalisation was explicitly REFUSED for
 * suppliers: three suppliers were typed by hand by one person. CRM receives
 * thousands of records from Meta forms, websites, events and a call centre, and
 * identity matching is the difference between one customer with a history and
 * two disconnected rows. The contradiction was flagged to Joy, not hidden.
 */
it('matches the spellings the same Bangladeshi number arrives in', function (string $input, ?string $expected) {
    expect(PhoneIdentity::normalize($input))->toBe($expected);
})->with([
    'plain local' => ['01711000000', '01711000000'],
    'international +880' => ['+8801711000000', '01711000000'],
    'international 880' => ['8801711000000', '01711000000'],
    // ⚠⚠ The one that already bit this project on three REAL supplier rows: a
    // spreadsheet ate the leading zero.
    'lost leading zero' => ['1711000000', '01711000000'],
    'spaces' => ['017 1100 0000', '01711000000'],
    'dashes' => ['017-1100-0000', '01711000000'],
    'brackets and spaces' => ['(+880) 1711-000000', '01711000000'],
]);

it('returns null when there is nothing to match on', function (?string $input) {
    expect(PhoneIdentity::normalize($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'spaces only' => ['   '],
    // ⚠⚠ An empty string would match every OTHER empty string, so every lead
    // without a phone would look like the same person. Null is the honest answer.
    'letters only' => ['no phone'],
]);

it('normalises every spelling of one number to the same value', function () {
    $spellings = ['01711000000', '+8801711000000', '8801711000000', '1711000000', '017 1100 0000'];

    $normalized = collect($spellings)->map(fn (string $s) => PhoneIdentity::normalize($s))->unique();

    expect($normalized)->toHaveCount(1)
        ->and($normalized->first())->toBe('01711000000');
});

it('leaves a number that is not a Bangladeshi mobile alone rather than guessing', function () {
    // ⚠ A landline or a foreign number is stripped of punctuation and otherwise
    // left as-is. Inventing a country rule we have not been given would be
    // exactly the invented business logic this project forbids.
    expect(PhoneIdentity::normalize('02-9556677'))->toBe('029556677')
        ->and(PhoneIdentity::normalize('+44 20 7946 0958'))->toBe('442079460958');
});
