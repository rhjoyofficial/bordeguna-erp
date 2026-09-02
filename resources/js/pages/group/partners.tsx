import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The group party book (Module 6, Slice 1). One real-world party, several
 * company rows, linked by a reversible statement of identity. Balances are
 * read through the links and only for companies the viewer belongs to.
 */
interface Member {
    business_id: number;
    business: string;
    partner_id: number;
    name: string;
    code: string | null;
    outstanding?: number;
}

interface LinkRow {
    id: number;
    name: string;
    kind: string;
    members: Member[];
    total_outstanding: number;
}

interface Suggestion {
    phone: string;
    kind: string;
    members: Member[];
}

interface PartnersProps {
    links: LinkRow[];
    suggestions: Suggestion[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Group partners', href: '/group/partners' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

function SuggestionCard({ suggestion }: { suggestion: Suggestion }) {
    const [name, setName] = useState(suggestion.members[0]?.name ?? '');

    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3">
            <p className="text-sm">
                <span className="font-medium">{suggestion.phone}</span>
                <span className="text-muted-foreground"> appears in {suggestion.members.length} companies:</span>
            </p>
            <ul className="text-muted-foreground space-y-0.5 text-sm">
                {suggestion.members.map((member) => (
                    <li key={`${member.business_id}-${member.partner_id}`}>
                        {member.business}: {member.name} ({member.code})
                    </li>
                ))}
            </ul>
            <div className="flex gap-2">
                <Input className="h-8 flex-1" value={name} onChange={(e) => setName(e.target.value)} placeholder="Group name for this party" />
                <Button
                    size="sm"
                    disabled={name.trim() === ''}
                    onClick={() =>
                        router.post(
                            '/group/partners/link',
                            {
                                name: name.trim(),
                                kind: suggestion.kind,
                                members: suggestion.members.map((member) => ({
                                    business_id: member.business_id,
                                    partner_id: member.partner_id,
                                })),
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Link as one party
                </Button>
            </div>
        </div>
    );
}

export default function GroupPartners({ links, suggestions }: PartnersProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Group partners" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Group partners</h1>
                    <p className="text-muted-foreground text-sm">
                        One real-world party, several company rows. Linking is a reversible statement of identity - it never touches a
                        document, and you see only the companies you belong to.
                    </p>
                </div>

                {suggestions.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="font-semibold">Same phone, different companies - probably the same party</h2>
                        <div className="grid gap-3 md:grid-cols-2">
                            {suggestions.map((suggestion) => (
                                <SuggestionCard key={suggestion.phone} suggestion={suggestion} />
                            ))}
                        </div>
                    </div>
                )}

                <div className="space-y-2">
                    <h2 className="font-semibold">Linked parties</h2>
                    {links.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing linked yet.</p>
                    ) : (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {links.map((link) => (
                                <div key={link.id} className="border-border bg-card space-y-2 rounded-lg border p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">{link.name}</p>
                                            <p className="text-muted-foreground text-xs capitalize">{link.kind}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-muted-foreground text-xs">Outstanding across the group</p>
                                            <p className="font-semibold tabular-nums">{money(link.total_outstanding)}</p>
                                        </div>
                                    </div>
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {link.members.map((member) => (
                                                <tr key={`${member.business_id}-${member.partner_id}`} className="border-t">
                                                    <td className="py-1.5 pr-2">{member.business}</td>
                                                    <td className="text-muted-foreground py-1.5 pr-2">
                                                        {member.name} ({member.code})
                                                    </td>
                                                    <td className="py-1.5 text-right tabular-nums">{money(member.outstanding ?? 0)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive"
                                        onClick={() => router.delete(`/group/partners/${link.id}`, { preserveScroll: true })}
                                    >
                                        Unlink
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
