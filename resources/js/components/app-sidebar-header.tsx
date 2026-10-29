import { Link, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { logout } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            {/* On a phone the sidebar, and its "Log out", is folded away: keep it one click away. */}
            <Button
                variant="ghost"
                size="sm"
                className="ml-auto md:hidden"
                asChild
            >
                <Link
                    href={logout()}
                    as="button"
                    onClick={() => router.flushAll()}
                    data-test="mobile-logout-button"
                >
                    <LogOut className="h-4 w-4" />
                    Log out
                </Link>
            </Button>
        </header>
    );
}
