import { Head, Link, usePage } from '@inertiajs/react';
import { SiteFooter } from '@/components/site-footer';
import { SiteHeader } from '@/components/site-header';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import { index as moviesIndex } from '@/routes/movies';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <Head title="Welcome" />
            <SiteHeader />

            <main className="flex flex-1 items-center">
                <div className="flex-1 rounded-br-lg rounded-bl-lg bg-white p-6 pb-12 text-[13px] leading-[20px] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] lg:rounded-tl-lg lg:rounded-br-none lg:p-20 dark:bg-[#161615] dark:text-[#EDEDEC] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                    <h1 className="mb-1 font-medium">Welcome to Hypertube</h1>
                    <p className="mb-2 text-[#706f6c] dark:text-[#A1A09A]">
                        Discover royalty-free movies from the public-domain
                        catalog. Search by title, then open a video to start
                        streaming it.
                    </p>

                    {auth.user ? (
                        <Button asChild>
                            <Link href={moviesIndex()}>Open the library</Link>
                        </Button>
                    ) : (
                        <Button asChild>
                            <Link href={register()}>Create an account</Link>
                        </Button>
                    )}
                </div>
            </main>

            <SiteFooter />
        </div>
    );
}
