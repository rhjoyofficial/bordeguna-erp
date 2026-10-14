<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\Contact;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * The phone book as an Excel workbook (Joy, 14 Oct 2026: "needs excel template
 * so can easily import using the template also phonebook export data").
 *
 * ⚠⚠ THE SAME SHAPE AS THE CSV, from ContactCsv::columns(), and the same reading
 * rules through ContactCsv::rowsFrom(). Excel is a second way to carry the file,
 * never a second definition of what a valid file is.
 *
 * ⚠⚠ THE PHONE COLUMN IS TEXT. Excel turns 01712345678 into the number
 * 1712345678 the moment it is typed into a General cell. The template and the
 * export format the phone column as text (with spare rows below the data), so a
 * number typed into the file keeps its zero. Every cell is written as an
 * explicit string, which also means nothing in an exported name can run as a
 * formula when the file is opened (CRM-007).
 */
class ContactSheet
{
    public const SHEET = 'Phone book';

    /** Empty rows under the data that still keep a typed phone number as text. */
    private const SPARE_ROWS = 2000;

    private const WIDTHS = [
        'name' => 32, 'company' => 24, 'phone' => 16, 'email' => 30,
        'address' => 36, 'source' => 30, 'note' => 40, 'is_active' => 10,
    ];

    /**
     * A blank workbook with the right header.
     *
     * ⚠ HEADER ONLY, no example person: a sample row is a row somebody imports
     * unchanged (the CSV template's rule).
     */
    public static function template(string $path): void
    {
        self::write([], $path);
    }

    /**
     * @param  iterable<Contact>  $contacts
     */
    public static function export(iterable $contacts, string $path): void
    {
        $rows = [];

        foreach ($contacts as $contact) {
            $row = [];

            foreach (ContactCsv::columns() as $column) {
                $row[$column] = match ($column) {
                    // The words the importer accepts, so the file round-trips.
                    'is_active' => $contact->is_active ? 'active' : 'inactive',
                    // ⚠⚠ `phone`, never `phone_normalized`: what the human typed.
                    default => (string) ($contact->{$column} ?? ''),
                };
            }

            $rows[] = $row;
        }

        self::write($rows, $path);
    }

    /**
     * The rows of the "Phone book" sheet (or the first sheet), in the shape the
     * CSV reader returns, line numbers being the sheet's row numbers.
     *
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function read(string $path): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('That file could not be read as an Excel workbook (.xlsx). Save it from Excel as .xlsx, or use the CSV.');
        }

        $sheet = $book->getSheetByName(self::SHEET) ?? $book->getSheet(0);
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $header = self::rowValues($sheet, 1, $lastColumn);

        if ($header === []) {
            throw new RuntimeException('The contact file is empty.');
        }

        $phoneAt = array_search('phone', array_map(fn ($h) => strtolower(trim($h)), $header), true);

        $records = [];

        for ($line = 2; $line <= $lastRow; $line++) {
            $records[$line] = self::rowValues($sheet, $line, $lastColumn, $phoneAt === false ? null : $phoneAt);
        }

        return ContactCsv::rowsFrom($header, $records);
    }

    /**
     * @param  list<array<string,string>>  $rows
     */
    private static function write(array $rows, string $path): void
    {
        $columns = ContactCsv::columns();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        foreach ($columns as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, 1], $column, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(self::WIDTHS[$column] ?? 20);
        }

        $sheet->getStyle([1, 1, count($columns), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $line = 2;

        foreach ($rows as $row) {
            foreach ($columns as $index => $column) {
                $value = $row[$column] ?? '';

                if ($value !== '') {
                    $sheet->setCellValueExplicit([$index + 1, $line], $value, DataType::TYPE_STRING);
                }
            }

            $line++;
        }

        $phone = array_search('phone', $columns, true) + 1;
        $sheet->getStyle([$phone, 2, $phone, $line + self::SPARE_ROWS])
            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $help = $book->createSheet();
        $help->setTitle('How to fill');
        $help->fromArray([['Column', 'Required', 'What to write']]);
        $help->getStyle('A1:C1')->getFont()->setBold(true);

        foreach ($columns as $index => $column) {
            $help->setCellValueExplicit([1, $index + 2], $column, DataType::TYPE_STRING);
            $help->setCellValueExplicit([2, $index + 2], in_array($column, ContactCsv::MANDATORY, true) ? 'yes' : 'no', DataType::TYPE_STRING);
            $help->setCellValueExplicit([3, $index + 2], ContactCsv::DESCRIPTIONS[$column] ?? '', DataType::TYPE_STRING);
        }

        $note = count($columns) + 3;
        $help->setCellValueExplicit([1, $note], 'A phone or an email is needed for each person. A row whose number is already in the book updates that person instead of adding a second one. Do not rename or add columns.', DataType::TYPE_STRING);
        $help->getColumnDimension('A')->setWidth(14);
        $help->getColumnDimension('B')->setWidth(10);
        $help->getColumnDimension('C')->setWidth(70);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
    }

    /**
     * One row as text, trailing empty cells dropped.
     *
     * @return list<string>
     */
    private static function rowValues(Worksheet $sheet, int $line, int $lastColumn, ?int $phoneAt = null): array
    {
        $values = [];

        for ($column = 1; $column <= $lastColumn; $column++) {
            $cell = $sheet->getCell([$column, $line]);
            $raw = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
            $text = self::text($raw);

            // ⚠ A phone typed into a General cell arrives as the NUMBER
            // 1712345678: Excel ate the zero, the person typed it. Giving it
            // back returns what was typed, which is the rule; a text cell is
            // never touched.
            if ($phoneAt !== null && $column - 1 === $phoneAt && (is_int($raw) || is_float($raw))
                && strlen($text) === 10 && str_starts_with($text, '1')) {
                $text = '0'.$text;
            }

            $values[] = $text;
        }

        while ($values !== [] && end($values) === '') {
            array_pop($values);
        }

        return $values;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof RichText => $value->getPlainText(),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            // ⚠ Never "1.712345678E+9": a whole number is written out in full.
            is_float($value) && floor($value) === $value => sprintf('%.0f', $value),
            default => (string) $value,
        };
    }
}
