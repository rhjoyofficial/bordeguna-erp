import { type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';

/**
 * Any document, drawn on whatever roll this counter has.
 *
 * ⭐⭐ ONE COMPONENT FOR EVERY THERMAL DOCUMENT. An invoice slip, a money
 * receipt and a delivery slip are the same shape of paper with different words
 * on it. Written once, they cannot disagree about where the total goes or how
 * wide the roll is.
 *
 * ⚠⚠ EVERY DIMENSION IS AN INLINE STYLE COMPUTED FROM THE PROFILE, NOT A CLASS,
 * and there are two separate reasons.
 *
 * The first is that the width and the type size are RUNTIME VALUES. The shop
 * can change its printer in Settings and this paper has to follow, which a
 * compiled utility class cannot do.
 *
 * The second is a defect this replaces. The old slip carried
 * `official-document font-mono text-[11px]` on one element. `.official-document`
 * is unlayered in the built stylesheet and Tailwind's utilities sit inside
 * `@layer utilities` - and in the CSS cascade UNLAYERED BEATS LAYERED whatever
 * the source order. So both utilities were dead: the slip rendered in the serif
 * document face at .95rem, and the TOTAL - which carried its own `text-[13px]`
 * on a child element, where it did win - printed SMALLER than the item lines
 * above it. An inline style beats every class in every layer, so the trap cannot
 * be re-armed here.
 *
 * ⚠ Columns are laid out with flex, never by counting characters into a
 * monospace font. The document face has to be Bangla-capable, Bangla is
 * proportional, and a receipt whose alignment depends on every glyph being the
 * same width is a receipt that breaks the first time somebody sells a product
 * with a Bengali name.
 */

export interface ThermalProfileShape {
    id: number | null;
    name: string;
    paper_width_mm: number;
    print_width_mm: number;
    dpi: number;
    base_font_pt: number;
    line_height: number;
    show_logo: boolean;
    show_barcode: boolean;
    footer_note: string | null;
    source: 'outlet' | 'business' | 'builtin';
}

export interface ThermalBarcode {
    value: string;
    fits: boolean;
    width_mm: number;
    height_mm: number;
    module_dots: number;
    module_mm: number;
    modules: number;
    required_mm: number;
    svg: string | null;
    reason: string | null;
}

export interface ThermalDocumentData {
    title: string;
    number: string;
    meta: { label: string; value: string }[];
    party: { heading: string; name: string; lines: string[] } | null;
    lines: {
        description: string;
        detail: string | null;
        quantity: string;
        unit_price: string;
        discount: string | null;
        net: string;
    }[];
    totals: { label: string; value: string; emphasis: boolean }[];
    payments: { label: string; value: string }[];
    barcode_value: string | null;
    footer_lines: string[];
}

/** The one face that must cover Bangla and Latin together. See FontCatalogue. */
const DOCUMENT_FONT = 'var(--font-document, "Tiro Bangla", "Noto Serif Bengali", Georgia, serif)';

function Rule() {
    return <div style={{ borderTop: '1px dashed #000', margin: '1.5mm 0' }} />;
}

function Row({ left, right, bold, size }: { left: string; right: string; bold?: boolean; size?: string }) {
    return (
        <div
            style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'baseline',
                gap: '2mm',
                fontWeight: bold ? 700 : 400,
                fontSize: size,
            }}
        >
            {/* ⚠ minWidth 0 or a long Bangla product name refuses to wrap and
                pushes the amount off the edge of the roll. */}
            <span style={{ minWidth: 0, overflowWrap: 'anywhere' }}>{left}</span>
            <span style={{ whiteSpace: 'nowrap' }}>{right}</span>
        </div>
    );
}

