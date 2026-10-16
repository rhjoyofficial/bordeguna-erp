import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTranslation } from '@/lib/i18n';
import { type User } from '@/types';
import { Link } from '@inertiajs/react';
import { Coffee, LogOut, Repeat, UserCircle } from 'lucide-react';

interface UserMenuContentProps {
    user: User;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const { t } = useTranslation();

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {/* The personal screens (profile, password, appearance) under one's own name;
                    company-wide settings are the Settings link at the foot of the sidebar (15 Oct 2026). */}
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href={route('profile.edit')} as="button" prefetch onClick={cleanup}>
                        <UserCircle className="mr-2" />
                        My account
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href="/businesses/switch" as="button" onClick={cleanup}>
                        <Repeat className="mr-2" />
                        Switch business
                    </Link>
                </DropdownMenuItem>
                {/* Round 3 (16 Oct 2026): the short break left the sidebar for here; the header search still finds it. */}
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href="/relax" as="button" prefetch onClick={cleanup}>
                        <Coffee className="mr-2" />
                        {t('ws.take_a_break')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link className="block w-full" method="post" href={route('logout')} as="button" onClick={cleanup}>
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
