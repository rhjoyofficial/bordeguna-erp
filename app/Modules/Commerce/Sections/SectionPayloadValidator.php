<?php

namespace App\Modules\Commerce\Sections;

/**
 * Validates a stored section payload against its type's contract.
 *
 * ⛔⛔ VALIDATION LIVES HERE, WITH THE TYPE, AND NEVER WITH A THEME. A theme
 * cannot loosen a rule, cannot require a field the contract does not have, and
 * cannot make a section mean something else. Two themes rendering the same page
 * must show the same facts, and that is only true if one authority decides what
 * the page contains.
 *
 * ⚠ `$requireComplete` is the difference between SAVING a section and
 * PUBLISHING one. An author composing a page over an afternoon legitimately has
 * a half-filled hero, so a save tolerates a missing required field and refuses
 * everything malformed: a wrong type, an over-long string, an unknown key, a
 * value outside an enum, a script tag. Completeness is then enforced once, at
 * the publish gate, where it is the last thing between unfinished work and a
 * visitor.
 *
 * ⚠ Returns every problem rather than throwing on the first. An author fixing a
 * section one error at a time is an author who gives up, and the same rule the
 * payroll validation already follows.
 */
class SectionPayloadValidator
{
    /**
     * @return array<int, string> a list of human-readable problems; empty means valid
     */
    public function validate(SectionType $type, array $payload, bool $requireComplete = true): array
    {
        return $this->checkFields($type->fields, $payload, '', $requireComplete);
    }

    public function passes(SectionType $type, array $payload): bool
    {
        return $this->validate($type, $payload) === [];
    }

    /**
     * @param  array<int, Field>  $fields
     * @return array<int, string>
     */
    private function checkFields(array $fields, array $payload, string $path, bool $requireComplete = true): array
    {
        $problems = [];

        foreach ($fields as $field) {
            $where = $path === '' ? $field->name : "{$path}.{$field->name}";
            $present = array_key_exists($field->name, $payload);
            $value = $payload[$field->name] ?? null;

            if (! $present || $value === null || $value === '' || $value === []) {
                if ($field->required && $requireComplete) {
                    $problems[] = "[{$where}] is required.";
                }

                continue;
            }

            $problems = [...$problems, ...$this->checkValue($field, $value, $where, $requireComplete)];
        }

        // ⚠ An unknown key is a problem, not something to ignore. It usually
        // means a theme or an import wrote a field the contract does not have,
        // and silently dropping it is how a page loses content nobody notices.
        $known = array_map(fn (Field $field) => $field->name, $fields);

        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $known, true)) {
                $at = $path === '' ? $key : "{$path}.{$key}";
                $problems[] = "[{$at}] is not part of this section's contract.";
            }
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    private function checkValue(Field $field, mixed $value, string $where, bool $requireComplete = true): array
    {
        return match ($field->type) {
            FieldType::Text, FieldType::RichText => $this->checkText($field, $value, $where),
            FieldType::Media, FieldType::ProductRef, FieldType::CategoryRef => $this->checkReference($value, $where),
            FieldType::Link => $this->checkLink($value, $where),
            FieldType::Enumeration => $this->checkChoice($field, $value, $where),
            FieldType::Boolean => is_bool($value) ? [] : ["[{$where}] must be true or false."],
            FieldType::Integer, FieldType::Decimal => $this->checkNumber($field, $value, $where),
            FieldType::ListOf => $this->checkList($field, $value, $where, $requireComplete),
        };
    }

    /**
     * @return array<int, string>
     */
    private function checkText(Field $field, mixed $value, string $where): array
    {
        if (! is_string($value)) {
            return ["[{$where}] must be text."];
        }

        if ($field->max !== null && mb_strlen($value) > $field->max) {
            return ["[{$where}] is longer than {$field->max} characters."];
        }

        // ⛔ Rich text is a CONSTRAINED subset. A content field that accepts
        // arbitrary HTML is a cross-site scripting hole with a friendly name, and
        // a script tag stored here would run on a public shop.
        if ($field->type === FieldType::RichText && preg_match('/<\s*(script|iframe|object|embed|style)\b/i', $value)) {
            return ["[{$where}] contains markup that is not allowed in rich text."];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function checkReference(mixed $value, string $where): array
    {
        // ⚠ A reference is an ID. A section that copied a product's title would
        // show the old title forever after somebody renamed the product.
        return (is_int($value) && $value > 0) ? [] : ["[{$where}] must reference a record by id."];
    }

    /**
     * @return array<int, string>
     */
    private function checkLink(mixed $value, string $where): array
    {
        if (! is_string($value) || $value === '') {
            return ["[{$where}] must be a link."];
        }

        if (str_starts_with($value, '/')) {
            return [];
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            ? []
            : ["[{$where}] must be an internal path starting with / or a full URL."];
    }

    /**
     * @return array<int, string>
     */
    private function checkChoice(Field $field, mixed $value, string $where): array
    {
        if (in_array($value, $field->options, true)) {
            return [];
        }

        $allowed = implode(', ', $field->options);

        return ["[{$where}] must be one of: {$allowed}."];
    }

    /**
     * @return array<int, string>
     */
    private function checkNumber(Field $field, mixed $value, string $where): array
    {
        if (! is_int($value) && ! is_float($value)) {
            return ["[{$where}] must be a number."];
        }

        if ($field->min !== null && $value < $field->min) {
            return ["[{$where}] must be at least {$field->min}."];
        }

        if ($field->max !== null && $value > $field->max) {
            return ["[{$where}] must be at most {$field->max}."];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function checkList(Field $field, mixed $value, string $where, bool $requireComplete = true): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ["[{$where}] must be a list."];
        }

        $problems = [];
        $count = count($value);

        if ($field->min !== null && $count < $field->min) {
            $problems[] = "[{$where}] needs at least {$field->min} entries.";
        }

        if ($field->max !== null && $count > $field->max) {
            $problems[] = "[{$where}] allows at most {$field->max} entries.";
        }

        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $problems[] = "[{$where}.{$index}] must be a group of fields.";

                continue;
            }

            $problems = [...$problems, ...$this->checkFields($field->of, $entry, "{$where}.{$index}", $requireComplete)];
        }

        return $problems;
    }
}
