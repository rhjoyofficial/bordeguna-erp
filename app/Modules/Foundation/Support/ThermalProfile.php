<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Foundation\Models\PrinterProfile;
use App\Modules\Inventory\Support\Code128;
use Throwable;

/**
 * The paper a counter document is being printed on, resolved for one outlet.
 *
 * ⭐⭐ THIS IS THE STANDARD. Every thermal document in the ERP asks this class
 * what the paper is and lays itself out from the answer. Nothing anywhere names
 * a printer model, and no width, font size or barcode dimension is written as a
 * literal in a component. Swap the machine, edit one row, and every slip is
 * correct - including ones written before that machine existed.
 *
 * ⚠⚠ THE RESOLUTION ORDER IS OUTLET, THEN BUSINESS, THEN BUILT IN, and the
 * answer says which one it came from. A shop that has configured nothing still
 * prints, on the 80mm profile below, because a counter that cannot hand over a
 * receipt until somebody visits a settings screen is a counter that stops
 * selling. The built-in is a floor, not a preference.
 */
final class ThermalProfile
{
    /**
     * The narrowest bar Code 128 can be printed at on thermal paper and still
     * be read by a counter scanner, in millimetres.
     *
     * ⚠ This is a physical limit of the paper and the head, not a style: below
     * it the ink spread between a bar and its neighbouring space closes up the
     * gap the decoder measures. Anything narrower does not fail honestly - it
     * scans from some angles and not others, which costs a customer five
     * minutes at the returns counter and looks like the software's fault.
     */
    private const MIN_MODULE_MM = 0.25;

    /**
     * The quiet zone Code 128 requires each side, as a multiple of the narrow
     * bar. White paper is part of the symbol; without it a scanner never finds
     * the start.
     */
    private const QUIET_ZONE_MODULES = 10;

    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly int $paperWidthMm,
        public readonly int $printWidthMm,
        public readonly int $dpi,
        public readonly float $baseFontPt,
        public readonly float $lineHeight,
        public readonly bool $showLogo,
        public readonly bool $showBarcode,
        public readonly ?string $footerNote,
        /** outlet | business | builtin */
        public readonly string $source,
    ) {}

    /**
     * The profile a shop gets before anybody configures anything: the common
     * 80mm counter printer, which is what Bor de Guna actually bought and what
     * the overwhelming majority of receipt printers sold in Bangladesh are.
     */
    public static function builtin(): self
    {
        return new self(
            id: null,
            name: '80mm receipt printer (built-in default)',
            paperWidthMm: 80,
            printWidthMm: 72,
            dpi: 203,
            baseFontPt: 8.0,
            lineHeight: 1.30,
            showLogo: false,
            showBarcode: true,
            footerNote: null,
            source: 'builtin',
        );
    }

    public static function fromModel(PrinterProfile $profile, string $source): self
    {
        return new self(
            id: $profile->id,
            name: (string) $profile->name,
            paperWidthMm: $profile->paper_width_mm,
            printWidthMm: $profile->print_width_mm,
            dpi: $profile->dpi,
            baseFontPt: $profile->base_font_pt,
            lineHeight: $profile->line_height,
            showLogo: $profile->show_logo,
            showBarcode: $profile->show_barcode,
            footerNote: blank($profile->footer_note) ? null : (string) $profile->footer_note,
            source: $source,
        );
    }

    /**
     * ⚠ Never throws and never returns null. This is called while rendering a
     * document somebody is waiting to hand over; a missing table, a business
     * with no context or a half-applied migration all mean "nobody has chosen",
     * which is the built-in profile.
     */
    public static function resolve(?int $warehouseId = null): self
    {
        try {
            if ($warehouseId !== null) {
                $outlet = PrinterProfile::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('is_active', true)
                    ->first();

                if ($outlet !== null) {
                    return self::fromModel($outlet, 'outlet');
                }
            }

            $default = PrinterProfile::query()
                ->whereNull('warehouse_id')
                ->where('is_active', true)
                ->first();

            if ($default !== null) {
                return self::fromModel($default, 'business');
            }
        } catch (Throwable) {
            // Fall through to the built-in: see the class note.
        }

        return self::builtin();
    }

    public function dotsPerMm(): float
    {
        return $this->dpi / 25.4;
    }

    /**
     * How wide one bar must be, in printer dots, for this paper.
     *
     * ⭐ A WHOLE NUMBER, ALWAYS. The head cannot print 2.16 dots, so a barcode
     * asked for that width comes out with some bars at 2 and some at 3 - a 50%
     * error on the element every other measurement in the symbol is judged
     * against. Rounding UP to the next whole dot at or above the physical
     * minimum is the entire trick, and it is why this is arithmetic rather than
     * a library.
     */
    public function moduleDots(): int
    {
        return max(1, (int) ceil(self::MIN_MODULE_MM * $this->dotsPerMm()));
    }

    public function moduleMm(): float
    {
        return $this->moduleDots() / $this->dotsPerMm();
    }

    /**
     * Everything the paper needs to know about printing one barcode, including
     * the honest answer that it will not fit.
     *
     * ⛔ A BARCODE THAT CANNOT BE SCANNED IS NOT PRINTED. On a 58mm roll a
     * 16-character invoice number needs more millimetres than the head has, and
     * the choices are to squeeze it - producing bars below the physical minimum,
     * which scan sometimes - or to leave it off and say why. It is left off.
     * The number is always printed as text underneath, so the returns screen can
     * still be given it by hand.
     *
     * @return array{value:string, fits:bool, width_mm:float, height_mm:float, module_dots:int, module_mm:float, modules:int, required_mm:float, svg:?string, reason:?string}
     */
    public function barcode(string $value, float $heightMm = 11.0): array
    {
        $modules = array_sum(Code128::widths($value));
        $moduleMm = $this->moduleMm();
        $widthMm = round($modules * $moduleMm, 2);
        $quietMm = self::QUIET_ZONE_MODULES * $moduleMm;
        $requiredMm = round($widthMm + (2 * $quietMm), 2);

        $fits = $this->showBarcode && $requiredMm <= $this->printWidthMm;

        return [
            'value' => $value,
            'fits' => $fits,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'module_dots' => $this->moduleDots(),
            'module_mm' => round($moduleMm, 4),
            'modules' => $modules,
            'required_mm' => $requiredMm,
            'svg' => $fits ? Code128::svg($value, $widthMm, $heightMm) : null,
            'reason' => $fits
                ? null
                : ($this->showBarcode
                    ? sprintf(
                        'This number needs %.1fmm including its quiet zones and the paper prints %dmm, so the barcode is left off rather than printed too narrow to scan.',
                        $requiredMm,
                        $this->printWidthMm,
                    )
                    : 'Barcodes are switched off on this printer profile.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'paper_width_mm' => $this->paperWidthMm,
            'print_width_mm' => $this->printWidthMm,
            'dpi' => $this->dpi,
            'base_font_pt' => $this->baseFontPt,
            'line_height' => $this->lineHeight,
            'show_logo' => $this->showLogo,
            'show_barcode' => $this->showBarcode,
            'footer_note' => $this->footerNote,
            'source' => $this->source,
        ];
    }
}
