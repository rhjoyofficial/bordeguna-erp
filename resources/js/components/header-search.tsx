import { useNavGroups } from '@/components/app-sidebar';
import { matchMenus } from '@/lib/menu-search';
import { router } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpen,
    ClipboardList,
    CornerDownLeft,
    FileCheck,
    FileText,
    LayoutGrid,
    LifeBuoy,
    Loader2,
    Package,
    Receipt,
    Search,
    ShoppingCart,
    Target,
    TrendingUp,
    Truck,
    UserRound,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface SearchItem {
    label: string;
    sub: string | null;
    url: string;
}

interface SearchGroup {
    title: string;
    icon: string;
    items: SearchItem[];
    total: number;
    more_url: string | null;
}

/** One thing the arrow keys can land on: a result, or a "view all" row. */
interface Row {
    url: string;
    kind: 'item' | 'more';
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
 * ── 6 Sep 2026, Joy: "the search box not showing enough link to click" ──
 * Four changes, three of them about having somewhere to click:
 *
 * 1. Each group now shows its TOTAL and, when more matched than fit, a "View
 *    all N" row that goes to the list screen filtered by the same words. The
 *    old box showed five results and then simply stopped, with no sixth click
 *    available anywhere.
 * 2. Arrow keys walk every row and Enter opens the highlighted one. Before,
 *    Enter could only ever open the first result and the mouse was the only
 *    way to reach the second.
 * 3. The box was `hidden md:block`, so on a phone the ERP had no search at
 *    all. Small screens now get a search button that opens a full-width panel.
 * 4. Each group carries an icon, so thirteen possible groups can be told apart
 *    at a glance instead of reading thirteen identical headings.
 *
 * ⚠ The panel is deliberately capped at 70vh and scrolls. On a laptop, a
 * common word can now legitimately return thirteen groups, and a dropdown
 * taller than the window puts its own last group off-screen.
 */

const ICONS: Record<string, LucideIcon> = {
    menu: LayoutGrid,
    product: Package,
    customer: Users,
    invoice: FileText,
    order: ClipboardList,
    quotation: FileCheck,
    supplier: Truck,
    'purchase-order': ShoppingCart,
    expense: Receipt,
    employee: UserRound,
    journal: BookOpen,
    lead: Target,
    opportunity: TrendingUp,
    ticket: LifeBuoy,
};

/**
 * The "Menus" group (Joy, 11 Oct 2026: "can find any sidebar menu from the
 * search bar, based on the roles"). Built in the browser from the sidebar's own
 * list, so a person finds exactly the pages their role can open and nothing
 * else, and the match is instant while the record search is still loading.
 * Every word typed must appear in the page name, its section or its keywords.
 */
function useMenuGroup(q: string): SearchGroup | null {
    const navGroups = useNavGroups();

    return useMemo(() => {
        // Tabs are searchable too, with their keywords (round 3, lib/menu-search).
        const items = matchMenus(navGroups, q);

        return items.length === 0 ? null : { title: 'Menus', icon: 'menu', items: items.slice(0, 8), total: items.length, more_url: null };
    }, [q, navGroups]);
}

export default function HeaderSearch() {
    const [q, setQ] = useState('');
    const [recordGroups, setGroups] = useState<SearchGroup[]>([]);
    const [recordTotal, setTotal] = useState(0);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [active, setActive] = useState(0);
    const [onPhone, setOnPhone] = useState(false);

    const inputRef = useRef<HTMLInputElement>(null);
    const phoneInputRef = useRef<HTMLInputElement>(null);
    const boxRef = useRef<HTMLDivElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Pages first: someone typing "payroll" usually wants the screen, and the
    // record matches follow underneath.
    const menuGroup = useMenuGroup(q);
    const groups = useMemo(() => (menuGroup ? [menuGroup, ...recordGroups] : recordGroups), [menuGroup, recordGroups]);
    const total = recordTotal + (menuGroup?.total ?? 0);

    // Every landable row in render order, so the arrow keys and the mouse
    // agree about what "the next one" means.
    const rows = useMemo<Row[]>(() => {
        const flat: Row[] = [];
        groups.forEach((group) => {
            group.items.forEach((item) => flat.push({ url: item.url, kind: 'item' }));
            if (group.more_url) {
                flat.push({ url: group.more_url, kind: 'more' });
            }
        });
        return flat;
    }, [groups]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (window.matchMedia('(min-width: 768px)').matches) {
                    inputRef.current?.focus();
                } else {
                    setOnPhone(true);
                }
            }
            if (e.key === 'Escape') {
                setOpen(false);
                setOnPhone(false);
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

    // Focus the phone panel's own input once it is actually mounted.
    useEffect(() => {
        if (onPhone) {
            phoneInputRef.current?.focus();
        }
    }, [onPhone]);

    const change = (value: string) => {
        setQ(value);
        if (timer.current) clearTimeout(timer.current);

        if (value.trim().length < 2) {
            setGroups([]);
            setTotal(0);
            setOpen(false);
            return;
        }

        setActive(0);
        setOpen(true);

        // Debounced so a fast typist costs one request, not one per key.
        timer.current = setTimeout(() => {
            setBusy(true);
            fetch(`/search?q=${encodeURIComponent(value.trim())}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : { groups: [], total: 0 }))
                .then((data: { groups: SearchGroup[]; total: number }) => {
                    setGroups(data.groups);
                    setTotal(data.total ?? 0);
                    setActive(0);
                    setOpen(true);
                })
                .catch(() => {
                    setGroups([]);
                    setTotal(0);
                })
                .finally(() => setBusy(false));
        }, 250);
    };

    const go = (url: string) => {
        setOpen(false);
        setOnPhone(false);
        setQ('');
        setGroups([]);
        setTotal(0);
        router.visit(url);
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (rows.length === 0) {
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % rows.length);
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i - 1 + rows.length) % rows.length);
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            const row = rows[active] ?? rows[0];
            if (row) go(row.url);
        }
    };

    // Where each rendered row sits in the flat list, so highlighting and the
    // arrow keys cannot drift apart as groups come and go.
    let cursor = -1;

    const results = (
        <>
            {groups.length === 0 ? (
                <p className="text-muted-foreground flex items-center gap-2 px-2 py-3 text-sm">
                    {busy ? (
                        <>
                            <Loader2 className="h-3.5 w-3.5 animate-spin" /> Searching…
                        </>
                    ) : (
                        'Nothing found.'
                    )}
                </p>
            ) : (
                <>
                    <p className="text-muted-foreground px-2 pb-1 text-xs">
                        {total} {total === 1 ? 'match' : 'matches'}
                    </p>
                    {groups.map((group) => {
                        const Icon = ICONS[group.icon] ?? Search;

                        return (
                            <div key={group.title} className="mb-1 last:mb-0">
                                <p className="text-muted-foreground flex items-center gap-1.5 px-2 pt-1 pb-0.5 text-xs font-medium">
                                    <Icon className="h-3.5 w-3.5" />
                                    {group.title}
                                    <span className="text-muted-foreground/70">({group.total})</span>
                                </p>

                                {group.items.map((item, i) => {
                                    cursor += 1;
                                    const here = cursor;

                                    return (
                                        <button
                                            key={`${group.title}-${i}`}
                                            type="button"
                                            onClick={() => go(item.url)}
                                            onMouseEnter={() => setActive(here)}
                                            className={`flex w-full items-baseline justify-between gap-2 rounded px-2 py-1.5 text-left text-sm ${
                                                active === here ? 'bg-accent text-accent-foreground' : ''
                                            }`}
                                        >
                                            <span className="truncate">{item.label}</span>
                                            {item.sub && <span className="text-muted-foreground shrink-0 text-xs">{item.sub}</span>}
                                        </button>
                                    );
                                })}

                                {group.more_url &&
                                    (() => {
                                        cursor += 1;
                                        const here = cursor;

                                        return (
                                            <button
                                                type="button"
                                                onClick={() => go(group.more_url as string)}
                                                onMouseEnter={() => setActive(here)}
                                                className={`text-muted-foreground hover:text-foreground flex w-full items-center gap-1 rounded px-2 py-1 text-left text-xs ${
                                                    active === here ? 'bg-accent text-accent-foreground' : ''
                                                }`}
                                            >
                                                View all {group.total} <ArrowRight className="h-3 w-3" />
                                            </button>
                                        );
                                    })()}
                            </div>
                        );
                    })}
                    <p className="text-muted-foreground/70 flex items-center gap-1 border-t px-2 pt-1.5 text-[11px]">
                        <CornerDownLeft className="h-3 w-3" /> Enter opens the highlighted result, ↑ ↓ to move
                    </p>
                </>
            )}
        </>
    );

    return (
        <>
            {/* Small screens: a button, because a permanent input would eat the
                header. It opens the same search as a full-width panel. */}
            <button
                type="button"
                onClick={() => setOnPhone(true)}
                aria-label="Search the ERP"
                className="hover:bg-accent hover:text-accent-foreground inline-flex h-9 w-9 items-center justify-center rounded-md md:hidden"
            >
                <Search className="h-4.5 w-4.5" />
            </button>

            {onPhone && (
                <div className="bg-background/80 fixed inset-0 z-50 md:hidden" onMouseDown={() => setOnPhone(false)}>
                    <div className="bg-popover text-popover-foreground m-3 rounded-lg border p-2 shadow-lg" onMouseDown={(e) => e.stopPropagation()}>
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                            <input
                                ref={phoneInputRef}
                                type="search"
                                value={q}
                                onChange={(e) => change(e.target.value)}
                                onKeyDown={onKeyDown}
                                placeholder="Search the ERP"
                                aria-label="Search the ERP"
                                className="border-input bg-background h-10 w-full rounded-md border py-1 pr-9 pl-8 text-sm focus-visible:outline-none"
                            />
                            <button
                                type="button"
                                onClick={() => setOnPhone(false)}
                                aria-label="Close search"
                                className="text-muted-foreground absolute top-1/2 right-2 -translate-y-1/2"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        {q.trim().length >= 2 && <div className="mt-2 max-h-[60vh] overflow-y-auto">{results}</div>}
                    </div>
                </div>
            )}

            {/* md and up: the inline box. */}
            <div ref={boxRef} className="relative hidden md:block">
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                    <input
                        ref={inputRef}
                        type="search"
                        value={q}
                        onChange={(e) => change(e.target.value)}
                        onFocus={() => q.trim().length >= 2 && setOpen(true)}
                        onKeyDown={onKeyDown}
                        placeholder="Search  (Ctrl+K)"
                        aria-label="Search the ERP"
                        className="border-input bg-background focus-visible:ring-ring/50 h-9 w-56 rounded-md border py-1 pr-3 pl-8 text-sm focus-visible:ring-[3px] focus-visible:outline-none lg:w-72"
                    />
                </div>

                {open && (
                    <div className="border-border bg-popover text-popover-foreground absolute right-0 z-50 mt-1 max-h-[70vh] w-[26rem] overflow-y-auto rounded-md border p-2 shadow-md">
                        {results}
                    </div>
                )}
            </div>
        </>
    );
}
