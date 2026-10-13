import { TranslationKey, useTranslation } from '@/lib/i18n';
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
const TABS: { key: TranslationKey; url: string }[] = [
    { key: 'tabs.my_profile', url: '/hrm/my-profile' },
    { key: 'tabs.clock', url: '/hrm/clock' },
    { key: 'tabs.my_leave', url: '/hrm/my-leave' },
    { key: 'tabs.my_payslips', url: '/hrm/my-payslips' },
    { key: 'tabs.my_expenses', url: '/hrm/my-expenses' },
    // Show cause notices issued to this employee (Joy, 24 Aug 2026) - the
    // employee's own printable copies of what HR put on record.
    { key: 'tabs.my_notices', url: '/hrm/my-notices' },
    // What the employee holds from the asset register (slice A2, 16 Sep 2026).
    { key: 'tabs.my_assets', url: '/hrm/my-assets' },
    // The accounts the employee has access to (slice 4, 13 Oct 2026).
    { key: 'tabs.my_accounts', url: '/hrm/my-accounts' },
];

export default function SelfServiceTabs({ active }: { active: string }) {
    const { t } = useTranslation();

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
                    {t(tab.key)}
                </Link>
            ))}
        </div>
    );
}
