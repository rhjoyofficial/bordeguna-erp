import { Head, router } from '@inertiajs/react';

interface LabelItem {
    name: string;
    sku: string;
    /** Null on a batch label whose product has no printable barcode: the label prints without bars. */
    barcode: string | null;
    svg: string | null;
    /** ⭐ Batch labels only (traceability, 17 Oct 2026): the jar's own identity. */
    brand?: string | null;
    net_content?: string | null;
    batch_number?: string | null;
    mfg?: string | null;
    exp?: string | null;
    mrp?: string | null;
}

/** Present when the sheet is a production run's batch labels. */
interface BatchSheet {
    run_number: string;
    back_url: string;
    count: number;
    size: string;
    sizes: { value: string; label: string }[];
}

/**
 * ⚠ A bare page with no application chrome, the same shape as the invoice,
 * challan, money receipt and purchase order prints: the browser's own print
 * dialog IS the download, so what is on screen must already be the sheet.
 *
 * ⚠⚠ Sizes are in MILLIMETRES, not pixels. A label that is "about right" on
 * screen and wrong on paper is worse than no label - it goes on the jar before
 * anybody notices the scanner will not read it.
 */
export default function LabelsPrint({
    labels,
    skipped,
    size,
    batch = null,
}: {
    labels: LabelItem[];
    skipped: string[];
    size: { w: number; h: number; label: string };
    batch?: BatchSheet | null;
}) {
    const reload = (changes: Partial<{ count: number; size: string }>) =>
        batch && router.get(window.location.pathname, { count: batch.count, size: batch.size, ...changes }, { preserveScroll: true });

    return (
        <>
            <Head title={batch ? `Batch labels ${batch.run_number}` : 'Barcode labels'} />
            <style>{`
                @page { margin: 8mm; }
                @media print {
                    .no-print { display: none !important; }
                    html, body { background: #fff; }
                }
                .sheet {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 2mm;
                    align-content: flex-start;
                }
                .lbl {
                    width: ${size.w}mm;
                    height: ${size.h}mm;
                    border: 0.2mm dashed #bbb;
                    padding: 1mm 2mm;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: center;
                    text-align: center;
                    overflow: hidden;
                    break-inside: avoid;
                    page-break-inside: avoid;
                    box-sizing: border-box;
                    color: #000;
                    background: #fff;
                }
                .lbl .nm {
                    font-size: 2.2mm;
                    line-height: 1.15;
                    font-weight: 600;
                    max-height: 5mm;
                    overflow: hidden;
                }
                .lbl .cd { font-size: 2.2mm; font-family: ui-monospace, monospace; letter-spacing: 0.2mm; }
                .lbl .bt { font-size: 2mm; line-height: 1.2; width: 100%; text-align: left; font-variant-numeric: tabular-nums; }
                .lbl .bt b { font-weight: 700; }
            `}</style>

            <div className="p-6 text-black" style={{ background: '#fff', minHeight: '100vh' }}>
                <div className="no-print mb-4 flex flex-wrap items-center gap-3">
                    <button type="button" onClick={() => window.print()} className="rounded bg-black px-3 py-1.5 text-sm text-white">
                        Print
                    </button>
                    <span className="text-sm text-neutral-600">
                        {labels.length} label{labels.length === 1 ? '' : 's'} · {size.label}
                    </span>
                    {batch && (
                        <>
                            <label className="flex items-center gap-1.5 text-sm">
                                Labels
                                <input
                                    type="number"
                                    min={1}
                                    max={2000}
                                    key={batch.count}
                                    defaultValue={batch.count}
                                    onBlur={(e) =>
                                        Number(e.target.value) > 0 &&
                                        Number(e.target.value) !== batch.count &&
                                        reload({ count: Number(e.target.value) })
                                    }
                                    className="w-20 rounded border border-neutral-400 px-2 py-1"
                                />
                            </label>
                            <select
                                aria-label="Label size"
                                value={batch.size}
                                onChange={(e) => reload({ size: e.target.value })}
                                className="rounded border border-neutral-400 px-2 py-1 text-sm"
                            >
                                {batch.sizes.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                            <a href={batch.back_url} className="text-sm underline underline-offset-2">
                                Back to run {batch.run_number}
                            </a>
                        </>
                    )}
                </div>

                {/* ⚠⚠ Named, never silently dropped. Someone who asked for forty
                    labels and got thirty needs to know which ten are missing. */}
                {skipped.length > 0 && (
                    <div className="no-print mb-4 rounded border border-amber-500 bg-amber-50 p-3 text-sm text-black">
                        <p className="font-medium">{batch ? 'Printed without a barcode:' : 'Left off the sheet - no barcode on the product:'}</p>
                        <ul className="mt-1 list-disc pl-5">
                            {skipped.map((s) => (
                                <li key={s}>{s}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {labels.length === 0 ? (
                    <p className="text-sm text-neutral-600">Nothing to print. Go back and set a quantity against a product.</p>
                ) : (
                    <div className="sheet">
                        {labels.map((l, i) => (
                            <div className="lbl" key={`${l.sku}-${i}`}>
                                <div className="nm">
                                    {l.name}
                                    {l.brand ? ` · ${l.brand}` : ''}
                                    {l.net_content ? ` · ${l.net_content}` : ''}
                                </div>
                                {/* ⭐ Batch labels (17 Oct 2026): what ties the jar back to its run. */}
                                {batch && (
                                    <div className="bt">
                                        <div>
                                            <b>Batch</b> {l.batch_number ?? '-'}
                                        </div>
                                        <div>
                                            <b>MFG</b> {l.mfg ?? '-'} <b>EXP</b> {l.exp ?? '-'}
                                        </div>
                                        <div>
                                            <b>MRP</b> {l.mrp ?? '-'}
                                        </div>
                                    </div>
                                )}
                                {/* The encoder builds the SVG server-side; it is our own
                                    markup, not anything a user supplied. */}
                                {l.svg && <div dangerouslySetInnerHTML={{ __html: l.svg }} />}
                                {l.barcode && <div className="cd">{l.barcode}</div>}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
