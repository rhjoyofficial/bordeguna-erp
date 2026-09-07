<?php

namespace App\Modules\Commerce\Sections;

use RuntimeException;

/**
 * Every section type the ERP knows, keyed by `key@version`.
 *
 * ⚠ Registered from code at boot, not from a table. A theme declares which of
 * these it can render, so the registry is the list a theme is checked against.
 *
 * ⚠ BOTH MAJORS STAY REGISTERED while a migration is in flight. A page holding
 * `story.split@1` must keep rendering on the day `story.split@2` appears, or the
 * upgrade takes the site down instead of improving it.
 */
class SectionTypeRegistry
{
    /** @var array<string, SectionType> */
    private array $types = [];

    public function register(SectionType $type): void
    {
        $identity = $type->identity();

        if (isset($this->types[$identity])) {
            throw new RuntimeException("Section type [{$identity}] is registered twice. Two definitions of one contract is one too many.");
        }

        $this->types[$identity] = $type;
    }

    public function registerMany(array $types): void
    {
        foreach ($types as $type) {
            $this->register($type);
        }
    }

    public function has(string $key, int $version): bool
    {
        return isset($this->types["{$key}@{$version}"]);
    }

    public function get(string $key, int $version): SectionType
    {
        $identity = "{$key}@{$version}";

        return $this->types[$identity]
            ?? throw new RuntimeException("Unknown section type [{$identity}]. A page referencing it cannot be rendered or validated.");
    }

    /**
     * The newest registered version of a type, or null if the key is unknown.
     */
    public function latestVersion(string $key): ?int
    {
        $versions = [];

        foreach ($this->types as $type) {
            if ($type->key === $key) {
                $versions[] = $type->version;
            }
        }

        return $versions === [] ? null : max($versions);
    }

    /**
     * @return array<int, SectionType>
     */
    public function all(): array
    {
        return array_values($this->types);
    }

    /**
     * Types grouped for a picker, newest version of each key only.
     *
     * ⚠ Only the newest, because an author composing a NEW page should never be
     * offered a superseded contract. Old versions stay renderable; they are not
     * offered.
     *
     * @return array<string, array<int, SectionType>>
     */
    public function forAuthoring(): array
    {
        $newest = [];

        foreach ($this->types as $type) {
            $existing = $newest[$type->key] ?? null;

            if ($existing === null || $type->version > $existing->version) {
                $newest[$type->key] = $type;
            }
        }

        $grouped = [];

        foreach ($newest as $type) {
            $grouped[$type->group][] = $type;
        }

        return $grouped;
    }
}
