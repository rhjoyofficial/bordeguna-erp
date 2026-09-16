import { Head } from '@inertiajs/react';

interface TagLabel {
    tag: string;
    name: string;
    svg: string;
}

/**
 * QR tag stickers (asset management slice A1).
 *
 * ⚠ A bare page with no application chrome, like every printed document here:
 * the browser's print dialog IS the download, so what is on screen must already
 * be the sheet.
 *
 * ⚠⚠ Sizes are in MILLIMETRES. The QR code keeps a white quiet zone and never
 * shrinks below the size set per sticker: a code a phone cannot read is a
 * sticker nobody can use.
 */
export default function AssetLabelsPrint({
    labels,
    size,
    business,
}: {
    labels: TagLabel[];
    size: { w: number; h: number; qr: number; label: string };
    business: string | null;
}) {
    return (
        <>
            <Head title="Asset tags" />
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
                .tag {
                    width: ${size.w}mm;
                    height: ${size.h}mm;
                    border: 0.2mm dashed #bbb;
                    padding: 1mm;
                    display: flex;
                    align-items: center;
                    gap: 1.5mm;
                    overflow: hidden;
                    break-inside: avoid;
                    page-break-inside: avoid;
                    box-sizing: border-box;
                    color: #000;
                    background: #fff;
                }
                .tag .qr { width: ${size.qr}mm; height: ${size.qr}mm; flex: none; }
                .tag .qr svg { width: 100%; height: 100%; display: block; }
                .tag .tx { min-width: 0; display: flex; flex-direction: column; gap: 0.6mm; }
                .tag .biz { font-size: 1.8mm; line-height: 1.1; text-transform: uppercase; letter-spacing: 0.1mm; }
                .tag .nm { font-size: 2.2mm; line-height: 1.15; font-weight: 600; max-height: 7.5mm; overflow: hidden; word-break: break-word; }
                .tag .cd { font-size: 2.4mm; font-family: ui-monospace, monospace; font-weight: 700; letter-spacing: 0.1mm; word-break: break-all; }
            `}</style>

            <div className="p-6 text-black" style={{ background: '#fff', minHeight: '100vh' }}>
                <div className="no-print mb-4 flex flex-wrap items-center gap-3">
                    <button type="button" onClick={() => window.print()} className="rounded bg-black px-3 py-1.5 text-sm text-white">
                        Print
                    </button>
                    <span className="text-sm text-neutral-600">
                        {labels.length} tag{labels.length === 1 ? '' : 's'} · {size.label}
                    </span>
                </div>

                {labels.length === 0 ? (
                    <p className="text-sm text-neutral-600">Nothing to print. Go back and tick a thing.</p>
                ) : (
                    <div className="sheet">
                        {labels.map((label, i) => (
                            <div className="tag" key={`${label.tag}-${i}`}>
                                {/* Our own encoder's markup, built on the server from the
                                    item's permanent address; nothing a user typed. */}
                                <div className="qr" dangerouslySetInnerHTML={{ __html: label.svg }} />
                                <div className="tx">
                                    {business && <div className="biz">{business}</div>}
                                    <div className="nm">{label.name}</div>
                                    <div className="cd">{label.tag}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
