import { Hint, Meter, Panel, PermissionNote, Stat, type Tone } from '@/components/assets/assets-ui';
import { clockTime, dayMonth, longDate, number, plural } from '@/components/crm/lead-format';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Phone } from 'lucide-react';

interface StageCount {
    status: string;
    label: string;
    count: number;
}

interface Need {
    key: string;
    title: string;
    hint: string;
    count: number;
    tone: Tone;
    url: string;
}

interface ColdLead {
    id: number;
    name: string | null;
    type: string;
    place: string;
    held_by: string | null;
    last: string | null;
    days: number;
}

interface TeamView {
    stand: {
        stages: StageCount[];
        new_uncalled: number;
        converted_month: number;
        lost_month: number;
        lost_top_reason: { reason: string; count: number } | null;
        conversion_rate: number | null;
    };
    needs: Need[];
    follow_ups: {
        due_today: number;
        overdue: number;
        done_this_week: number;
        people: { who: string; open: number; overdue: number }[];
    };
    new_leads: { month: number; last_month: number; by_type: { type: string; label: string; count: number }[] };
    districts: { working: number; top: { district: string | null; count: number }[]; other_districts: number; other_count: number };
    going_cold: { count: number; rows: ColdLead[] };
    sources: { source: string; campaign: string; leads: number; converted: number; rate: number | null }[];
    demand: { what: string; kind: string; sku: string | null; people: number; requests: number; not_currently_sold: boolean }[];
    deals: {
        count: number;
        value: number;
        stages: { stage: string; count: number; value: number }[];
        top: { name: string; who: string | null; stage: string | null; value: number } | null;
    };
    phone_book: {
        contacts: number | null;
        opted_out: number | null;
        groups: number | null;
        sends:
            | {
                  id: number;
                  name: string;
                  channel: string;
                  status: string;
                  status_label: string;
                  sent_on: string | null;
                  sent: number;
                  cost: number;
              }[]
            | null;
    } | null;
}

interface TodayTask {
    id: number;
    title: string;
    due_at: string | null;
    overdue: boolean;
    subject: { kind: string; id: number; name: string | null; phone: string | null; place: string | null } | null;
}

interface PersonalView {
    my_day: {
        leads: number;
        due_today: number;
        overdue: number;
        oldest_overdue: string | null;
        new_this_week: number;
        new_uncalled: number;
        going_cold: number;
        converted: number;
        closed: number;
    };
    today: TodayTask[];
    going_cold: { count: number; rows: ColdLead[] };
    by_status: StageCount[];
    by_area: { thana: string; district: string | null; count: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'CRM', href: '/crm/overview' }];

/** What each stage means, in plain words, under its number. */
const STAGE_HINT: Record<string, string> = {
    new: 'not called yet',
    contacted: 'talking',
    qualified: 'wants to buy',
    opportunity: 'a deal is open',
    nurture: 'not now, later',
};

/**
 * The CRM Overview (redesign round 3, 16 Oct 2026). One page, two views from
 * the server: the TEAM view (where leads stand, what needs somebody, the
 * people's follow-ups, where leads come from) and the PERSONAL view of an
 * officer's day. Every number opens the list behind it, filtered where the
 * list has the filter. Calm base; colour only in text, by meaning.
 */
