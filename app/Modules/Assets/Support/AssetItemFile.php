<?php

namespace App\Modules\Assets\Support;

use App\Modules\Foundation\Support\CsvSafe;
use App\Modules\Inventory\Support\CatalogueText;
use RuntimeException;

/**
 * Reads the things sheet (asset register bulk import, 13 Oct 2026): one row per
 * thing, or per batch of identical tagged things, in the template's columns.
 *
 * ⚠ Strict about columns for the catalogue importer's reason: a column headed
 * `serial` instead of `serial_number` would read as a file of blanks and
 * register every laptop without its serial, silently.
 */
class AssetItemFile
{
    public const MANDATORY = ['category', 'name'];

    public const OPTIONAL = [
        'tag', 'quantity', 'brand', 'model', 'serial_number', 'specification', 'place', 'area', 'status', 'condition',
        'part_of', 'acquired_on', 'supplier', 'purchase_price', 'warranty_ends_on', 'holder', 'outside_party', 'note',
    ];

    /** The template's own order; the export writes it and the import reads it. */
    public const TEMPLATE = [
        'tag', 'category', 'name', 'quantity', 'brand', 'model', 'serial_number', 'specification', 'place', 'area', 'status',
        'condition', 'part_of', 'acquired_on', 'supplier', 'purchase_price', 'warranty_ends_on', 'holder', 'outside_party', 'note',
    ];

    /**
     * The rows the template download carries, so the person filling it sees one
     * of each case: a tagged thing with a holder, a part, a batch of ten, a
     * counted group, a thing with an outside party.
     *
     * @var list<list<string>>
     */
    public const EXAMPLES = [
        ['', 'IT', 'Laptop', '1', 'HP', 'ProBook 450 G9', '5CD2345XYZ', 'i5 12th gen, 16 GB, 512 GB SSD', 'Head office', 'Desk 3', 'in_use', 'good', '', '2025-03-10', '', '85000', '2027-03-10', 'BDG-000016', '', "Rakibul's work laptop"],
        ['', 'IT', 'Laptop charger', '1', 'HP', '65W', '', '', 'Head office', 'Desk 3', 'in_use', 'good', 'BDG-IT-0001', '2025-03-10', '', '', '', 'BDG-000016', '', "Part of the laptop above (put the laptop's tag in part_of after the first import)"],
        ['', 'FURN', 'Office chair', '10', '', '', '', 'Mesh, black', 'Head office', 'Main floor', 'in_store', 'good', '', '2024-01-15', '', '4500', '', '', '', '10 rows become 10 tags'],
        ['', 'SML', 'Scissors', '4', '', '', '', '', 'Head office', 'Store cupboard', 'in_store', 'good', '', '', '', '', '', '', '', 'Counted group: 4 pieces under one tag'],
        ['', 'CAM', 'DSLR camera', '1', 'Canon', 'EOS 250D', '', '', 'Head office', 'Studio', 'with_outside_party', 'good', '', '2024-06-01', '', '', '', '', 'Shuvo Photography', 'Lent to the freelancer for the shoot'],
    ];

    /**
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read the things file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the things file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new RuntimeException('The things file is empty.');
            }

            $clean = [];

            foreach ($header as $index => $name) {
                $name = (string) $name;

                // ⚠⚠ Excel writes a UTF-8 BOM in front of the first column.
                if ($index === 0) {
                    $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
                }

                $clean[] = strtolower(CatalogueText::clean($name));
            }

            $header = $clean;
            $missing = array_diff(self::MANDATORY, $header);

            if ($missing !== []) {
                throw new RuntimeException('The file is missing column(s): '.implode(', ', $missing).'. Download the template and fill that in.');
            }

            $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

            if ($duplicates !== []) {
                throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
            }

            $unknown = array_diff($header, self::MANDATORY, self::OPTIONAL);

            if ($unknown !== []) {
                throw new RuntimeException(
                    'The file has unrecognised column(s): '.implode(', ', $unknown).'. Expected only: '.implode(', ', self::TEMPLATE).'.'
                );
            }

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                // ⚠ The export's formula guard (a leading quote before `=`, `+`,
                // `-`, `@`) comes off before the value is cleaned, so an
                // exported file re-imports every name exactly as it was.
                $values = array_map(fn ($value) => CatalogueText::clean(CsvSafe::read((string) $value)), $values);

                // A row of nothing but separators, left under the last real row.
                if (implode('', $values) === '') {
                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new RuntimeException(
                        "Line {$line} has ".count($values).' values but the header declares '.count($header).
                        '. Fix the file rather than guessing which column is which.'
                    );
                }

                $rows[] = ['line' => $line, 'values' => array_combine($header, $values)];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
