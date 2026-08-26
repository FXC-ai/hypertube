


import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useInitials } from '@/hooks/use-initials';
import { show } from '@/routes/users';
import { Head } from '@inertiajs/react';
import { CalendarDays, Languages, UserRound } from 'lucide-react';

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
    return new Intl.DateTimeFormat('fr-FR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function formatLanguage(language: string): string {
    const languageNames: Record<string, string> = {
        en: 'Anglais',
        english: 'Anglais',
        fr: 'Français',
        french: 'Français',
    };

    return languageNames[language.toLowerCase()] ?? language;
}

export default function UserShow({ user }: UserShowProps) {
    const getInitials = useInitials();
    const profile = user.data;

    const fullName = [profile.firstname, profile.lastname]
        .filter(Boolean)
        .join(' ');

    return (
        <>
            <Head title={`${profile.username} — Profil`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col p-4 md:p-6">
                <Card className="gap-0 overflow-hidden py-0">
                    <div className="h-28 bg-gradient-to-r from-primary/25 via-primary/10 to-transparent" />

                    <CardHeader className="-mt-14 gap-5 px-6 pb-6 sm:flex-row sm:items-end sm:px-8">
                        <Avatar className="size-28 shrink-0 rounded-2xl border-4 border-card shadow-md">
                            <AvatarImage
                                src={
                                    profile.profilepicture
                                        ? `/storage/${profile.profilepicture}`
                                        : undefined
                                }
                                alt={`Photo de profil de ${profile.username}`}
                                className="object-cover"
                            />

                            <AvatarFallback className="rounded-xl bg-muted text-2xl font-semibold">
                                {getInitials(profile.username)}
                            </AvatarFallback>
                        </Avatar>

                        <div className="min-w-0 flex-1 space-y-1 pb-1">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0">
                                    <CardTitle className="truncate text-2xl">
                                        {fullName || profile.username}
                                    </CardTitle>

                                    <CardDescription className="mt-1 truncate text-base">
                                        @{profile.username}
                                    </CardDescription>
                                </div>

                                {profile.preferredlanguage && (
                                    <Badge
                                        variant="secondary"
                                        className="shrink-0 gap-1.5"
                                    >
                                        <Languages aria-hidden="true" />
                                        {formatLanguage(
                                            profile.preferredlanguage,
                                        )}
                                    </Badge>
                                )}
                            </div>

                            <p className="flex items-center gap-2 pt-2 text-sm text-muted-foreground">
                                <CalendarDays
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Membre depuis le{' '}
                                {formatRegistrationDate(profile.created_at)}
                            </p>
                        </div>
                    </CardHeader>

                    <Separator />

                    <CardContent className="px-6 py-6 sm:px-8">
                        <div className="mb-6">
                            <h2 className="text-base font-semibold">
                                Informations
                            </h2>

                            <p className="mt-1 text-sm text-muted-foreground">
                                Informations publiques du profil.
                            </p>
                        </div>

                        <dl className="grid gap-4 sm:grid-cols-2">
                            <div className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4">
                                <div className="rounded-lg bg-background p-2 shadow-sm">
                                    <UserRound
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </div>

                                <div className="min-w-0">
                                    <dt className="text-sm text-muted-foreground">
                                        Nom complet
                                    </dt>

                                    <dd className="mt-1 truncate font-medium">
                                        {fullName || 'Non renseigné'}
                                    </dd>
                                </div>
                            </div>

                            <div className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4">
                                <div className="rounded-lg bg-background p-2 shadow-sm">
                                    <Languages
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </div>

                                <div className="min-w-0">
                                    <dt className="text-sm text-muted-foreground">
                                        Langue préférée
                                    </dt>

                                    <dd className="mt-1 font-medium">
                                        {profile.preferredlanguage
                                            ? formatLanguage(
                                                profile.preferredlanguage,
                                            )
                                            : 'Non renseignée'}
                                    </dd>
                                </div>
                            </div>

                            <div className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4 sm:col-span-2">
                                <div className="rounded-lg bg-background p-2 shadow-sm">
                                    <CalendarDays
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </div>

                                <div className="min-w-0">
                                    <dt className="text-sm text-muted-foreground">
                                        Date d’inscription
                                    </dt>

                                    <dd className="mt-1 font-medium">
                                        <time dateTime={profile.created_at}>
                                            {formatRegistrationDate(
                                                profile.created_at,
                                            )}
                                        </time>
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

UserShow.layout = ({ user }: UserShowProps) => ({
    breadcrumbs: [
        {
            title: 'Utilisateurs',
            href: '/users',
        },
        {
            title: user.data.username,
            href: show(user.data.id),
        },
    ],
});
