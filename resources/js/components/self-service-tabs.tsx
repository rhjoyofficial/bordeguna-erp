import { Link } from '@inertiajs/react';

/**
 * The employee's own corner of the ERP, as one place.
 *
 * ⚠ Joy's reorganisation, 23 Aug 2026: Clock in/out, My leave and My payslips
 * stop being separate sidebar entries and live under My profile instead. The
 * sidebar keeps ONE link; these tabs carry the rest. The routes themselves are
 * unchanged - a bookmark to /hrm/clock still works - because moving a page and
 * breaking its address are different jobs and only the first was asked for.
 */
const TABS = [
    { title: 'My profile', url: '/hrm/my-profile' },
    { title: 'Clock in/out', url: '/hrm/clock' },
    { title: 'My leave', url: '/hrm/my-leave' },
    { title: 'My payslips', url: '/hrm/my-payslips' },
    { title: 'My expenses', url: '/hrm/my-expenses' },
    // Show cause notices issued to this employee (Joy, 24 Aug 2026) - the
    // employee's own printable copies of what HR put on record.
    { title: 'My notices', url: '/hrm/my-notices' },
];

export default function SelfServiceTabs({ active }: { active: string }) {
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
