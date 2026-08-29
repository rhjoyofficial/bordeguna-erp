import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Bot, Building2, KeyRound, ListChecks, Network, Palette, ScrollText, ShieldCheck, User, Users } from 'lucide-react';

/**
 * ⚠⚠ `wide` exists because the audit trail was unreadable without it. Settings
 * pages are forms, so the content column is clamped to max-w-xl, which is right
 * for a profile form and wrong for a five-column table of every change ever
 * made: it was being squeezed into 576px beside a 192px nav. Data-heavy settings
 * pages opt out; the forms keep the narrow measure that makes them readable.
 */
export default function SettingsLayout({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
    const { canManageUsers, canManageOrganization } = usePage<SharedData>().props;
    const currentPath = window.location.pathname;

    const sidebarNavItems: NavItem[] = [
        { title: 'Profile', url: '/settings/profile', icon: User },
        { title: 'Password', url: '/settings/password', icon: KeyRound },
        { title: 'Appearance', url: '/settings/appearance', icon: Palette },
        { title: 'Reasons', url: '/settings/reasons', icon: ListChecks },
        ...(canManageUsers
            ? [
                  { title: 'Team', url: '/settings/team', icon: Users },
                  { title: 'Roles', url: '/settings/roles', icon: ShieldCheck },
              ]
            : []),
        ...(canManageOrganization
            ? [
                  { title: 'Business', url: '/settings/business', icon: Building2 },
                  { title: 'Organization', url: '/settings/organization', icon: Network },
                  { title: 'AI providers', url: '/settings/ai-providers', icon: Bot },
                  // ⚠ The trail was written from day one and had no door. This
                  // link is the door.
                  { title: 'Audit trail', url: '/settings/audit', icon: ScrollText },
              ]
            : []),
    ];

    return (
        <div className="px-4 py-6">
            <Heading title="Settings" description="Manage your profile and account settings" />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.icon && <item.icon className="h-4 w-4" />}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className={cn('flex-1', wide ? 'min-w-0' : 'md:max-w-2xl')}>
                    <section className={cn('space-y-12', wide ? '' : 'max-w-xl')}>{children}</section>
                </div>
            </div>
        </div>
    );
}
