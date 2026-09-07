<?php

namespace App\Modules\Commerce\Sections;

use InvalidArgumentException;

/**
 * One field on a section type.
 *
 * ⚠ Immutable and declared in code, not stored in a table. A section type is a
 * CONTRACT, and a contract that can be edited from a screen while pages already
 * depend on it is not a contract. Changing one is a code change, a version bump
 * and a migration of the stored instances, which is exactly the friction that
 * should exist.
 */
final class Field
{
    /**
     * @param  array<int, string>  $options  allowed values, for an enum field
     * @param  array<int, Field>  $of  the shape of each entry, for a list field
     */
    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly bool $required = false,
        public readonly ?int $max = null,
        public readonly ?int $min = null,
        public readonly array $options = [],
        public readonly array $of = [],
        public readonly string $help = '',
    ) {
        if ($type === FieldType::Enumeration && $options === []) {
            throw new InvalidArgumentException("Enum field [{$name}] declares no options, so nothing could ever be valid.");
        }

        if ($type === FieldType::ListOf && $of === []) {
            throw new InvalidArgumentException("List field [{$name}] declares no shape, so its entries could never be validated.");
        }
    }

    public static function text(string $name, int $max = 200, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::Text, $required, $max, help: $help);
    }

    public static function richText(string $name, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::RichText, $required, help: $help);
    }

    public static function media(string $name, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::Media, $required, help: $help);
    }

    public static function product(string $name, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::ProductRef, $required, help: $help);
    }

    public static function category(string $name, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::CategoryRef, $required, help: $help);
    }

    public static function link(string $name, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::Link, $required, help: $help);
    }

    /**
     * @param  array<int, string>  $options
     */
    public static function choice(string $name, array $options, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::Enumeration, $required, options: $options, help: $help);
    }

    public static function boolean(string $name, string $help = ''): self
    {
        return new self($name, FieldType::Boolean, help: $help);
    }

    public static function integer(string $name, ?int $min = null, ?int $max = null, bool $required = false, string $help = ''): self
    {
        return new self($name, FieldType::Integer, $required, $max, $min, help: $help);
    }

    /**
     * @param  array<int, Field>  $of
     */
    public static function listOf(string $name, array $of, int $min = 0, int $max = 50, string $help = ''): self
    {
        return new self($name, FieldType::ListOf, $min > 0, $max, $min, of: $of, help: $help);
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type->value,
            'required' => $this->required,
            'min' => $this->min,
            'max' => $this->max,
            'options' => $this->options,
            'of' => array_map(fn (Field $f) => $f->toArray(), $this->of),
            'help' => $this->help,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }
}
