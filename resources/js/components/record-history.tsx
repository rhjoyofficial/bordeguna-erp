import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { datesInText } from '@/lib/dates';
import { useState } from 'react';

/**
 * A record's History, in a side panel (edit, delete and correct, Phase 0,
 * PHASE-0-CONTRACT 1.13). Read-only: what was recorded, changed, corrected,
 * reversed, asked for and decided, with the reasons and links.
 *
 * ⚠ A GET (records.history), so this hand-written fetch is a safe one.
 * ⚠ When the log starts after the record was made, the panel says so in a
 * sentence (`gap_notice`) instead of looking complete.
 */

type Entry = {
    at: string;
    at_human: string;
    actor: string | null;
    kind: string;
    summary: string;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    links: { label: string; url: string | null }[];
};

type History = { entries: Entry[]; recorded_since: string | null; gap_notice: string | null };

const show = (value: unknown) => (value === null || value === undefined || value === '' ? 'empty' : String(value));

export function RecordHistory({ type, id, label }: { type: string; id: number; label: string }) {
    const [history, setHistory] = useState<History | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    const load = async () => {
        setProblem(null);

        try {
            const response = await fetch(`/records/${type}/${id}/history`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!response.ok) {
                setProblem('The history could not be loaded.');

                return;
            }

            setHistory((await response.json()) as History);
        } catch {
            setProblem('The history could not be loaded.');
        }
    };

    return (
        <Sheet onOpenChange={(open) => open && void load()}>
            <SheetTrigger asChild>
                <button type="button" className="text-xs underline">
                    History
                </button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>History of {label}</SheetTitle>
                    <SheetDescription>Everything recorded about it, newest first.</SheetDescription>
                </SheetHeader>

                <div className="grid gap-3 px-4 pb-6 text-sm">
                    {problem && <p className="text-destructive">{problem}</p>}
                    {history === null && !problem && <p className="text-muted-foreground">Loading...</p>}
                    {history?.gap_notice && <p className="text-muted-foreground italic">{history.gap_notice}</p>}
                    {history && history.entries.length === 0 && <p className="text-muted-foreground">Nothing has been recorded yet.</p>}

                    {history?.entries.map((entry, index) => (
                        <div key={`${entry.at}-${index}`} className="border-sidebar-border/70 grid gap-1 border-b pb-2">
                            <div className="text-muted-foreground text-xs">
                                {entry.at_human}
                                {entry.actor ? `, ${entry.actor}` : ''}
                            </div>
                            <div>{datesInText(entry.summary)}</div>
                            {entry.after && entry.before && (
                                <ul className="text-muted-foreground text-xs">
                                    {Object.keys(entry.after).map((field) => (
                                        <li key={field}>
                                            {field.replaceAll('_', ' ')}: {show(entry.before?.[field])} to {show(entry.after?.[field])}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {entry.links.length > 0 && (
                                <div className="flex flex-wrap gap-3 text-xs">
                                    {entry.links.map((link) =>
                                        link.url ? (
                                            <a key={link.label} href={link.url} className="underline">
                                                {link.label}
                                            </a>
                                        ) : (
                                            <span key={link.label}>{link.label}</span>
                                        ),
                                    )}
                                </div>
                            )}
                        </div>
                    ))}

                    <div>
                        <Button type="button" variant="outline" size="sm" onClick={() => void load()}>
                            Refresh
                        </Button>
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    );
}
