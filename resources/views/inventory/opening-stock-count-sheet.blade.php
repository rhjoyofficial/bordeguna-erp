{{--
    The blank sheet somebody carries around the warehouse on 31 August.

    ⚠⚠ Served to the BROWSER for its own print dialog, never to dompdf. Every
    product name carries Bangla, no Bangla font is bundled for dompdf, and a PDF
    would render exactly the names - and nothing else - as empty boxes. Chrome
    uses the system fonts and gets it right.

    ⚠ Nothing on this page is pre-filled with a quantity or a cost, not even a
    zero. Opening stock is written once per warehouse for the life of the
    business and its unit costs become the FIFO layers behind every margin
    reported until that stock sells through. A number the counter did not write
    is a number nobody owns.

    Bilingual because the person holding it counts in Bangla and the person
    typing it back reads the English column headers in the CSV.

    $warehouse        Warehouse - its CODE is what the importer matches on
    $products         Collection<Product> - active, stocked, category then name
    $asAt             string date the count is made as at
    $alreadyRecorded  bool - this warehouse can never receive opening stock again
    $business         string|null
--}}
<!DOCTYPE html>
<html lang="bn">
<head>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=berkshire-swash:400" rel="stylesheet" />
    <meta charset="utf-8">
    <title>Opening stock count sheet - {{ $warehouse->code }} - {{ $asAt }}</title>
    <style>
        @page { size: A4 portrait; margin: 10mm 8mm 12mm 8mm; }

        body {
            font-family: 'Noto Sans Bengali', 'Nirmala UI', 'SolaimanLipi', 'Kalpurush', 'Segoe UI', sans-serif;
            font-size: 10px;
            color: #111827;
            margin: 0;
            padding: 10px;
        }

        .toolbar { margin-bottom: 10px; }

        .toolbar button {
            font: inherit;
            padding: 6px 14px;
            border: 1px solid #374151;
            background: #111827;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        .toolbar a { margin-left: 10px; font-size: 11px; }

        header { border-bottom: 2px solid #111827; padding-bottom: 6px; margin-bottom: 8px; }

        /* The brand wordmark on a sheet that gets printed and signed.
           ⚠ A real fallback stack: this page is often opened in a warehouse,
           and a count sheet that fails to render its own company name is worse
           than one in a plainer face. */
        .business {
            font-size: 13px;
            font-weight: bold;
            font-family: 'Berkshire Swash', 'Segoe Script', cursive;
        }

        h1 { font-size: 15px; margin: 2px 0 4px; }

        .meta { font-size: 10px; }

        .meta span { margin-right: 18px; white-space: nowrap; }

        .code {
            font-size: 15px;
            font-weight: bold;
            border: 2px solid #111827;
            padding: 1px 8px;
            letter-spacing: 1px;
        }

        .danger {
            border: 2px solid #991b1b;
            background: #fef2f2;
            color: #7f1d1d;
            padding: 6px 8px;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 10px;
        }

        .howto {
            border: 1px solid #9ca3af;
            background: #f9fafb;
            padding: 6px 8px;
            margin-bottom: 8px;
            font-size: 9.5px;
        }

        .howto ol { margin: 3px 0 0; padding-left: 16px; }

        .howto li { margin-bottom: 1px; }

        /* ⚠ FIXED layout. Left to itself the browser hands almost the whole
           width to the product name and squeezes the write-in boxes down to a
           few millimetres - unusable for a person filling them in by hand. */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }

        thead { display: table-header-group; }

        thead th {
            background: #e5e7eb;
            border: 1px solid #6b7280;
            padding: 4px 4px;
            font-size: 9px;
            text-align: left;
            vertical-align: bottom;
        }

        tbody td {
            border: 1px solid #9ca3af;
            padding: 5px 4px;
            font-size: 9px;
            vertical-align: middle;
            /* Long Bangla names wrap rather than push the write-in boxes off. */
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* Tall enough to write a number into with a pen. */
        tbody td.write { height: 26px; }

        tbody tr { page-break-inside: avoid; }

        .cat td {
            background: #f3f4f6;
            font-weight: bold;
            font-size: 9.5px;
            padding: 3px 4px;
        }

        /* Percentages, not pixels: the counter's half of the sheet has to
           survive both A4 and whatever width the screen happens to be. */
        .num { width: 3%; text-align: right; color: #6b7280; }
        .sku { width: 15%; font-family: 'Consolas', 'Courier New', monospace; font-size: 8.5px; }
        .unit { width: 4%; text-align: center; }
        .write { background: #fff; }
        .qty { width: 11%; }
        .batch { width: 9%; }
        .exp { width: 9%; }
        .cost { width: 11%; }
        .src { width: 10%; }

        .sign { margin-top: 18px; page-break-inside: avoid; }

        .sign table { width: 100%; }

        .sign td {
            border: none;
            padding-top: 26px;
            font-size: 9.5px;
            width: 33%;
        }

        .sign .line { border-top: 1px solid #111827; padding-top: 3px; }

        footer { margin-top: 10px; font-size: 8.5px; color: #6b7280; }

        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button onclick="window.print()">Print / প্রিন্ট করুন</button>
    <a href="{{ route('inventory.opening-stock.count-sheet', ['warehouse' => $warehouse->id, 'as_at' => $asAt, 'format' => 'csv']) }}">
        Download the CSV to type the counted numbers back into
    </a>
</div>

<header>
    <div class="business">{{ $business ?? '' }}</div>
    <h1>Opening stock count sheet / ওপেনিং স্টক গণনা শীট</h1>
    <div class="meta">
        <span class="code">{{ $warehouse->code }}</span>
        <span><strong>{{ $warehouse->name }}</strong></span>
        <span>Count as at / গণনার তারিখ: <strong>{{ $asAt }}</strong></span>
        <span>Products listed / পণ্য সংখ্যা: <strong>{{ $products->count() }}</strong></span>
        <span>Printed: {{ now()->format('Y-m-d H:i') }}</span>
    </div>
</header>

@if ($alreadyRecorded)
    <div class="danger">
        ⚠ Opening stock has ALREADY been recorded for {{ $warehouse->name }}. It is written once per warehouse, ever.
        Counting again will be refused at import; a correction goes through an inventory adjustment instead.<br>
        এই গুদামের ওপেনিং স্টক আগেই রেকর্ড হয়ে গেছে। এটি প্রতি গুদামে একবারই লেখা যায়।
    </div>
@endif

<div class="howto">
    <strong>How to use this sheet / এই শীট যেভাবে ব্যবহার করবেন</strong>
    <ol>
        <li>Write only what you physically counted. Leave a row blank if the product is not in this warehouse. যা সত্যিই গুনেছেন কেবল তাই লিখুন; না থাকলে ঘরটি খালি রাখুন।</li>
        <li>Quantity is in the unit printed on the row. পরিমাণ অবশ্যই সারিতে লেখা এককেই লিখতে হবে।</li>
        <li>Unit cost is the real buying cost of one unit, excluding VAT. Write where it came from in Cost source, for example a purchase invoice number. একক ক্রয়মূল্য এবং সেটি কোথা থেকে পেলেন তা লিখুন।</li>
        <li>Do not estimate. An unknown cost is left blank and asked about, never guessed. অনুমান করবেন না; না জানলে খালি রাখুন।</li>
        <li>Typing back: open the CSV, fill <em>quantity</em> and <em>unit_cost</em>, and <strong>clear every cell of any row you did not count</strong>. Do not add or rename columns; an unrecognised column is rejected.</li>
    </ol>
</div>

<table>
    <thead>
    <tr>
        <th class="num">#</th>
        <th class="sku">SKU</th>
        <th>Product / পণ্য</th>
        <th class="unit">Unit</th>
        <th class="qty">Counted qty<br>গণনা</th>
        <th class="batch">Batch</th>
        <th class="exp">Expiry</th>
        <th class="cost">Unit cost<br>একক মূল্য</th>
        <th class="src">Cost source</th>
    </tr>
    </thead>
    <tbody>
    @php $lastCategory = '__none__'; $row = 0; @endphp
    @forelse ($products as $product)
        @php $category = $product->category?->name ?? 'Uncategorised'; @endphp
        @if ($category !== $lastCategory)
            <tr class="cat"><td colspan="9">{{ $category }}</td></tr>
            @php $lastCategory = $category; @endphp
        @endif
        <tr>
            <td class="num">{{ ++$row }}</td>
            <td class="sku">{{ $product->sku }}</td>
            <td>{{ $product->name }}@if ($product->brand)<span style="color:#6b7280"> [{{ $product->brand->code }}]</span>@endif</td>
            {{-- ⚠ The ABBREVIATION. The importer compares this exact string
                 against the product's unit and refuses "Piece" where the
                 system says "pcs". --}}
            <td class="unit">{{ $product->unitOfMeasure?->abbreviation }}</td>
            <td class="write qty"></td>
            <td class="write batch"></td>
            <td class="write exp"></td>
            <td class="write cost"></td>
            <td class="write src"></td>
        </tr>
    @empty
        <tr><td colspan="9" style="text-align:center;padding:14px">
            No active stocked products exist yet, so there is nothing to count.
            Raw material and packaging SKUs have to be created first.
        </td></tr>
    @endforelse
    </tbody>
</table>

<div class="sign">
    <table>
        <tr>
            <td><div class="line">Counted by / গণনাকারী - name, signature, date</div></td>
            <td><div class="line">Checked by / যাচাইকারী - name, signature, date</div></td>
            <td><div class="line">Approved by / অনুমোদনকারী - name, signature, date</div></td>
        </tr>
    </table>
</div>

<footer>
    Warehouse code <strong>{{ $warehouse->code }}</strong> is what the import matches on. Keep this signed sheet:
    it is the evidence behind the opening stock figure, and that figure cannot be entered a second time.
</footer>

</body>
</html>
