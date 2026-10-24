<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026): the duplicate warning for a LIST record (a customer, a
 * supplier, an employee, a product, a phone book entry), FINAL-PLAN 5.
 *
 * A type gives the keys it compares (DuplicateKeys) and says, row by row,
 * which key matched; this class does the rest: same business only, the record
 * being edited never counts, the sentence names what matched ("the phone
 * 01712-345678"), and the warning's ack binds to exactly those keys, so
 * changing the phone asks again.
 *
 * ⚠ Rows are compared in PHP after one narrow select, because a name key
 * (case, spaces, Bangla marks) cannot be written as portable SQL. A list of a
 * few thousand rows is read once per save; that is the price of matching
 * "Rahim  Traders" with "rahim traders".
 */
abstract class ListDuplicateRule implements DuplicateRule
{
    /** @return array<string, string|null> the keys this type compares, from what was typed */
    abstract protected function keysOf(array $payload): array;

    /** The same business's rows, narrowed to the columns the keys need. */
    abstract protected function rows(int $businessId): Builder;

    /** @return array<string, string|null> the same keys, for a stored row */
    abstract protected function rowKeys(Model $row): array;

    /** "A customer with the phone 01712-345678 already exists: Rahim Traders". */
    abstract protected function describe(Model $row, string $matched): string;

    abstract protected function numberOf(Model $row): string;

    abstract protected function urlOf(Model $row): ?string;

    abstract protected function visibleTo(User $viewer): bool;

    public function fingerprintOf(array $payload, int $businessId): array
    {
        return ['business' => $businessId] + $this->keysOf($payload);
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $keys = array_filter($this->keysOf($payload), fn ($key) => $key !== null && $key !== '');

        if ($keys === []) {
            return [];
        }

        $visible = $this->visibleTo($viewer);

        return $this->rows($businessId)
            ->orderBy('id')
            ->get()
            ->reject(fn (Model $row) => in_array($row->getMorphClass().':'.$row->getKey(), $exclude, true))
            ->map(function (Model $row) use ($keys, $visible) {
                $theirs = $this->rowKeys($row);
                $matched = null;

                foreach ($keys as $name => $key) {
                    if (($theirs[$name] ?? null) === $key) {
                        $matched = $name;
                        break;
                    }
                }

                if ($matched === null) {
                    return null;
                }

                return new DuplicateCandidate(
                    type: $row->getMorphClass(),
                    id: (int) $row->getKey(),
                    number: $this->numberOf($row),
                    summary: $this->describe($row, $matched),
                    savedBy: $this->savedBy($row),
                    savedAgo: $row->created_at?->diffForHumans() ?? '',
                    status: ($row->is_active ?? true) ? 'Active' : 'Inactive',
                    visibleToUser: $visible,
                    url: $this->urlOf($row),
                );
            })
            ->filter()
            ->take(5)
            ->values()
            ->all();
    }

    protected function savedBy(Model $row): string
    {
        return '';
    }
}
