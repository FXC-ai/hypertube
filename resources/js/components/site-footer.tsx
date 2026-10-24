import { Link } from '@inertiajs/react';
import { home } from '@/routes';

export function SiteFooter() {
    return (
        <footer className="mt-auto border-t border-border/70 bg-background">
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-2 px-4 py-6 text-center text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:text-left">
                <p>
                    &copy; {new Date().getFullYear()} Hypertube. Discover and
                    stream legally distributed movies.
                </p>

                <Link
                    href={home()}
                    className="font-medium text-foreground underline-offset-4 hover:underline"
                >
                    Home
                </Link>
            </div>
        </footer>
    );
}
