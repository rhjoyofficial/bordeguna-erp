<?php

namespace App\Modules\Hrm\Reporting\Writers;

use App\Modules\Hrm\Reporting\Contracts\ReportWriter;
use App\Modules\Hrm\Reporting\ReportDataset;
use Illuminate\Support\Facades\View;

/**
 * The report as a page the browser prints itself (RPT-03's "print-friendly").
 *
 * The same `hrm.report` template the PDF writer uses, with `$print = true` so
 * it carries a print button and hides it from the printed output. **This is the
 * point of the shared template**: print and PDF cannot drift apart, because
 * there is only one document.
 *
 * ⚠ **`isDownload()` is false and must stay false.** Sending this as an
 * attachment would have the browser save a file rather than display it, and a
 * print view that cannot be printed has no reason to exist. The controller
 * keys off this flag.
 */
class PrintHtmlWriter implements ReportWriter
{
    public function key(): string
    {
        return 'print';
    }

    public function label(): string
    {
        return 'Print view';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function mimeType(): string
    {
        return 'text/html';
    }

    public function isDownload(): bool
    {
        return false;
    }

    public function write(ReportDataset $dataset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report_').'.html';

        file_put_contents($path, View::make('hrm.report', [
            'dataset' => $dataset,
            'print' => true,
        ])->render());

        return $path;
    }
}
