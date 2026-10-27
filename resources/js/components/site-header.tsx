import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { home, login, register } from '@/routes';
import { index as moviesIndex } from '@/routes/movies';

export function SiteHeader() {
    const { auth } = usePage().props;

    return (
        <header className="border-b border-border/70 bg-background">
            <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-4 px-4">
                <Link href={home()} className="flex items-center" prefetch>
                    <AppLogo />
                </Link>

                <nav
                    aria-label="Main navigation"
                    className="flex items-center gap-2"
                >
                    {auth.user ? (
                        <Button asChild>
                            <Link href={moviesIndex()} prefetch>
                                Movie library
                            </Link>
                        </Button>
                    ) : (
                        <>
                            <Button variant="ghost" asChild>
                                <Link href={login()}>Log in</Link>
                            </Button>
                            <Button asChild>
                                <Link href={register()}>Register</Link>
                            </Button>
                        </>
                    )}
                </nav>
            </div>
        </header>
    );
}
