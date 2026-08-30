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
 */
export function DocumentLetterhead({ issuer, title, subtitle }: { issuer: Issuer; title: string; subtitle?: string }) {
    return (
        <>
            {issuer.missing.length > 0 && (
                <div className="mb-4 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm print:hidden">
                    This document has no {issuer.missing.join(', ')} on it, because the business has none saved.{' '}
                    <Link href="/settings/business" className="font-medium underline">
                        Settings, Business
                    </Link>{' '}
                    is where they go, and they appear here the moment they are saved.
                </div>
            )}

            <div className="flex flex-wrap items-start justify-between gap-4 border-b border-black/20 pb-4">
                <div className="flex items-start gap-4">
                    {/* ⚠ Bounded, never stretched: a logo uploaded at any size has
                        to sit on a page beside text without pushing it around. */}
                    {issuer.logo_url && <img src={issuer.logo_url} alt="" className="h-16 w-auto max-w-[9rem] object-contain" />}
                    <div>
                        <p className="brand-name text-xl font-semibold">{issuer.legal_name ?? issuer.name}</p>
                        {/* ⚠ Only when it actually says something different. The legal
                            name and the trading name are the same words here, and
                            printing them twice reads as a fault on a document a
                            customer keeps. */}
                        {issuer.legal_name && issuer.legal_name !== issuer.name && <p className="text-sm text-black/60">{issuer.name}</p>}
                        {issuer.address && <p className="mt-1 max-w-xs text-sm whitespace-pre-line text-black/70">{issuer.address}</p>}
                        {/* ⚠ Joined only from what is actually set, so a business
                            with no website does not print a stray separator. */}
                        <p className="text-sm text-black/70">{[issuer.phone, issuer.email, issuer.website].filter(Boolean).join(' · ')}</p>
                    </div>
                </div>
                <div className="text-right">
                    <p className="text-sm font-semibold tracking-widest uppercase">{title}</p>
                    {subtitle && <p className="mt-1 text-sm text-black/60">{subtitle}</p>}
                </div>
            </div>
        </>
    );
}
