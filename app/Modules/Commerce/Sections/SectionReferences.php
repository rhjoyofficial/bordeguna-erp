<?php

namespace App\Modules\Commerce\Sections;

/**
 * Which records a stored payload points at, read through its contract.
 *
 * ⭐ The contract is what makes this possible at all. Because every reference
 * is a declared field of a declared type, the ERP can ask a page "which images
 * do you use" without knowing anything about heroes, figures or galleries, and
 * without a theme being involved. A page storing markup could only be asked
 * that with a regular expression over HTML, which is the same as not being able
 * to ask.
 *
 * ⚠ Walks list fields too. A gallery's images live inside repeated entries, and
 * a check that only looked at the top level would pass a page whose every
 * picture was missing.
 */
class SectionReferences
{
    /**
     * @return array<int, int> media ids, unique, in no particular order
     */
    public function media(SectionType $type, array $payload): array
    {
        return $this->collect($type->fields, $payload, FieldType::Media);
    }

    /**
     * @return array<int, int>
     */
    public function products(SectionType $type, array $payload): array
    {
        return $this->collect($type->fields, $payload, FieldType::ProductRef);
    }

    /**
     * @param  array<int, Field>  $fields
     * @return array<int, int>
     */
    private function collect(array $fields, array $payload, FieldType $wanted): array
    {
        $found = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->name, $payload)) {
                continue;
            }

            $value = $payload[$field->name];

            if ($field->type === $wanted) {
                if (is_int($value) && $value > 0) {
                    $found[] = $value;
                }

                continue;
            }

            if ($field->type === FieldType::ListOf && is_array($value)) {
                foreach ($value as $entry) {
                    if (is_array($entry)) {
                        $found = [...$found, ...$this->collect($field->of, $entry, $wanted)];
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
