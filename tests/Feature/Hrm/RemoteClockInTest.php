<?php

use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Support\Geofence;

/**
 * Where a punch came from, and who is allowed to be there.
 *
 * ⚠⚠ THE POINT OF THIS FILE. Joy's complaint was that people clock in from
 * outside the office and it is not trackable. The geofence engine existed and
 * was correct, but it answered only one question - should this be flagged -
 * and returned on its first line when enforcement was off, throwing away a
 * location the browser had already supplied. Distance is now always measured;
 * flagging is a separate decision on top of it.
 */
function office(array $attributes = []): Office
{
    return new Office(array_merge([
        'name' => 'Head Office',
        'geofence_latitude' => 23.7639,
        'geofence_longitude' => 90.3589,
        'geofence_radius_meters' => 200,
        'geofence_enforced' => true,
    ], $attributes));
}

it('measures the distance even when the geofence is switched off', function () {
    // ⚠⚠ THE OLD BEHAVIOUR RETURNED HERE WITH NOTHING. An office with no
    // enforcement still has a location, and "how far away was he" still has an
    // answer. Tracking and policing are different questions.
    $verdict = Geofence::evaluate(office(['geofence_enforced' => false]), 23.8103, 90.4125);

    expect($verdict['outside'])->toBeFalse()
        ->and($verdict['distance'])->not->toBeNull()
        ->and($verdict['distance'])->toBeGreaterThan(5000);
});

it('does not flag a punch inside the fence', function () {
    $verdict = Geofence::evaluate(office(), 23.7640, 90.3590);

    expect($verdict['outside'])->toBeFalse()
        ->and($verdict['reason'])->toBeNull()
        ->and($verdict['distance'])->toBeLessThan(200);
});

it('flags a punch outside the fence and says how far', function () {
    $verdict = Geofence::evaluate(office(), 23.8103, 90.4125);

    expect($verdict['outside'])->toBeTrue()
        ->and($verdict['reason'])->toContain('Head Office')
        ->and($verdict['distance'])->toBeGreaterThan(5000);
});

it('does not flag someone who may clock in remotely, but still records the distance', function () {
    // ⚠⚠ THE APPROVAL PHASE. A field salesman clocking in from a customer's
    // shop is doing his job. Asking somebody to review that every morning
    // teaches them to approve without looking.
    $verdict = Geofence::evaluate(office(), 23.8103, 90.4125, mayWorkRemotely: true);

    expect($verdict['outside'])->toBeFalse()
        ->and($verdict['distance'])->toBeGreaterThan(5000)
        // Still explained, so the punch log can say why it was allowed.
        ->and($verdict['reason'])->toContain('may clock in remotely');
});

it('still flags a missing location even for someone allowed to work remotely', function () {
    // ⚠ The permission says where they may be, not that they may refuse to say
    // where they are. This is the one case that stays a question for everyone.
    $verdict = Geofence::evaluate(office(), null, null, mayWorkRemotely: true);

    expect($verdict['outside'])->toBeTrue()
        ->and($verdict['reason'])->toContain('No location provided')
        ->and($verdict['distance'])->toBeNull();
});

it('measures nothing when the office has no coordinates', function () {
    // ⚠⚠ THE LIVE SITUATION ON 27 AUGUST: all three offices have NULL
    // coordinates, so nothing can be measured and nothing is flagged. Null here
    // must mean "not knowable", never "at the office".
    $verdict = Geofence::evaluate(
        office(['geofence_latitude' => null, 'geofence_longitude' => null, 'geofence_radius_meters' => null]),
        23.8103,
        90.4125,
    );

    expect($verdict['distance'])->toBeNull()
        ->and($verdict['outside'])->toBeFalse()
        ->and($verdict['reason'])->toContain('not configured');
});

it('measures nothing when the employee has no office at all', function () {
    expect(Geofence::evaluate(null, 23.8103, 90.4125))
        ->toMatchArray(['outside' => false, 'reason' => null, 'distance' => null]);
});

it('computes a distance that matches the real world', function () {
    // Head Office to roughly Banani: about 6 km, and certainly not 60 or 600.
    $metres = Geofence::distanceInMeters(23.7639, 90.3589, 23.8103, 90.4125);

    expect($metres)->toBeGreaterThan(5000)->toBeLessThan(9000);
});
