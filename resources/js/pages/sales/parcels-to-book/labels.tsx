import { Head } from '@inertiajs/react';

interface ParcelLabel {
    id: number;
    number: string;
    qr: string;
    barcode: string | null;
    courier: string | null;
    recipient: string | null;
    phone: string | null;
    address: string | null;
    cod: string;
    brand: string | null;
    order_number: string | null;
    shop_reference: string | null;
    challan: string | null;
    weight_grams: number | null;
    contents: string | null;
}

/**
 * Parcel labels on A4 (bulk booking, 11 Oct 2026): 2 or 4 to a page, cut along
 * the dashed lines.
 *
 * ⚠ A bare page with no application chrome, like every printed document here:
 * the browser's print dialog IS the download. The QR code and the Code 128
 * barcode are drawn on the server by the encoders the asset tags and product
 * labels already use; both carry the courier's consignment number only.
 */
export default function ParcelLabels({ labels, per_page, sender }: { labels: ParcelLabel[]; per_page: number; sender: string | null }) {
    const height = per_page === 2 ? '138mm' : '68mm';

    return (
        <>
            <Head title="Parcel labels" />
            <style>{`
                @page { size: A4; margin: 8mm; }
                @media print {
                    .no-print { display: none !important; }
                    html, body { background: #fff; }
                }
                .sheet { display: grid; grid-template-columns: ${per_page === 2 ? '1fr' : '1fr 1fr'}; gap: 3mm; width: 194mm; }
                .label {
                    height: ${height};
                    border: 0.3mm dashed #999;
                    padding: 3mm;
                    box-sizing: border-box;
                    display: flex;
                    flex-direction: column;
                    gap: 1.5mm;
                    overflow: hidden;
                    break-inside: avoid;
                    page-break-inside: avoid;
                    color: #000;
                    background: #fff;
                    font-size: ${per_page === 2 ? '3.6mm' : '2.9mm'};
                    line-height: 1.25;
                }
                .label .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 2mm; }
                .label .qr { width: ${per_page === 2 ? '30mm' : '20mm'}; height: ${per_page === 2 ? '30mm' : '20mm'}; flex: none; }
                .label .qr svg, .label .bar svg { width: 100%; height: 100%; display: block; }
                .label .bar { height: ${per_page === 2 ? '14mm' : '10mm'}; }
                .label .num { font-family: ui-monospace, monospace; font-weight: 700; font-size: ${per_page === 2 ? '5mm' : '3.8mm'}; letter-spacing: 0.2mm; }
                .label .to { font-weight: 700; font-size: ${per_page === 2 ? '4.4mm' : '3.4mm'}; }
                .label .cod { font-weight: 700; font-size: ${per_page === 2 ? '5mm' : '3.8mm'}; border: 0.3mm solid #000; padding: 0.5mm 1.5mm; }
                .label .small { font-size: ${per_page === 2 ? '3mm' : '2.4mm'}; color: #333; }
                .label .row { display: flex; justify-content: space-between; align-items: center; gap: 2mm; }
            `}</style>

            <div className="p-6 text-black" style={{ background: '#fff', minHeight: '100vh' }}>
                <div className="no-print mb-4 flex flex-wrap items-center gap-3">
                    <button type="button" onClick={() => window.print()} className="rounded bg-black px-3 py-1.5 text-sm text-white">
                        Print
                    </button>
                    <span className="text-sm text-neutral-600">
                        {labels.length} label{labels.length === 1 ? '' : 's'}, {per_page} per A4 page
                    </span>
                </div>

                {labels.length === 0 ? (
                    <p className="text-sm text-neutral-600">No booked parcels to print.</p>
                ) : (
                    <div className="sheet">
                        {labels.map((label) => (
                            <div className="label" key={label.id}>
                                <div className="top">
                                    <div style={{ minWidth: 0 }}>
                                        <div className="small">
                                            {label.courier ?? 'Courier'}
                                            {label.brand ? ` · ${label.brand}` : ''}
                                        </div>
                                        <div className="num">{label.number}</div>
                                        <div className="small">
                                            {label.order_number}
                                            {label.shop_reference ? ` · ${label.shop_reference}` : ''}
                                            {label.challan ? ` · ${label.challan}` : ''}
                                        </div>
                                    </div>
                                    {/* Our own encoder's markup, built on the server from the consignment number. */}
                                    <div className="qr" dangerouslySetInnerHTML={{ __html: label.qr }} />
                                </div>
                                {label.barcode && <div className="bar" dangerouslySetInnerHTML={{ __html: label.barcode }} />}
                                <div>
                                    <div className="small">To</div>
                                    <div className="to">{label.recipient}</div>
                                    <div className="to">{label.phone}</div>
                                    <div>{label.address}</div>
                                </div>
                                <div className="row" style={{ marginTop: 'auto' }}>
                                    <span className="cod">COD {label.cod}</span>
                                    <span className="small">{label.weight_grams !== null ? `${label.weight_grams} g` : ''}</span>
                                </div>
                                {label.contents && <div className="small">{label.contents}</div>}
                                {sender && <div className="small">From {sender}</div>}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
