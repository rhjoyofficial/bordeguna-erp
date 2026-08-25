import { Link } from '@inertiajs/react';

export interface Issuer {
    name: string;
    legal_name: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
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
                <div>
                    <p className="text-xl font-semibold">{issuer.legal_name ?? issuer.name}</p>
                    {issuer.legal_name && <p className="text-sm text-black/60">{issuer.name}</p>}
                    {issuer.address && <p className="mt-1 max-w-xs text-sm whitespace-pre-line text-black/70">{issuer.address}</p>}
                    <p className="text-sm text-black/70">{[issuer.phone, issuer.email].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="text-right">
                    <p className="text-sm font-semibold tracking-widest uppercase">{title}</p>
                    {subtitle && <p className="mt-1 text-sm text-black/60">{subtitle}</p>}
                </div>
            </div>
        </>
    );
}
