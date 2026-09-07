<?php

namespace App\Modules\Commerce\Themes;

/**
 * One line of a theme's capability declaration: "I can render this section
 * type, up to this contract version, and if you hand me something newer here is
 * what I will do instead."
 *
 * ⚠ `maxVersion` rather than an exact version, because a theme written against
 * version 1 keeps working when version 1 gains an OPTIONAL field. Only a new
 * major version needs a new declaration.
 *
 * ⛔ `fallback` is the difference between a theme switch being allowed and being
 * refused. A theme that declares no fallback for a type it cannot render is
 * saying "a page using this would go blank", and the ERP takes it at its word.
 */
final class SectionSupport
{
    public function __construct(
        public readonly string $typeKey,
        public readonly int $maxVersion = 1,
        public readonly ?string $fallback = null,
        public readonly string $note = '',
    ) {}

    public function rendersDirectly(string $typeKey, int $version): bool
    {
        return $this->typeKey === $typeKey && $version <= $this->maxVersion;
    }
}
