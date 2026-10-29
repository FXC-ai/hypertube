import { Head } from '@inertiajs/react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { show } from '@/routes/users';

type User = {
    id: number;
    username: string;
    firstname: string;
    lastname: string;
    preferredlanguage: string;
    profilepicture: string | null;
    created_at: string;
};

type UserShowProps = {
    user: {
        data: User;
    };
};

function formatRegistrationDate(date: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function formatLanguage(language: string): string {
    const languageNames: Record<string, string> = {
        en: 'English',
        english: 'English',
        fr: 'French',
        french: 'French',
    };

    return languageNames[language.toLowerCase()] ?? language;
}

export default function UserShow({ user }: UserShowProps) {
    const getInitials = useInitials();
    const profile = user.data;

    return (
        <>
            <Head title={`${profile.username} — User profile`} />

            <main className="flex w-full flex-1 flex-col gap-8 p-4 sm:flex-row sm:items-start sm:p-6 lg:gap-12 lg:p-8">
                <Avatar className="size-48 shrink-0 rounded-xl sm:size-56 lg:size-64">
                    <AvatarImage
                        src={
                            profile.profilepicture
                                ? `/storage/${profile.profilepicture}`
                                : undefined
                        }
                        alt={`Profile picture of ${profile.username}`}
                        className="object-cover"
                    />
                    <AvatarFallback className="rounded-xl bg-muted text-4xl font-semibold">
                        {getInitials(profile.username)}
                    </AvatarFallback>
                </Avatar>

                <div className="min-w-0 flex-1">
                    <dl className="grid grid-cols-[10rem_minmax(0,1fr)] items-baseline gap-x-6 gap-y-4 text-base">
                        <div className="contents">
                            <dt className="font-medium">Username:</dt>
                            <dd className="truncate text-2xl font-semibold tracking-tight">
                                {profile.username}
                            </dd>
                        </div>

                        <div className="contents">
                            <dt className="font-medium">First Name:</dt>
                            <dd className="min-w-0 text-muted-foreground">
                                {profile.firstname || 'Not provided'}
                            </dd>
                        </div>

                        <div className="contents">
                            <dt className="font-medium">Last Name:</dt>
                            <dd className="min-w-0 text-muted-foreground">
                                {profile.lastname || 'Not provided'}
                            </dd>
                        </div>

                        <div className="contents">
                            <dt className="font-medium">Preferred language:</dt>
                            <dd className="min-w-0 text-muted-foreground">
                                {profile.preferredlanguage
                                    ? formatLanguage(profile.preferredlanguage)
                                    : 'Not provided'}
                            </dd>
                        </div>

                        <div className="contents">
                            <dt className="font-medium">Member since:</dt>
                            <dd className="min-w-0 text-muted-foreground">
                                <time dateTime={profile.created_at}>
                                    {formatRegistrationDate(profile.created_at)}
                                </time>
                            </dd>
                        </div>
                    </dl>
                </div>
            </main>
        </>
    );
}

UserShow.layout = ({ user }: UserShowProps) => ({
    breadcrumbs: [
        {
            title: 'User profile',
            href: show(user.data.id),
        },
    ],
});