export function ThermalDocument({
    profile,
    document,
    issuer,
    barcode,
}: {
    profile: ThermalProfileShape;
    document: ThermalDocumentData;
    issuer: Issuer;
    barcode: ThermalBarcode | null;
}) {
    const base = `${profile.base_font_pt}pt`;
    const big = `${(profile.base_font_pt * 1.3).toFixed(1)}pt`;
    const small = `${(profile.base_font_pt * 0.9).toFixed(1)}pt`;

    return (
        <>
            {/*
              ⚠⚠ THIS RULE HAS TO WIN AGAINST app.css, which declares
              `@page { size: A4 portrait; margin: 12mm }` for every document in
              the ERP. Both are unlayered, so document order decides, and a
              <style> the component renders into the body comes after the
              stylesheet in the head. That is the whole mechanism - it is not an
              accident, and moving this into a CSS file would break it silently.
            */}
            <style>{`@media print {
                @page { size: ${profile.paper_width_mm}mm auto; margin: 0; }
                html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
            }`}</style>

            <div
                style={{
                    width: `${profile.print_width_mm}mm`,
                    fontFamily: DOCUMENT_FONT,
                    fontSize: base,
                    lineHeight: profile.line_height,
                    color: '#000',
                    background: '#fff',
                    padding: '2mm 0 4mm',
                }}
            >
                <div style={{ textAlign: 'center' }}>
                    {profile.show_logo && issuer.logo_url && (
                        /* ⚠ Height-capped, not width-capped: a wide logo on a
                           narrow roll must shrink, and a tall one must not eat
                           the top third of the paper. */
                        <img src={issuer.logo_url} alt="" style={{ maxHeight: '12mm', maxWidth: '100%', margin: '0 auto 1mm', display: 'block' }} />
                    )}
                    <div style={{ fontSize: big, fontWeight: 700 }}>{issuer.name}</div>
                    {issuer.address && <div style={{ whiteSpace: 'pre-line', fontSize: small }}>{issuer.address}</div>}
                    {issuer.phone && <div style={{ fontSize: small }}>{issuer.phone}</div>}
                </div>

                <Rule />

                <div style={{ textAlign: 'center', fontWeight: 700 }}>{document.title}</div>
                <Row left={document.number} right="" />

                {document.meta.map((entry) => (
                    <Row key={entry.label} left={entry.label} right={entry.value} />
                ))}

                {document.party && (
                    <>
                        <Rule />
                        <div style={{ fontWeight: 700 }}>{document.party.heading}</div>
                        <div style={{ overflowWrap: 'anywhere' }}>{document.party.name}</div>
                        {document.party.lines.map((line, index) => (
                            <div key={index} style={{ fontSize: small, overflowWrap: 'anywhere' }}>
                                {line}
                            </div>
                        ))}
                    </>
                )}

                {document.lines.length > 0 && (
                    <>
                        <Rule />
                        {document.lines.map((line, index) => (
                            <div key={index} style={{ marginBottom: '1mm' }}>
                                <div style={{ overflowWrap: 'anywhere' }}>{line.description}</div>
                                {line.detail && <div style={{ fontSize: small }}>{line.detail}</div>}
                                <Row
                                    left={`${line.quantity} x ${line.unit_price}${line.discount ? ` less ${line.discount}` : ''}`}
                                    right={line.net}
                                />
                            </div>
                        ))}
                    </>
                )}

                {document.totals.length > 0 && <Rule />}
                {document.totals.map((total) => (
                    <Row key={total.label} left={total.label} right={total.value} bold={total.emphasis} size={total.emphasis ? big : undefined} />
                ))}

                {document.payments.length > 0 && (
                    <>
                        <Rule />
                        {document.payments.map((payment, index) => (
                            <Row key={index} left={payment.label} right={payment.value} />
                        ))}
                    </>
                )}

                {/*
                  ⛔ A BARCODE THAT CANNOT BE SCANNED IS NOT PRINTED. The server
                  works out whether this number fits the roll at a bar width the
                  head can actually lay down; when it does not, the paper simply
                  carries the number as text. Squeezing it would produce a symbol
                  that scans from some angles and not others, which is worse than
                  none: the counter would trust it.
                */}
                {barcode && (
                    <>
                        <Rule />
                        {barcode.fits && barcode.svg && (
                            <div
                                style={{ display: 'flex', justifyContent: 'center', padding: '1mm 0' }}
                                dangerouslySetInnerHTML={{ __html: barcode.svg }}
                            />
                        )}
                        <div style={{ textAlign: 'center' }}>{barcode.value}</div>
                        {!barcode.fits && barcode.reason && (
                            /* On screen only. The customer holding the paper does
                               not need to read why a barcode is absent; the
                               person who can widen the roll does. */
                            <div className="print:hidden" style={{ fontSize: small, textAlign: 'center', marginTop: '1mm' }}>
                                {barcode.reason}
                            </div>
                        )}
                    </>
                )}

                {(document.footer_lines.length > 0 || profile.footer_note) && <Rule />}
                {document.footer_lines.map((line, index) => (
                    <div key={index} style={{ textAlign: 'center' }}>
                        {line}
                    </div>
                ))}
                {profile.footer_note && <div style={{ textAlign: 'center', whiteSpace: 'pre-line' }}>{profile.footer_note}</div>}
            </div>
        </>
    );
}

/**
 * The controls above the paper, and the one place that says which profile drew
 * it. ⚠ `builtin` is called out on purpose: a shop printing on the fallback has
 * not described its own machine yet, and the day it buys a 58mm one that fact
 * is the difference between a five-second fix and an afternoon.
 */
export function ThermalControls({ profile, children }: { profile: ThermalProfileShape; children?: React.ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-2 print:hidden">
            {children}
            <Button size="sm" onClick={() => window.print()}>
                Print to thermal printer
            </Button>
            <span className="text-muted-foreground text-xs">
                {profile.paper_width_mm}mm paper · {profile.print_width_mm}mm printed · {profile.dpi} dpi ·{' '}
                {profile.source === 'builtin'
                    ? 'built-in default, no printer described yet'
                    : profile.source === 'outlet'
                      ? `outlet profile: ${profile.name}`
                      : `business default: ${profile.name}`}
            </span>
        </div>
    );
}
