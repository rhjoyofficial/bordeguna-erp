import { Link } from '@inertiajs/react';

export interface Issuer {
    name: string;
    legal_name: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    logo_url: string | null;
    missing: string[];
}

/**
 * The top of a document that leaves the building.
 *
 * ⚠⚠ Nothing here is invented. A plausible default address printed on a paper
 * a customer keeps is worse than a visible gap, so only what the business has
 * actually set is shown.
 *
 * ⚠ The warning about missing details is `print:hidden`. The person who can fix
 * it needs to see it; the customer holding the paper does not need to read that
 * we have not filled our own settings in.
 *
 * ⭐ LAID OUT ON THREE COLUMNS (Joy, 7 Sep 2026): the offices flank a centred
 * logo and name, and the document title sits on one line with its number
 * underneath the rule. The old stacked arrangement pushed every document's
 * first real content a long way down the page for no gain - this says the same
 * things in roughly half the height, which is the whole point of the change.
 *
 * ⚠ ONE HEADER FOR ELEVEN DOCUMENTS. Invoice, delivery, quotation, receipt,
 * purchase order, pick list, both vouchers, other income, HR letters and the
 * settlement sheet all render this. That is deliberate: a letterhead that
 * differs between two papers from the same company reads as a forgery. Change
 * it here or not at all.
 */
export function DocumentLetterhead({ issuer, title, subtitle }: { issuer: Issuer; title: string; subtitle?: string }) {
    /*
     * ⚠ The business keeps its addresses as ONE free-text field, and Bor de Guna
     * writes two lines into it: a head office and a corporate office. Splitting
     * on the line break puts one either side of the name, which is how the
     * office wanted it and how a letterhead normally reads.
     *
     * ⛔ Degrades on purpose. One line means one centred block and no side
     * columns; three or more means the extras stay with the right-hand block
     * rather than being dropped. Nothing about a business's own address is ever
     * silently discarded because it did not match a shape.
     */
    const blocks = (issuer.address ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

    const leftAddress = blocks.length > 1 ? blocks[0] : null;
    const rightAddress = blocks.length > 1 ? blocks.slice(1).join('\n') : null;
    const centreAddress = blocks.length === 1 ? blocks[0] : null;

    const contact = [issuer.phone, issuer.email, issuer.website].filter(Boolean).join(' · ');

    return (
        <>
            {issuer.missing.length > 0 && (
                <div className="mb-3 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm print:hidden">
                    This document has no {issuer.missing.join(', ')} on it, because the business has none saved.{' '}
                    <Link href="/settings/business" className="font-medium underline">
                        Settings, Business
                    </Link>{' '}
                    is where they go, and they appear here the moment they are saved.
                </div>
            )}

            <div className="border-b border-black/25 pb-2">
                <div className="flex items-center gap-4">
                    {/* ⚠ The offices get a FIXED share and the centre takes the rest.
                        Equal thirds looked right and read badly: the name and contact
                        line wrapped while the addresses had room to spare. */}
                    <div className="hidden basis-[26%] text-[10px] leading-snug whitespace-pre-line text-black/65 sm:block">
                        {leftAddress}
                    </div>

                    <div className="flex-1 text-center">
                        {/* ⚠ Bounded, never stretched: a logo uploaded at any size has
                            to sit on a page without pushing the text around. */}
                        {issuer.logo_url && <img src={issuer.logo_url} alt="" className="mx-auto h-12 w-auto max-w-[7rem] object-contain" />}
                        <p className="brand-name mt-1 text-lg leading-tight font-semibold whitespace-nowrap">{issuer.legal_name ?? issuer.name}</p>
                        {/* ⚠ Only when it actually says something different. The legal
                            name and the trading name are the same words here, and
                            printing them twice reads as a fault on a document a
                            customer keeps. */}
                        {issuer.legal_name && issuer.legal_name !== issuer.name && (
                            <p className="text-[11px] text-black/60">{issuer.name}</p>
                        )}
                        {centreAddress && <p className="mt-0.5 text-[10px] whitespace-pre-line text-black/65">{centreAddress}</p>}
                        {/* Joined only from what is set, so a business with no website
                            does not print a stray separator. */}
                        {contact && <p className="mt-0.5 text-[10px] text-black/65">{contact}</p>}
                    </div>

                    <div className="hidden basis-[26%] text-right text-[10px] leading-snug whitespace-pre-line text-black/65 sm:block">
                        {rightAddress}
                    </div>
                </div>

                {/* On a narrow screen the side columns are hidden rather than squashed;
                    the addresses still print, stacked, so nothing is lost. */}
                {(leftAddress || rightAddress) && (
                    <div className="mt-2 space-y-0.5 text-[10px] leading-snug whitespace-pre-line text-black/65 sm:hidden">
                        <p>{leftAddress}</p>
                        <p>{rightAddress}</p>
                    </div>
                )}
            </div>

            {/* ⭐ Title and number on ONE line. Two stacked lines in a corner cost
                three times the height and said no more. */}
            <div className="flex flex-wrap items-baseline justify-center gap-x-2 border-b border-black/15 py-1.5 text-center">
                <span className="text-sm font-semibold tracking-widest uppercase">{title}</span>
                {subtitle && <span className="text-sm text-black/60">· {subtitle}</span>}
            </div>
        </>
    );
}