export default function CrmOverview({
    today,
    viewer,
    view,
    can,
    team,
    personal,
}: {
    today: string;
    viewer: string;
    view: 'team' | 'personal';
    can: { manage: boolean; verify: boolean; judge: boolean; approve: boolean; contacts: boolean; send: boolean };
    team: TeamView | null;
    personal: PersonalView | null;
}) {
    const firstName = viewer.split(' ')[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="CRM" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-xs">{longDate(today)}</p>
                        <p className="text-2xl font-semibold tracking-tight">{view === 'team' ? 'CRM' : `Good day, ${firstName}`}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={view === 'team' ? '/crm/queue' : '/crm/leads?follow_up=today'}>Today's follow-ups</Link>
                        </Button>
                        {can.manage && (
                            <Button size="sm" asChild>
                                <Link href="/crm/leads?add=1">Add a lead</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {team && <Team team={team} />}
                {personal && <Personal personal={personal} canRecord={can.manage} />}
            </div>
        </AppLayout>
    );
}

/* ───────────────────────────── Team view ───────────────────────────── */

function Team({ team }: { team: TeamView }) {
    return (
        <>
            <WhereLeadsStand stand={team.stand} />

            <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                <NeedsYou needs={team.needs} />
                <FollowUpsByPerson followUps={team.follow_ups} />
                <NewLeads newLeads={team.new_leads} />
                <Districts districts={team.districts} />
                <GoingCold going={team.going_cold} title="Going cold" />
                <Sources sources={team.sources} />
                <Demand demand={team.demand} />
                <OpenDeals deals={team.deals} />
                {team.phone_book && <PhoneBook book={team.phone_book} />}
            </div>
        </>
    );
}

function WhereLeadsStand({ stand }: { stand: TeamView['stand'] }) {
    const closed = stand.converted_month + stand.lost_month;

    return (
        <section className="bg-card rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="inline-flex items-center gap-1.5 text-[15px] font-semibold">
                    Where leads stand
                    <Hint text="Working leads by status. Duplicates and archived leads are left out. Converted and Lost count this month only." />
                </p>
                {stand.conversion_rate !== null && (
                    <span className="text-muted-foreground text-xs">
                        Converted {stand.converted_month} of the {closed} leads closed this month:{' '}
                        <span className="text-foreground font-medium">{stand.conversion_rate}%</span>
                    </span>
                )}
            </div>
            <div className="mt-3 flex flex-wrap gap-2.5">
                {stand.stages.map((stage) => (
                    <StepLink
                        key={stage.status}
                        href={`/crm/leads?status=${stage.status}`}
                        label={stage.label}
                        value={number(stage.count)}
                        hint={
                            stage.status === 'new' && stand.new_uncalled > 0
                                ? `${number(stand.new_uncalled)} not called yet`
                                : STAGE_HINT[stage.status]
                        }
                        hintTone={stage.status === 'new' && stand.new_uncalled > 0 ? 'warning' : 'muted'}
                    />
                ))}
                <StepLink
                    href="/crm/leads?status=converted&show=all"
                    label="Converted, this month"
                    value={number(stand.converted_month)}
                    hint="now customers"
                    valueTone="ok"
                />
                <StepLink
                    href="/crm/leads?status=lost&show=all"
                    label="Lost, this month"
                    value={number(stand.lost_month)}
                    hint={stand.lost_top_reason ? `${stand.lost_top_reason.count} ${stand.lost_top_reason.reason.toLowerCase()}` : 'no reason given'}
                    valueTone={stand.lost_month > 0 ? 'danger' : 'ok'}
                />
            </div>
        </section>
    );
}

function StepLink({
    href,
    label,
    value,
    hint,
    hintTone = 'muted',
    valueTone = 'ok',
}: {
    href: string;
    label: string;
    value: string;
    hint?: string;
    hintTone?: Tone;
    valueTone?: Tone;
}) {
    return (
        <Link href={href} className="hover:bg-muted/50 bg-card flex min-w-32 flex-1 basis-32 flex-col gap-0.5 rounded-lg border px-3.5 py-3">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className={cn('text-2xl font-semibold tabular-nums', valueTone === 'danger' && 'text-destructive')}>{value}</span>
            {hint && (
                <span className={cn('text-xs', hintTone === 'warning' ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground')}>{hint}</span>
            )}
        </Link>
    );
}

const NEED_TEXT: Record<Tone, string> = {
    danger: 'text-destructive',
    warning: 'text-amber-700 dark:text-amber-400',
    info: 'text-sky-700 dark:text-sky-400',
    muted: 'text-muted-foreground',
    ok: 'text-foreground',
};

function NeedsYou({ needs }: { needs: Need[] }) {
    return (
        <Panel
            title="Needs you"
            aside={<span className="text-muted-foreground text-xs">{needs.length === 0 ? 'Nothing waiting' : plural(needs.length, 'item')}</span>}
            flush
        >
            {needs.length === 0 ? (
                <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing overdue or waiting on you.</p>
            ) : (
                <ul>
                    {needs.map((need) => (
                        <li key={need.key}>
                            <Link href={need.url} className="hover:bg-muted/50 flex items-center gap-3 border-t px-4 py-2.5">
                                <span className={cn('w-8 shrink-0 text-right text-lg font-semibold tabular-nums', NEED_TEXT[need.tone])}>
                                    {number(need.count)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-[13.5px] font-medium">{need.title}</span>
                                    <span className="text-muted-foreground block truncate text-xs">{need.hint}</span>
                                </span>
                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function FollowUpsByPerson({ followUps }: { followUps: TeamView['follow_ups'] }) {
    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    Follow-ups by person
                    <Hint text="Open follow-ups per person, in alphabetical order. The same numbers as the follow-up load on Reports." />
                </span>
            }
            aside={
                <Link href="/crm/queue" className="text-primary text-xs hover:underline">
                    Follow-ups
                </Link>
            }
        >
            <div className="mt-3 flex flex-wrap gap-7">
                <Stat label="Due today" value={number(followUps.due_today)} href="/crm/queue" />
                <Stat label="Overdue" value={number(followUps.overdue)} tone={followUps.overdue > 0 ? 'danger' : 'ok'} href="/crm/queue" />
                <Stat label="Done this week" value={number(followUps.done_this_week)} href="/crm/queue?status=all" />
            </div>
            {followUps.people.length > 0 && (
                <table className="mt-3 w-full border-t text-[13px]">
                    <thead className="text-muted-foreground text-xs">
                        <tr>
                            <th className="py-2 text-left font-medium">Person</th>
                            <th className="py-2 text-right font-medium">Open</th>
                            <th className="py-2 text-right font-medium">Overdue</th>
                        </tr>
                    </thead>
                    <tbody>
                        {followUps.people.map((row) => (
                            <tr key={row.who} className="border-t">
                                <td className="py-1.5">{row.who}</td>
                                <td className="py-1.5 text-right tabular-nums">{number(row.open)}</td>
                                <td className={cn('py-1.5 text-right tabular-nums', row.overdue > 0 && 'text-destructive font-medium')}>
                                    {number(row.overdue)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Panel>
    );
}

function NewLeads({ newLeads }: { newLeads: TeamView['new_leads'] }) {
    const shown = newLeads.by_type.filter((row) => row.count > 0);

    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    New leads
                    <Hint text="Working leads added this month, by type. Duplicates and archived leads are left out." />
                </span>
            }
            aside={
                <Link href="/crm/leads" className="text-primary text-xs hover:underline">
                    All leads
                </Link>
            }
        >
            <div className="mt-3">
                <Stat label="This month, to date" value={number(newLeads.month)} size="lg" href="/crm/leads" />
                <p className="text-muted-foreground mt-1 text-xs">Last month: {number(newLeads.last_month)} in the whole month</p>
            </div>
            {shown.length > 0 && (
                <ul className="mt-3 grid gap-1.5 border-t pt-3 text-[13px]">
                    {shown.map((row) => (
                        <li key={row.type} className="flex justify-between gap-3">
                            <Link href={`/crm/leads?lead_type=${row.type}`} className="hover:underline">
                                {row.label}
                            </Link>
                            <span className="tabular-nums">{number(row.count)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function Districts({ districts }: { districts: TeamView['districts'] }) {
    const most = Math.max(1, ...districts.top.map((row) => row.count));

    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    Leads by district
                    <Hint text="Working leads by the district recorded on them." />
                </span>
            }
            aside={<span className="text-muted-foreground text-xs">{plural(districts.working, 'working lead')}</span>}
        >
            {districts.top.length === 0 ? (
                <p className="text-muted-foreground mt-3 text-sm">No working leads yet.</p>
            ) : (
                <ul className="mt-3 grid gap-2.5">
                    {districts.top.map((row) => (
                        <li key={row.district ?? 'none'}>
                            <div className="flex justify-between gap-3 text-[13px]">
                                {row.district ? (
                                    <Link href={`/crm/leads?district=${encodeURIComponent(row.district)}`} className="truncate hover:underline">
                                        {row.district}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">No district</span>
                                )}
                                <span className="tabular-nums">{number(row.count)}</span>
                            </div>
                            <Meter
                                className="mt-1 h-1.5"
                                label={`${row.district ?? 'No district'} ${row.count}`}
                                segments={[{ share: (row.count / most) * 100, className: 'bg-primary' }]}
                            />
                        </li>
                    ))}
                    {districts.other_districts > 0 && (
                        <li className="text-muted-foreground flex justify-between gap-3 text-[13px]">
                            <span>{plural(districts.other_districts, 'other district')}</span>
                            <span className="tabular-nums">{number(districts.other_count)}</span>
                        </li>
                    )}
                </ul>
            )}
        </Panel>
    );
}

function GoingCold({ going, title }: { going: { count: number; rows: ColdLead[] }; title: string }) {
    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    {title}
                    <Hint text="Working leads in New to Opportunity with no open follow-up and nothing recorded for 14 days (customers, retailers) or 30 days (distributors, suppliers)." />
                </span>
            }
            aside={<span className="text-muted-foreground text-xs">{plural(going.count, 'lead')}</span>}
            flush
        >
            {going.rows.length === 0 ? (
                <p className="text-muted-foreground px-4 pb-4 text-sm">Nobody is going quiet.</p>
            ) : (
                <ul>
                    {going.rows.map((row) => (
                        <li key={row.id}>
                            <Link href={`/crm/lead/${row.id}/timeline`} className="hover:bg-muted/50 flex items-start gap-3 border-t px-4 py-2.5">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-medium">
                                        {row.name ?? `Lead #${row.id}`} <span className="text-muted-foreground text-xs font-normal">{row.type}</span>
                                    </span>
                                    <span className="text-muted-foreground block truncate text-xs">
                                        {[
                                            row.place,
                                            row.held_by ? `Held by ${row.held_by}` : null,
                                            row.last ? `Last: ${row.last.toLowerCase()}` : null,
                                        ]
                                            .filter(Boolean)
                                            .join('. ')}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs font-medium text-amber-700 tabular-nums dark:text-amber-400">{row.days} days</span>
                            </Link>
                        </li>
                    ))}
                    {going.count > going.rows.length && (
                        <li>
                            <Link href="/crm/leads?cold=1" className="text-primary block border-t px-4 py-2.5 text-xs hover:underline">
                                See all {number(going.count)}
                            </Link>
                        </li>
                    )}
                </ul>
            )}
        </Panel>
    );
}

function Sources({ sources }: { sources: TeamView['sources'] }) {
    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    Where leads come from
                    <Hint text="Leads with a recorded source, and how many of them became customers. Reports shows them all." />
                </span>
            }
            aside={
                <Link href="/crm/reports" className="text-primary text-xs hover:underline">
                    Reports
                </Link>
            }
        >
            {sources.length === 0 ? (
                <p className="text-muted-foreground mt-3 text-sm">No lead has a source recorded yet.</p>
            ) : (
                <table className="mt-3 w-full text-[13px]">
                    <thead className="text-muted-foreground text-xs">
                        <tr>
                            <th className="py-1.5 text-left font-medium">Source</th>
                            <th className="py-1.5 text-right font-medium">Leads</th>
                            <th className="py-1.5 text-right font-medium">Converted</th>
                            <th className="py-1.5 text-right font-medium">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sources.map((row) => (
                            <tr key={`${row.source}-${row.campaign}`} className="border-t">
                                <td className="py-1.5">
                                    {row.source}
                                    {row.campaign !== '-' && <span className="text-muted-foreground block text-xs">{row.campaign}</span>}
                                </td>
                                <td className="py-1.5 text-right tabular-nums">{number(row.leads)}</td>
                                <td className="py-1.5 text-right tabular-nums">{number(row.converted)}</td>
                                <td className="py-1.5 text-right tabular-nums">{row.rate === null ? '-' : `${Math.round(row.rate)}%`}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Panel>
    );
}

function Demand({ demand }: { demand: TeamView['demand'] }) {
    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    Asked for, still waiting
                    <Hint text="Open requests, counted by the PEOPLE who asked, not by how many times." />
                </span>
            }
            aside={
                <Link href="/crm/reports" className="text-primary text-xs hover:underline">
                    Reports
                </Link>
            }
        >
            {demand.length === 0 ? (
                <p className="text-muted-foreground mt-3 text-sm">Nobody is waiting for anything.</p>
            ) : (
                <ul className="mt-3 grid gap-2 text-[13px]">
                    {demand.map((row) => (
                        <li key={`${row.kind}-${row.what}`} className="flex justify-between gap-3">
                            <span className="min-w-0">
                                <span className="block truncate">
                                    {row.kind === 'brand' ? `Brand: ${row.what}` : row.what}
                                    {row.not_currently_sold && (
                                        <span className="ml-1.5 text-xs font-medium text-amber-700 dark:text-amber-400">not sold now</span>
                                    )}
                                </span>
                            </span>
                            <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                {plural(row.people, 'person', 'people')}
                                {row.requests > row.people && `, ${number(row.requests)} asks`}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function OpenDeals({ deals }: { deals: TeamView['deals'] }) {
    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    Open deals
                    <Hint text="Deals still open on the Pipeline, by stage, with what they are worth." />
                </span>
            }
            aside={
                <Link href="/crm/pipeline" className="text-primary text-xs hover:underline">
                    Pipeline
                </Link>
            }
        >
            <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
                <Stat label="Worth, Tk" value={number(deals.value)} size="lg" href="/crm/pipeline" />
                <span className="text-muted-foreground text-xs">{plural(deals.count, 'deal')}</span>
            </div>
            {deals.stages.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 border-t pt-3">
                    {deals.stages.map((stage) => (
                        <div key={stage.stage}>
                            <div className="text-muted-foreground text-xs">{stage.stage}</div>
                            <div className="text-lg font-semibold tabular-nums">{number(stage.count)}</div>
                            <div className="text-muted-foreground text-xs tabular-nums">Tk {number(stage.value)}</div>
                        </div>
                    ))}
                </div>
            )}
            {deals.top && (
                <p className="mt-3 flex justify-between gap-3 border-t pt-3 text-[13px]">
                    <span className="min-w-0 truncate">
                        {deals.top.who ?? deals.top.name}
                        {deals.top.stage && <span className="text-muted-foreground">, {deals.top.stage}</span>}
                    </span>
                    <span className="shrink-0 tabular-nums">{number(deals.top.value)}</span>
                </p>
            )}
        </Panel>
    );
}

function PhoneBook({ book }: { book: NonNullable<TeamView['phone_book']> }) {
    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    Phone book and messages
                    <Hint text="Active contacts, numbers that asked not to be messaged, and the latest sends." />
                </span>
            }
            aside={
                book.contacts !== null ? (
                    <Link href="/crm/contacts" className="text-primary text-xs hover:underline">
                        Phone book
                    </Link>
                ) : (
                    <PermissionNote title="Shown to people who may send SMS and email">Senders</PermissionNote>
                )
            }
        >
            {book.contacts !== null && (
                <div className="mt-3 flex flex-wrap gap-7">
                    <Stat label="Contacts" value={number(book.contacts)} href="/crm/contacts" />
                    <Stat label="Opted out" value={number(book.opted_out ?? 0)} href="/crm/contacts" />
                    <Stat label="Groups" value={number(book.groups ?? 0)} href="/crm/contacts" />
                </div>
            )}
            {book.sends && book.sends.length > 0 && (
                <ul className="mt-3 grid gap-2 border-t pt-3 text-[13px]">
                    {book.sends.map((send) => (
                        <li key={send.id}>
                            <Link href={`/crm/messages/${send.channel}`} className="hover:underline">
                                {send.channel === 'sms' ? 'SMS' : 'Email'} "{send.name}"
                            </Link>
                            <span
                                className={cn(
                                    'block text-xs',
                                    send.status === 'pending_approval' ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground',
                                )}
                            >
                                {send.status === 'sent'
                                    ? `Sent ${dayMonth(send.sent_on)}, ${number(send.sent)} sent${send.cost > 0 ? `, Tk ${number(send.cost)}` : ''}`
                                    : send.status_label}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/* ─────────────────────────── Personal view ─────────────────────────── */

function Personal({ personal, canRecord }: { personal: PersonalView; canRecord: boolean }) {
    const day = personal.my_day;

    return (
        <>
            <section className="bg-card rounded-xl border p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="inline-flex items-center gap-1.5 text-[15px] font-semibold">
                        My day
                        <Hint text="Your own leads and follow-ups (and your team's, if you lead one)." />
                    </p>
                    <span className="text-muted-foreground text-xs">{plural(day.leads, 'working lead')}</span>
                </div>
                <div className="mt-3 flex flex-wrap gap-2.5">
                    <StepLink
                        href="/crm/leads?follow_up=today"
                        label="Due today"
                        value={number(day.due_today)}
                        hint={day.overdue > 0 ? `${number(day.overdue)} from before today` : 'calls and visits'}
                    />
                    <StepLink
                        href="/crm/queue"
                        label="Overdue"
                        value={number(day.overdue)}
                        valueTone={day.overdue > 0 ? 'danger' : 'ok'}
                        hint={day.oldest_overdue ? `oldest ${dayMonth(day.oldest_overdue)}` : 'nothing late'}
                    />
                    <StepLink
                        href="/crm/leads?status=new"
                        label="New this week"
                        value={number(day.new_this_week)}
                        hint={day.new_uncalled > 0 ? `${number(day.new_uncalled)} not called yet` : 'all contacted'}
                        hintTone={day.new_uncalled > 0 ? 'warning' : 'muted'}
                    />
                    <StepLink href="/crm/leads?cold=1" label="Going cold" value={number(day.going_cold)} hint="quiet for 14+ days" />
                    <StepLink
                        href="/crm/leads?status=converted&show=all"
                        label="Converted, this month"
                        value={number(day.converted)}
                        hint={day.closed > 0 ? `${day.converted} of ${day.closed} closed` : 'none closed yet'}
                    />
                </div>
            </section>

            <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                <TodaysFollowUps tasks={personal.today} canRecord={canRecord} />
                <GoingCold going={personal.going_cold} title="My leads going cold" />
                <Panel
                    title="My leads by status"
                    aside={
                        <Link href="/crm/leads" className="text-primary text-xs hover:underline">
                            My leads
                        </Link>
                    }
                >
                    <ul className="mt-3 grid gap-1.5 text-[13px]">
                        {personal.by_status.map((row) => (
                            <li key={row.status} className="flex justify-between gap-3">
                                <Link href={`/crm/leads?status=${row.status}`} className="hover:underline">
                                    {row.label}
                                </Link>
                                <span className="tabular-nums">{number(row.count)}</span>
                            </li>
                        ))}
                    </ul>
                </Panel>
                <Panel
                    title={
                        <span className="inline-flex items-center gap-1.5">
                            My leads by area
                            <Hint text="Working leads by thana: plan a route through the busiest." />
                        </span>
                    }
                >
                    {personal.by_area.length === 0 ? (
                        <p className="text-muted-foreground mt-3 text-sm">No thana recorded on your leads yet.</p>
                    ) : (
                        <ul className="mt-3 grid gap-1.5 text-[13px]">
                            {personal.by_area.map((row) => (
                                <li key={`${row.thana}-${row.district}`} className="flex justify-between gap-3">
                                    <span className="truncate">
                                        {row.thana}
                                        {row.district && <span className="text-muted-foreground">, {row.district}</span>}
                                    </span>
                                    <span className="tabular-nums">{number(row.count)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}

function TodaysFollowUps({ tasks, canRecord }: { tasks: TodayTask[]; canRecord: boolean }) {
    const done = (task: TodayTask) => router.put(`/crm/tasks/${task.id}`, { status: 'done' }, { preserveScroll: true });

    return (
        <Panel
            title="Today's follow-ups"
            aside={
                <Link href="/crm/queue" className="text-primary text-xs hover:underline">
                    All follow-ups
                </Link>
            }
            flush
            className="md:col-span-2 2xl:col-span-1"
        >
            {tasks.length === 0 ? (
                <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing due today.</p>
            ) : (
                <ul>
                    {tasks.map((task) => (
                        <li key={task.id} className="flex flex-wrap items-start gap-3 border-t px-4 py-2.5">
                            <span
                                className={cn(
                                    'w-16 shrink-0 text-xs font-medium tabular-nums',
                                    task.overdue ? 'text-destructive' : 'text-muted-foreground',
                                )}
                            >
                                {task.overdue ? `Overdue, ${dayMonth(task.due_at)}` : clockTime(task.due_at)}
                            </span>
                            <span className="min-w-0 flex-1">
                                {task.subject ? (
                                    <Link
                                        href={`/crm/${task.subject.kind}/${task.subject.id}/timeline`}
                                        className="block text-[13.5px] font-medium hover:underline"
                                    >
                                        {task.subject.name ?? 'No name'}
                                    </Link>
                                ) : null}
                                <span className="block text-[13px]">{task.title}</span>
                                {task.subject?.place && <span className="text-muted-foreground block truncate text-xs">{task.subject.place}</span>}
                            </span>
                            <span className="flex shrink-0 gap-1.5">
                                {task.subject?.phone && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={`tel:${task.subject.phone}`} aria-label={`Call ${task.subject.name ?? ''}`}>
                                            <Phone className="size-3.5" />
                                            Call
                                        </a>
                                    </Button>
                                )}
                                {canRecord && (
                                    <Button variant="outline" size="sm" onClick={() => done(task)}>
                                        Done
                                    </Button>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
