import { Link, router } from '@inertiajs/react';
import { LogOut, BookOpen, Settings, LayoutGrid, User, Film } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { index } from '@/routes/users'

import { useMobileNavigation } from '@/hooks/use-mobile-navigation';

import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Video',
        href: "/",
        icon: Film,
    },
    {
        title: 'Users',
        href: index(),
        icon: User,
    },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const footerNavItems: NavItem[] = [

    {
        title: 'Repository',
        href: "",
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };




    return (
        <Sidebar collapsible="icon" variant="inset">

            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild>
                            <Link
                                className="block w-full cursor-pointer"
                                href={edit()}
                                prefetch
                                onClick={cleanup}
                            >
                                <Settings className="h-5 w-5" />
                                <span>Settings</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild>
                            <Link
                                href={logout()}
                                as="button"
                                onClick={handleLogout}
                                className="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                <LogOut className="h-5 w-5" />
                                <span>Log out</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                {/*<NavFooter items={footerNavItems} className="mt-auto" />*/}

                {/*<NavUser />*/}
            </SidebarFooter>
        </Sidebar>
    );
}
