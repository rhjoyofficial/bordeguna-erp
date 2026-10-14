<?php

namespace App\Modules\Sales\Support;

use DateTimeInterface;
use Normalizer;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

/**
 * The shop order sheet: its columns, its template, and how a file becomes rows
 * (Joy, 11 Oct 2026: shop orders reach the ERP by hand or by import sheet).
 *
 * ⭐ ONE ROW PER ORDER LINE, grouped by `order_ref`. Order-level columns may be
 * repeated on every line of an order or written on its first line only; the
 * planner refuses a later line that disagrees, never picks one quietly.
 *
 * ⚠ Every value is read as TEXT. A phone typed into Excel is a number that has
 * lost its leading zero, and a date is a serial number; both are turned back
 * into what a person meant here, once, so the planner only ever sees strings.
 * Bangla digits are read as Latin digits (LaLaDia's checkout met them in phones).
 *
 * ⚠ The template is written with text cells only, so a formula typed into a
 * name can never run (the HRM writer's rule).
 */
final class ShopOrderSheet
{
    /**
     * column => [level, what it takes]. The order of this list IS the template.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COLUMNS = [
        'order_ref' => ['order', 'Required. The shop order number, for example #LLD-20261010-0001. At most 40 characters. Every line of one order carries the same order_ref.'],
        'order_date' => ['order', 'Required. 2026-10-10 or 10/10/2026 (day first).'],
        'brand' => ['order', 'Brand name or code, for example LaLaDia or LLD, Bionic-Garden or BNC. Required when the business has brands.'],
        'channel' => ['order', 'website, facebook, whatsapp, phone or other. Blank = other.'],
        'customer_name' => ['order', 'Required. Who receives the parcel.'],
        'phone' => ['order', 'Required. A mobile number; written as 01XXXXXXXXX.'],
        'alt_phone' => ['order', 'Optional second number; kept in the order note.'],
        'district' => ['order', 'Required. Dhaka, Chattogram (Chittagong), and so on.'],
        'thana' => ['order', 'Required. A thana of that district.'],
        'area' => ['order', 'Optional. An area the ERP does not know is left out.'],
        'address_line' => ['order', 'Required. House, road, village.'],
        'landmark' => ['order', 'Optional.'],
        'sku' => ['line', 'Required. The product SKU in the ERP.'],
        'quantity' => ['line', 'Required. More than 0.'],
        'unit_price' => ['line', 'Optional. Blank = the price list. A different price is a price change and needs the price-override permission.'],
        'line_discount' => ['line', 'Optional. Taka off this line. Any discount needs the price-override permission, and the whole order is held to the counter discount cap.'],
        'order_discount' => ['order', 'Optional. Taka off the whole order (a coupon). Counted with price cuts and line discounts against the counter discount cap.'],
        'delivery_charge' => ['order', 'Optional. What the customer pays for delivery.'],
        'advance_paid' => ['order', 'Optional. Money already paid.'],
        'advance_method' => ['order', 'bkash, nagad, rocket, upay, bank, card, cash or other. Needed with an advance.'],
        'advance_reference' => ['order', 'Optional. Transaction id.'],
        'payment_method' => ['order', 'cod or prepaid. Blank = cod.'],
        'note' => ['order', 'Optional.'],
        'weight_grams' => ['order', 'Optional parcel weight in grams. Blank = worked out from the products.'],
    ];

    public const REQUIRED_COLUMNS = ['order_ref', 'order_date', 'customer_name', 'phone', 'district', 'thana', 'address_line', 'sku', 'quantity'];

    public const CHANNELS = ['website', 'facebook', 'whatsapp', 'phone', 'other'];

    public const ADVANCE_METHODS = ['bkash', 'nagad', 'rocket', 'upay', 'bank', 'card', 'cash', 'other'];

    public const PAYMENT_METHODS = ['cod', 'prepaid'];

    public const SPREADSHEET_EXTENSIONS = ['xlsx'];

    public const TEXT_EXTENSIONS = ['csv', 'txt'];

    /**
     * @return array{rows: list<array{line: int, values: array<string, string>}>, ignored: list<string>}
     *
     * @throws RuntimeException a sentence for the person who uploaded it
     */
    public static function read(string $path, string $extension): array
    {
        $raw = in_array(strtolower($extension), self::SPREADSHEET_EXTENSIONS, true)
            ? self::readXlsx($path)
            : self::readCsv($path);

        if ($raw === []) {
            throw new RuntimeException('The file is empty.');
        }

        $header = array_map(fn (mixed $heading) => self::headerKey((string) $heading), array_shift($raw));
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));

        if ($missing !== []) {
            throw new RuntimeException('The first row must be the column names. Missing: '.implode(', ', $missing).'. Download the template to see them all.');
        }

        $duplicates = array_values(array_unique(array_diff_assoc(array_filter($header), array_unique(array_filter($header)))));

        if ($duplicates !== []) {
            throw new RuntimeException('These columns appear twice: '.implode(', ', $duplicates).'.');
        }

        $ignored = array_values(array_filter($header, fn (string $h) => $h !== '' && ! array_key_exists($h, self::COLUMNS)));
        $rows = [];

        foreach ($raw as $index => $cells) {
            $values = [];

            foreach ($header as $position => $column) {
                if ($column === '' || ! array_key_exists($column, self::COLUMNS)) {
                    continue;
                }

                $values[$column] = self::clean($cells[$position] ?? '');
            }

            if (implode('', $values) === '') {
                continue;
            }

            $rows[] = ['line' => $index + 2, 'values' => $values + array_fill_keys(array_keys(self::COLUMNS), '')];
        }

        return ['rows' => $rows, 'ignored' => $ignored];
    }

    /** The template as CSV: the header row only, with a BOM so Excel reads Bangla. */
    public static function csvTemplate(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_keys(self::COLUMNS));
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The template as XLSX: an "Orders" sheet with the header (the only sheet
     * read back) and a "Help" sheet saying what each column takes.
     *
     * @param  list<string>  $brands  "Name (CODE)" of the business's brands
     */
    public static function xlsxTemplate(array $brands): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orders_').'.xlsx';
        $bold = (new Style)->withFontBold(true);

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Orders');
        $writer->addRow(new Row(array_map(fn (string $c) => new StringCell($c, $bold), array_keys(self::COLUMNS))));

        $writer->addNewSheetAndMakeItCurrent()->setName('Help');
        $writer->addRow(new Row([new StringCell('column', $bold), new StringCell('one per', $bold), new StringCell('what it takes', $bold)]));

        foreach (self::COLUMNS as $column => [$level, $help]) {
            $writer->addRow(new Row([new StringCell($column), new StringCell($level === 'line' ? 'line' : 'order'), new StringCell($help)]));
        }

        $writer->addRow(new Row([new StringCell('')]));
        $writer->addRow(new Row([new StringCell('brands', $bold), new StringCell(''), new StringCell($brands === [] ? 'None set up' : implode(', ', $brands))]));
        $writer->addRow(new Row([new StringCell('one row per product line; an order with three products is three rows with the same order_ref')]));
        $writer->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** "Order Ref" / "order-ref" / " ORDER_REF " => order_ref */
    public static function headerKey(string $heading): string
    {
        $heading = preg_replace('/^\xEF\xBB\xBF/', '', $heading) ?? $heading;
        $key = strtolower(trim($heading));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
    }

    /** Bangla digits to Latin, NFC, trimmed. */
    public static function clean(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value)) {
            $value = floor($value) === $value && abs($value) < 1e15 ? number_format($value, 0, '.', '') : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        $text = trim((string) $value);
        $text = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return $text;
    }

    /** @return list<list<mixed>> */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        try {
            $first = (string) fgets($handle);
            rewind($handle);
            // A sheet saved by Excel in some locales uses semicolons.
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $rows = [];

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($cells === [null]) {
                    $rows[] = [];

                    continue;
                }

                $rows[] = $cells;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<list<mixed>> the first sheet only */
    private static function readXlsx(string $path): array
    {
        $reader = new XlsxReader;

        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new RuntimeException('That file is not a readable .xlsx workbook. Save it again as .xlsx, or as CSV.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }

                return $rows;
            }

            return [];
        } finally {
            $reader->close();
        }
    }
}
