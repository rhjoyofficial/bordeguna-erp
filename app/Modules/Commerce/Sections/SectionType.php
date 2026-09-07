<?php

namespace App\Modules\Commerce\Sections;

/**
 * One typed block a page may contain.
 *
 * ⭐⭐ This is the contract the whole programme rests on. A page is stored as an
 * ordered list of these with validated payloads, which is what lets a theme be a
 * skin rather than the page itself. Store a page as markup and a theme switch
 * means rebuilding every page by hand.
 *
 * ⚠ VERSION IS PART OF THE IDENTITY. A theme declares the maximum version of
 * each type it can render, so both an old and a new major stay renderable until
 * every stored instance has been migrated. Adding an optional field does not
 * bump the major; adding a required one, renaming one, removing one, or
 * changing what one MEANS does.
 *
 * ⛔ Changing what a field means without changing its name is never allowed. Add
 * a new field and deprecate the old one, or two themes will disagree about what
 * the same page says.
 */
final class SectionType
{
    /**
     * @param  array<int, Field>  $fields
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $label,
        public readonly string $group,
        public readonly array $fields,
        public readonly string $purpose = '',
    ) {}

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The identity a theme declares support against.
     */
    public function identity(): string
    {
        return "{$this->key}@{$this->version}";
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'label' => $this->label,
            'group' => $this->group,
            'purpose' => $this->purpose,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
        ];
    }
}
