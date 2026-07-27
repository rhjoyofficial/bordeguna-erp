{{--
    The payslip as it is printed, emailed and archived (DIS-03/DIS-04).

    Written as plain tables with inline-ish styles because dompdf is not a
    browser: flexbox and grid are not laid out, and a payslip that renders
    beautifully in Chrome and collapses in the PDF is worse than one built for
    the engine that actually draws it.

    The watermark is `position: fixed`, which dompdf repeats on every page — a
    watermark that only marks page one is not a watermark (SAL-07).
--}}
<style>
    @page { margin: 22mm 14mm 20mm 14mm; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 10px;
        color: #1f2937;
    }

    .watermark {
        position: fixed;
        top: 38%;
        left: 0;
        width: 100%;
        text-align: center;
        transform: rotate(-30deg);
        font-size: 62px;
        font-weight: bold;
        color: #111827;
        opacity: 0.07;
        letter-spacing: 6px;
        z-index: -1;
    }

    .stamp {
        position: fixed;
        bottom: -14mm;
        left: 0;
        width: 100%;
        font-size: 8px;
        color: #6b7280;
        border-top: 0.5px solid #d1d5db;
        padding-top: 4px;
    }

    h1 { font-size: 15px; margin: 0 0 2px 0; }
    h2 { font-size: 10px; margin: 14px 0 5px 0; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }

    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 4px 6px; vertical-align: top; }

    .meta td { padding: 2px 6px 2px 0; }
    .meta .k { color: #6b7280; width: 90px; }

    .lines th {
        text-align: left;
        background: #f3f4f6;
        border-bottom: 0.5px solid #d1d5db;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #4b5563;
    }
    .lines td { border-bottom: 0.5px solid #e5e7eb; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines .formula { color: #6b7280; font-size: 8px; }

    .totals td { border-top: 1px solid #9ca3af; font-weight: bold; }
    .net { background: #f3f4f6; font-size: 12px; }

    .note { color: #4b5563; font-size: 8.5px; }
    .warn { color: #b45309; }
</style>

<div class="watermark">CONFIDENTIAL</div>

<div class="stamp">
    Generated {{ $doc['generated']['at'] }}@if ($doc['generated']['by']) by {{ $doc['generated']['by'] }}@endif.
    Confidential salary document — {{ $doc['employee']['name'] }} ({{ $doc['employee']['code'] }}), {{ $doc['period']['label'] }}.
    @if ($protected) Password protected under SAL-07. @endif
</div>

<h1>{{ $doc['employee']['business'] ?? 'Bor De Guna' }}</h1>
<div style="color:#6b7280;">Payslip for {{ $doc['period']['label'] }}
    ({{ $doc['period']['start'] }} &ndash; {{ $doc['period']['end'] }})@if (($doc['period']['run_version'] ?? 1) > 1), revision {{ $doc['period']['run_version'] }}@endif</div>

<h2>Employee</h2>
<table class="meta">
    <tr>
        <td class="k">Name</td><td>{{ $doc['employee']['name'] }}</td>
        <td class="k">Employee code</td><td>{{ $doc['employee']['code'] }}</td>
    </tr>
    <tr>
        <td class="k">Designation</td><td>{{ $doc['employee']['designation'] ?? '—' }}</td>
        <td class="k">Department</td><td>{{ $doc['employee']['department'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Office</td><td>{{ $doc['employee']['office'] ?? '—' }}</td>
        <td class="k">Section</td><td>{{ $doc['employee']['section'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Joined</td><td>{{ $doc['employee']['joining_date'] ?? '—' }}</td>
        <td class="k">Employment</td><td>{{ $doc['employee']['employment_type'] ?? '—' }}</td>
    </tr>
</table>

@if ($doc['attendance'])
    <h2>Attendance summary</h2>
    <table class="lines">
        <tr>
            <th>Present</th><th>Absent</th><th>Paid leave</th><th>Unpaid leave</th>
            <th>Late occurrences</th><th>Days deducted</th>
        </tr>
        <tr>
            <td>{{ $doc['attendance']['present_days'] }}</td>
            <td>{{ $doc['attendance']['absent_days'] }}</td>
            <td>{{ $doc['attendance']['paid_leave_days'] }}</td>
            <td>{{ $doc['attendance']['unpaid_leave_days'] }}</td>
            <td>{{ $doc['attendance']['late_occurrences'] }}</td>
            <td>{{ $doc['attendance']['deductible_days'] }}</td>
        </tr>
    </table>
@endif

<h2>Earnings</h2>
<table class="lines">
    <tr><th style="width:45%">Component</th><th>How it was worked out</th><th class="num" style="width:18%">Amount</th></tr>
    @forelse ($doc['earnings'] as $line)
        <tr>
            <td>{{ $line['label'] }}</td>
            <td class="formula">{{ $line['formula'] ?? '—' }}</td>
            <td class="num">{{ number_format($line['amount'], 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="3" class="note">No earnings recorded.</td></tr>
    @endforelse
    <tr class="totals">
        <td colspan="2">Total earnings</td>
        <td class="num">{{ number_format($doc['totals']['total_earnings'], 2) }}</td>
    </tr>
</table>

<h2>Deductions</h2>
<table class="lines">
    <tr><th style="width:45%">Component</th><th>How it was worked out</th><th class="num" style="width:18%">Amount</th></tr>
    @forelse ($doc['deductions'] as $line)
        <tr>
            <td>{{ $line['label'] }}</td>
            <td class="formula">{{ $line['formula'] ?? '—' }}</td>
            <td class="num">{{ number_format($line['amount'], 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="3" class="note">No deductions recorded.</td></tr>
    @endforelse
    <tr class="totals">
        <td colspan="2">Total deductions</td>
        <td class="num">{{ number_format($doc['totals']['total_deductions'], 2) }}</td>
    </tr>
</table>

<table class="lines" style="margin-top:10px;">
    <tr class="net">
        <td><strong>Net payable</strong></td>
        <td class="num"><strong>{{ number_format($doc['totals']['net'], 2) }}</strong></td>
    </tr>
</table>

<h2>Payment</h2>
<table class="meta">
    <tr>
        <td class="k">Method</td><td>{{ $doc['payment']['method'] }}</td>
        <td class="k">Status</td><td>{{ $doc['payment']['status'] }}</td>
        <td class="k">Paid</td><td>{{ number_format($doc['payment']['paid_total'], 2) }}</td>
    </tr>
</table>
@if (count($doc['payment']['events']))
    <table class="lines">
        <tr><th>Date</th><th>Event</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>
        @foreach ($doc['payment']['events'] as $event)
            <tr>
                <td>{{ $event['date'] }}</td>
                <td>{{ $event['type'] }}@if ($event['failure_reason']) <span class="warn">— {{ $event['failure_reason'] }}</span>@endif</td>
                <td>{{ $event['method'] }}</td>
                <td>{{ $event['reference'] ?? '—' }}</td>
                <td class="num">{{ number_format($event['amount'], 2) }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if (count($doc['notes']))
    <h2>How this payslip was reached</h2>
    <ul class="note">
        @foreach ($doc['notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>
@endif

@if (count($doc['block_reasons']))
    <h2>Held</h2>
    <ul class="note warn">
        @foreach ($doc['block_reasons'] as $reason)
            <li>{{ $reason }}</li>
        @endforeach
    </ul>
@endif
