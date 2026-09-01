{{--
    The one template behind BOTH the PDF and the print-friendly view (RPT-03).

    "Print-friendly" is not a fourth file format — it is this same document
    served to the browser for its own print dialog. Rendering both from one
    template is what stops the printed page and the PDF from disagreeing,
    which they otherwise will, quietly, about exactly the figure someone is
    checking.

    Written as plain tables with simple CSS because dompdf is not a browser:
    flexbox and grid are not laid out. The same lesson the payslip template
    learned in 3f applies here unchanged.

    $dataset  ReportDataset — columns, rows (iterated ONCE), meta, totals
    $print    bool — true when served to a browser, false when drawn by dompdf
--}}
<style>
    @page { margin: 14mm 10mm 16mm 10mm; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9px;
        color: #1f2937;
        margin: 0;
    }

    h1 { font-size: 15px; margin: 0 0 2px; }

    .subtitle { font-size: 10px; color: #4b5563; margin: 0 0 8px; }

    .business { font-size: 11px; font-weight: bold; color: #111827; margin: 0 0 1px; }

    /* The letterhead. Centred because that is where a company's mark belongs on
       a document that leaves the building, and because three signatories at the
       foot expect a masthead at the head. */
    .letterhead { text-align: center; margin: 0 0 6px; }

    /* ⚠ Sized in mm, not px: this block is drawn by dompdf at print scale, and a
       logo measured in screen pixels comes out either postage-stamp or half the
       page depending on the upload. Height is capped and the width left to the
       aspect ratio, so a wide wordmark and a square mark both behave. */
    .letterhead img { max-height: 18mm; max-width: 70mm; }

    .doc-title { text-align: center; }
    .doc-title h1 { margin: 4px 0 2px; }
    .doc-title .subtitle { margin: 0 0 8px; }

    /* ⚠⚠ NORMAL FLOW, NEVER `position: fixed`. The provenance stamp below is
       fixed precisely so it repeats on every page - and that is exactly what
       these three boxes must NOT do. A signature block on page 1 of 9 invites a
       signature on page 1 of 9. In flow, it is emitted after the last row, which
       puts it at the foot of the last page and nowhere else. */
    .signatures {
        width: 100%;
        margin-top: 14mm;
        border-collapse: collapse;
        page-break-inside: avoid;
    }

    .signatures td {
        width: 33.33%;
        vertical-align: bottom;
        padding: 0 6mm;
        border: none;
        text-align: center;
    }

    /* The room a wet signature actually needs. */
    .signatures .space { height: 16mm; }

    .signatures .rule {
        border-top: 1px solid #374151;
        padding-top: 3px;
        font-size: 9px;
        font-weight: bold;
        color: #111827;
    }

    .signatures .who {
        font-size: 7.5px;
        color: #6b7280;
        padding-top: 1px;
    }

    .filters {
        margin: 0 0 10px;
        padding: 5px 7px;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        font-size: 8.5px;
        color: #374151;
    }

    .filters span { margin-right: 14px; white-space: nowrap; }
    .filters strong { color: #111827; }

    table { width: 100%; border-collapse: collapse; }

    thead th {
        background: #eef2f7;
        border: 1px solid #d1d5db;
        padding: 4px 5px;
        font-size: 8.5px;
        text-align: left;
        font-weight: bold;
    }

    tbody td {
        border: 1px solid #e5e7eb;
        padding: 3px 5px;
        font-size: 8.5px;
    }

    tbody tr:nth-child(even) td { background: #fafafa; }

    tfoot td {
        border: 1px solid #d1d5db;
        padding: 4px 5px;
        font-size: 8.5px;
        font-weight: bold;
        background: #eef2f7;
    }

    .right { text-align: right; }
    .center { text-align: center; }
    .left { text-align: left; }

    .empty {
        padding: 18px;
        text-align: center;
        color: #6b7280;
        border: 1px solid #e5e7eb;
        font-size: 9px;
    }

    {{-- Fixed, so dompdf repeats it on every page. A provenance stamp that
         marks only the first page tells you nothing about page 7, which is
         the page that gets photocopied. --}}
    /* A standing statement about how the report must be read (W2 / C16b).
       Bordered rather than muted: this is not a footnote, it is the sentence
       that stops "within 1 BDT" being mistaken for a pass. */
    .report-note {
        margin: 10px 0;
        padding: 8px 10px;
        border: 1px solid #9ca3af;
        border-left: 4px solid #374151;
        font-size: 10px;
        line-height: 1.45;
    }
    .stamp {
        position: fixed;
        bottom: -11mm;
        left: 0;
        width: 100%;
        text-align: center;
        font-size: 7.5px;
        color: #6b7280;
    }

    .unlocked { color: #b45309; font-weight: bold; }

    @media print {
        .no-print { display: none; }
        .stamp { position: fixed; bottom: 0; }
    }

    .no-print {
        margin: 0 0 12px;
        padding: 8px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        font-size: 11px;
    }
</style>

@if ($print)
    <div class="no-print">
        <button onclick="window.print()">Print this report</button>
        &nbsp; This is the print view. It is the same document the PDF export produces.
    </div>
@endif

{{-- ⚠ The logo arrives as a data URI, already inlined by ReportLetterhead.
     dompdf carries no session and could never fetch it from the private disk
     behind an authenticated route, so a src="/..." would print a broken image
     on the PDF while the browser print view looked perfect. --}}
<div class="letterhead">
    @if ($dataset->meta->logo)
        <img src="{{ $dataset->meta->logo }}" alt="">
    @endif

    @if ($dataset->meta->businessName)
        {{-- ⚠ Deliberately NOT set in the brand script. dompdf has no Berkshire
             Swash and would fall back silently, printing the wordmark in the
             wrong face on the one document that goes outside. The logo above
             already carries the wordmark drawn correctly. --}}
        <div class="business">{{ $dataset->meta->businessName }}</div>
    @endif
</div>

<div class="doc-title">
    <h1>{{ $dataset->meta->title }}</h1>

    @if ($dataset->meta->subtitle)
        <p class="subtitle">{{ $dataset->meta->subtitle }}</p>
    @endif
</div>

@if (count($dataset->meta->filters) > 0)
    <div class="filters">
        @foreach ($dataset->meta->filters as $label => $value)
            <span><strong>{{ $label }}:</strong> {{ $value }}</span>
        @endforeach
    </div>
@endif

@php
    // The single pass. $rows is a LazyCollection and may be a database cursor,
    // so it is walked once here and never again — including by the "is it
    // empty?" check below, which is why that is a counter and not ->isEmpty().
    $rendered = 0;
@endphp

<table>
    <thead>
        <tr>
            @foreach ($dataset->columns as $column)
                <th class="{{ $column->type->align() }}">{{ $column->label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($dataset->rows as $row)
            @php $rendered++; @endphp
            <tr>
                @foreach ($dataset->columns as $column)
                    <td class="{{ $column->type->align() }}">{{ \App\Modules\Hrm\Reporting\ReportValue::forDisplay($row[$column->key] ?? null, $column->type) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
    {{-- <tfoot> is written after <tbody> — valid HTML5, and it matters here:
         the sums are accumulated BY the loop above, so the markup must be
         generated after it has run or the totals print as zeros. Keeping it in
         the same table is what keeps the columns aligned; a second table would
         size its columns independently. --}}
    @if ($dataset->hasTotals())
        <tfoot>
            <tr>
                @foreach ($dataset->columns as $index => $column)
                    <td class="{{ $index === 0 ? 'left' : $column->type->align() }}">{{ $index === 0 ? $dataset->totals->label() : \App\Modules\Hrm\Reporting\ReportValue::forDisplay($dataset->totals->get($column->key), $column->type) }}</td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>

@if ($rendered === 0)
    <div class="empty">
        No rows matched these filters. This is an answer, not a failure — the report ran and found nothing.
    </div>
@endif

@if ($dataset->meta->note)
    {{-- Governs how the whole report must be read, so it is printed on every
         copy rather than living in a docblock the signatories never see. --}}
    <div class="report-note">{{ $dataset->meta->note }}</div>
@endif

{{-- Three signatories in one row, at the foot of the LAST page (Joy, 1 Sep
     2026). In normal flow on purpose - see the .signatures comment above for
     why this must not be fixed like the stamp is.

     ⚠ All three are left blank to be signed by hand. The stamp below already
     records who generated the report and when; printing a name above a
     signature line would be the document asserting who approved it before
     anybody had. --}}
<table class="signatures">
    <tr>
        <td><div class="space"></div><div class="rule">Prepared by</div><div class="who">Name &amp; designation</div></td>
        <td><div class="space"></div><div class="rule">Checked by</div><div class="who">Name &amp; designation</div></td>
        <td><div class="space"></div><div class="rule">Approved by</div><div class="who">Name &amp; designation</div></td>
    </tr>
</table>

<div class="stamp">
    {{ $dataset->meta->stamp() }}
    @if ($dataset->meta->lockNote)
        <br><span class="unlocked">{{ $dataset->meta->lockNote }}</span>
    @endif
</div>
