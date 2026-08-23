import { Link } from '@inertiajs/react';

/**
 * Shift & schedule as one section (Joy's reorganisation, 23 Aug 2026: "merge
 * the Shift & Schedule both in one"). One sidebar entry, two tabs; the routes
 * stay what they were, so nothing bookmarked or linked breaks. A shift is the
 * timing pattern, a schedule is who follows it - they answer one question
 * ("when does this person work") and now live behind one door.
 */
const TABS = [
    { title: 'Schedules', url: '/hrm/schedules' },
    { title: 'Shifts', url: '/hrm/shifts' },
];

export default function ShiftScheduleTabs({ active }: { active: string }) {
    return (
        <div className="border-border mb-4 flex gap-1 overflow-x-auto border-b">
            {TABS.map((tab) => (
                <Link
                    key={tab.url}
                    href={tab.url}
                    className={
                        active === tab.url
                            ? 'border-primary text-foreground -mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap'
                            : 'text-muted-foreground hover:text-foreground -mb-px border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap'
                    }
                >
                    {tab.title}
                </Link>
            ))}
        </div>
    );
}
