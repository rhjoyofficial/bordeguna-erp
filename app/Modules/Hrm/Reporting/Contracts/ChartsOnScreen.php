<?php

namespace App\Modules\Hrm\Reporting\Contracts;

use App\Modules\Hrm\Reporting\ReportParameters;

/**
 * A report that also draws a small chart when it is viewed on screen.
 *
 * Optional, and deliberately thin: the chart is drawn from the SAME rows the
 * on-screen table shows, so it cannot disagree with the numbers beside it. The
 * definition only says which column is the label and which numeric columns are
 * the bars; it never runs a second query for a picture.
 *
 * The files (CSV, XLSX, PDF) carry no chart. They are the exact numbers.
 */
interface ChartsOnScreen
{
    /**
     * @return array{
     *     type: 'bar'|'stacked_bar',
     *     label: string,
     *     series: list<array{key: string, label: string}>,
     *     title?: string,
     *     limit?: int,
     * }|null  null when this run has nothing worth drawing
     */
    public function chart(ReportParameters $parameters): ?array;
}
