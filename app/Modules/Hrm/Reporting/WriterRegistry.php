<?php

namespace App\Modules\Hrm\Reporting;

use App\Modules\Hrm\Reporting\Contracts\ReportWriter;
use App\Modules\Hrm\Reporting\Writers\CsvWriter;
use App\Modules\Hrm\Reporting\Writers\PdfWriter;
use App\Modules\Hrm\Reporting\Writers\PrintHtmlWriter;
use App\Modules\Hrm\Reporting\Writers\XlsxWriter;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RPT-03's four ticked outputs, resolved by key.
 *
 * The order here is the order they appear in the format menu, and it is chosen:
 * Excel first because it is what someone asking for a report usually wants to
 * do something *with*, print last because it is the one that is not a file.
 */
class WriterRegistry
{
    /** @var array<string, ReportWriter> */
    private array $writers = [];

    public function __construct()
    {
        foreach ([new XlsxWriter, new CsvWriter, new PdfWriter, new PrintHtmlWriter] as $writer) {
            $this->writers[$writer->key()] = $writer;
        }
    }

    /** @return Collection<string, ReportWriter> */
    public function all(): Collection
    {
        return collect($this->writers);
    }

    public function find(string $key): ?ReportWriter
    {
        return $this->writers[$key] ?? null;
    }

    public function findOrFail(string $key): ReportWriter
    {
        return $this->find($key) ?? throw new NotFoundHttpException('No such report format.');
    }

    /** @return array<int, array{key: string, label: string, download: bool}> */
    public function options(): array
    {
        return $this->all()
            ->map(fn (ReportWriter $w) => ['key' => $w->key(), 'label' => $w->label(), 'download' => $w->isDownload()])
            ->values()
            ->all();
    }
}
