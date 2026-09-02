/**
 * A record's own story (Module 1, Slice 4): the activity log and approval
 * decisions the system already keeps, drawn as a vertical rail. Read-only by
 * construction - if an event is not on record, it is not here.
 */
export interface TimelineEntry {
    when: string;
    when_human: string;
    who: string | null;
    what: string;
    note: string | null;
}

export default function RecordTimeline({ entries }: { entries: TimelineEntry[] }) {
    if (!entries || entries.length === 0) {
        return <p className="text-muted-foreground text-sm">Nothing recorded yet.</p>;
    }

    return (
        <ol className="border-border ml-2 space-y-4 border-l-2 pl-4">
            {entries.map((entry, index) => (
                <li key={index} className="relative">
                    <span className="border-border bg-card absolute top-1 -left-[23px] h-3 w-3 rounded-full border-2" />
                    <p className="text-sm leading-tight font-medium">{entry.what}</p>
                    <p className="text-muted-foreground text-xs">
                        {entry.who ?? 'System'} · <time dateTime={entry.when}>{entry.when_human}</time>
                    </p>
                    {entry.note && <p className="text-muted-foreground mt-1 text-xs italic">"{entry.note}"</p>}
                </li>
            ))}
        </ol>
    );
}
