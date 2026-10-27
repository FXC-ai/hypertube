import { Head } from '@inertiajs/react';
import { SiteFooter } from '@/components/site-footer';
import { SiteHeader } from '@/components/site-header';

export default function Welcome() {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <Head title="Welcome" />
            <SiteHeader />

            <main className="flex flex-1 items-center">
                <div className="flex-1 rounded-br-lg rounded-bl-lg bg-white p-6 pb-12 text-[13px] leading-[20px] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] lg:rounded-tl-lg lg:rounded-br-none lg:p-20 dark:bg-[#161615] dark:text-[#EDEDEC] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                    <h1 className="mb-1 font-medium">Welcome to Hypertube</h1>
                    <p className="mb-2 text-[#706f6c] dark:text-[#A1A09A]">
                        You'll discover old forgotten movies.
                        <br />
                        Create a free account, then open the movie library to
                        search by title and start watching.
                    </p>
                </div>
            </main>

            <SiteFooter />
        </div>
    );
}
