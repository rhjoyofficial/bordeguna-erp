<?php

namespace App\Modules\Sales\Couriers\Drivers;

/**
 * One coverage list as a courier returned it, shaped the same for every
 * courier: id (always a string), name, and whatever else the courier says
 * about the entry. `error` set means the call failed and `items` is empty,
 * which a sync must never read as "the courier dropped everything".
 */
final class LocationList
{
    /**
     * @param  list<array{id:string,name:string,extra:array<string,mixed>}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self([], $error);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * Rows from any list the courier sent, keeping only the ones with an id and
     * a name. Everything else on the row lands in `extra`.
     *
     * @param  array<mixed>  $rows
     */
    public static function from(array $rows, string $idKey, string $nameKey): self
    {
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$idKey]) || ! isset($row[$nameKey])) {
                continue;
            }

            $extra = $row;
            unset($extra[$idKey], $extra[$nameKey]);

            $items[] = [
                'id' => (string) $row[$idKey],
                'name' => trim((string) $row[$nameKey]),
                'extra' => $extra,
            ];
        }

        return new self($items);
    }
}
