import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface SearchItem {
    label: string;
    sub: string | null;
    url: string;
}

interface SearchGroup {
    title: string;
    items: SearchItem[];
}

/**
 * The header search Joy asked for from the DreamsPOS reference: type a thing,
 * land on its page. Products, customers, suppliers, invoices, employees -
 * whatever THIS user is allowed to see, filtered server-side.
 *
 * ⚠ A GET to /search, deliberately - the hand-written-fetch trap in this
 * project's history was a POST missing its CSRF token and dying as a swallowed
 * 419. A GET needs none of that ceremony.
 *
 * Ctrl+K (or Cmd+K) focuses it from anywhere, Escape closes, Enter opens the
 * first result.
 */
export default function HeaderSearch() {
    const [q, setQ] = useState('');
    const [groups, setGroups] = useState<SearchGroup[]>([]);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const boxRef = useRef<HTMLDivElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                inputRef.current?.focus();
            }
            if (e.key === 'Escape') {
                setOpen(false);
                inputRef.current?.blur();
            }
        };
        const onClick = (e: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);
        window.addEventListener('mousedown', onClick);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('mousedown', onClick);
        };
    }, []);

    const change = (value: string) => {
        setQ(value);
        if (timer.current) clearTimeout(timer.current);

        if (value.trim().length < 2) {
            setGroups([]);
            setOpen(false);
            return;
        }

        // Debounced so a fast typist costs one request, not one per key.
        timer.current = setTimeout(() => {
            setBusy(true);
            fetch(`/search?q=${encodeURIComponent(value.trim())}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : { groups: [] }))
                .then((data: { groups: SearchGroup[] }) => {
                    setGroups(data.groups);
                    setOpen(true);
                })
                .catch(() => setGroups([]))
                .finally(() => setBusy(false));
        }, 250);
    };

    const go = (url: string) => {
        setOpen(false);
        setQ('');
        setGroups([]);
        router.visit(url);
    };

    const first = groups[0]?.items[0];

    return (
        <div ref={boxRef} className="relative hidden md:block">
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                <input
                    ref={inputRef}
                    type="search"
                    value={q}
                    onChange={(e) => change(e.target.value)}
                    onFocus={() => q.trim().length >= 2 && setOpen(true)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && first) go(first.url);
                    }}
                    placeholder="Search  (Ctrl+K)"
                    aria-label="Search the ERP"
                    className="border-input bg-background focus-visible:ring-ring/50 h-9 w-56 rounded-md border py-1 pr-3 pl-8 text-sm focus-visible:ring-[3px] focus-visible:outline-none"
                />
            </div>

            {open && (
                <div className="border-border bg-popover text-popover-foreground absolute right-0 z-50 mt-1 max-h-96 w-80 overflow-y-auto rounded-md border p-2 shadow-md">
                    {groups.length === 0 ? (
                        <p className="text-muted-foreground px-2 py-1.5 text-sm">{busy ? 'Searching…' : 'Nothing found.'}</p>
                    ) : (
                        groups.map((group) => (
                            <div key={group.title} className="mb-1 last:mb-0">
                                <p className="text-muted-foreground px-2 pt-1 pb-0.5 text-xs font-medium">{group.title}</p>
                                {group.items.map((item, i) => (
                                    <button
                                        key={`${group.title}-${i}`}
                                        type="button"
                                        onClick={() => go(item.url)}
                                        className="hover:bg-accent hover:text-accent-foreground flex w-full items-baseline justify-between gap-2 rounded px-2 py-1.5 text-left text-sm"
                                    >
                                        <span className="truncate">{item.label}</span>
                                        {item.sub && <span className="text-muted-foreground shrink-0 text-xs">{item.sub}</span>}
                                    </button>
                                ))}
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
