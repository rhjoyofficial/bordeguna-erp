<?php

namespace App\Modules\Hrm\Reporting\Writers;

use App\Modules\Hrm\Reporting\Contracts\ReportWriter;
use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Modules\Hrm\Reporting\ReportDataset;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * The report as a PDF, drawn by dompdf from the shared `hrm.report` template.
 *
 * dompdf came in with 3f under Correction C9a — chosen over mPDF because mPDF
 * is GPL-2.0-only and this ERP is delivered to clients rather than only hosted
 * for them. That reasoning is unchanged here, and reusing the same engine means
 * one PDF stack in the codebase rather than two.
 *
 * **Nothing here is confidential-by-default.** `PayslipPdf` carries SAL-07's
 * watermark, generated-by stamp and password encryption because a payslip is a
 * confidential document about one person. A report is not that: it is an
 * operational document, frequently printed and passed around, and encrypting it
 * would make it useless without protecting anything the access gate
 * (Correction C10d) does not already protect at the route. Reports carry the
 * provenance stamp and nothing more — which is why this writer is a separate
 * class from `PayslipPdf` rather than a mode of it.
 *
 * ⚠ **Landscape by default.** A report with more than about eight columns is
 * unreadable on portrait A4, and most of these have more.
 */
class PdfWriter implements ReportWriter
{
    public function key(): string
    {
        return 'pdf';
    }

    public function label(): string
    {
        return 'PDF';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function isDownload(): bool
    {
        return true;
    }

    public function write(ReportDataset $dataset): string
    {
        $options = new Options;
        // Nothing in a report is remote, and a report must never be able to
        // reach out of the server while rendering. Same rule as `PayslipPdf`.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(View::make('hrm.report', [
            'dataset' => $dataset,
            'print' => false,
        ])->render());

        $dompdf->setPaper('A4', $this->orientation($dataset));
        $dompdf->render();

        $path = tempnam(sys_get_temp_dir(), 'report_').'.pdf';
        file_put_contents($path, (string) $dompdf->output());

        return $path;
    }

    /**
     * Portrait only for genuinely narrow reports. The threshold counts columns
     * rather than measuring width because dompdf will not tell us the width
     * until it is too late to change the paper.
     */
    private function orientation(ReportDataset $dataset): string
    {
        $wide = count(array_filter(
            $dataset->columns,
            fn ($column) => $column->type === ReportColumnType::Text,
        ));

        return count($dataset->columns) <= 5 && $wide <= 3 ? 'portrait' : 'landscape';
    }
}
