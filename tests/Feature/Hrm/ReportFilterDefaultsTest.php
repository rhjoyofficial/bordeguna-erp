<?php

use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportParameterType;
use App\Modules\Hrm\Reporting\ReportRegistry;
use Illuminate\Support\Facades\Validator;

/**
 * ⚠⚠ A report filter's DEFAULT has to survive its own validation rule.
 *
 * Joy, 1 September 2026: "not working Payroll variance." He picked both runs,
 * pressed Print view, and a new tab opened showing the empty filter form again.
 * No error, nowhere.
 *
 * The screen carries every filter in the query string as text. A checkbox
 * speaks '1' and '', and Laravel's `boolean` rule accepts 1, 0, '1', '0' and
 * rejects the string 'true'. A boolean parameter declared with `default: true`
 * reached the screen as the string 'true', which made the checkbox render
 * UNCHECKED - a lie about its own state - and made the validator refuse every
 * export. A refused GET redirects back, and in the fresh tab a print view opens
 * that looks exactly like "the button does nothing".
 *
 * FIVE of the six reports with a checkbox default to true, the salary sheet
 * among them, so five reports could not be exported at all until somebody
 * happened to tick the box and untick it again.
 *
 * ⚠ This tests the CONTRACT the URL actually carries: the default, as a string,
 * against the parameter's own rule. Asserting the default is `true` would have
 * passed all along - being true is not the problem, being un-postable is.
 */
it('sends every filter default in a form its own rule accepts', function () {
    $offenders = [];

    foreach (app(ReportRegistry::class)->all() as $definition) {
        /** @var ReportDefinition $definition */
        foreach ($definition->parameters() as $parameter) {
            $key = array_key_first($parameter->rules());

            // ⚠ A checkbox can only ever send these two. Both must pass, or the
            // report cannot be exported in one of its two states - and the
            // failure is a redirect with nothing on screen to explain it.
            if ($parameter->type === ReportParameterType::Boolean) {
                foreach (['1', ''] as $sent) {
                    if (Validator::make([$key => $sent], $parameter->rules())->fails()) {
                        $offenders[] = "{$definition->key()}.{$key} cannot send '{$sent}'";
                    }
                }

                continue;
            }

            $default = $parameter->toArray()['default'] ?? null;

            if ($default === null || $default === '' || $default === false) {
                continue;
            }

            if (Validator::make([$key => (string) $default], $parameter->rules())->fails()) {
                $offenders[] = "{$definition->key()}.{$key} default '{$default}' is refused by its own rule";
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

it('never hands the screen a checkbox default it cannot represent', function () {
    // ⚠⚠ THE ACTUAL DEFECT. The screen stringifies a default it does not
    // recognise, so `default: true` arrived as 'true' - which the checkbox
    // reads as unchecked and the validator refuses outright. Five of the six
    // reports with a checkbox default to true, the salary sheet among them, so
    // five could not be exported at all. A boolean default must be a real
    // boolean, never a string, and the screen normalises it to '1' or ''.
    $offenders = [];

    foreach (app(ReportRegistry::class)->all() as $definition) {
        foreach ($definition->parameters() as $parameter) {
            if ($parameter->type !== ReportParameterType::Boolean) {
                continue;
            }

            $default = $parameter->toArray()['default'] ?? null;

            if (! ($default === null || is_bool($default))) {
                $offenders[] = "{$definition->key()}: ".array_key_first($parameter->rules());
            }
        }
    }

    expect($offenders)->toBe([], 'These checkbox defaults are not booleans: '.implode(', ', $offenders));
});

it('normalises a checkbox default to the vocabulary the checkbox reads', function () {
    // The control is `checked={values[key] === '1'}`. Anything else renders
    // unchecked, so a default that is not '1' silently misreports itself.
    $source = file_get_contents(resource_path('js/pages/hrm/reports/show.tsx'));

    expect($source)->toContain("if (parameter.type === 'boolean')")
        ->and($source)->toContain("checked={values[parameter.key] === '1'}");
});
