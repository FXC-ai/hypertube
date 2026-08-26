import { useInitials } from '@/hooks/use-initials';
import { index } from '@/routes/users';
import { Form, Head, InfiniteScroll, Link } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import Heading from '@/components/heading';
import UserController from '@/actions/App/Http/Controllers/UserController'

import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';



type User = {
    id: number;
    username: string;
    profilepicture: string | null;
    created_at: string;
};

type UserIndexProps = {
    users: {
        data: User[];
    };
    filters: {
        search: string | null;
    };
};

function formatRegistrationDate(date: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function UserItem({ user }: { user: User }) {
    const getInitials = useInitials();

    return (
        <Link
            href={`/users/${user.id}`}
            className="flex items-center gap-4 border-b p-4 transition-colors last:border-b-0 hover:bg-muted/50"
        >
            <Avatar className="size-10">
                <AvatarImage
                    src={
                        user.profilepicture
                            ? `/storage/${user.profilepicture}`
                            : undefined
                    }
                    alt={user.username}
                    className="object-cover"
                />

                <AvatarFallback>
                    {getInitials(user.username)}
                </AvatarFallback>
            </Avatar>

            <span className="min-w-0 flex-1 truncate font-medium">
                {user.username}
            </span>

            <time
                dateTime={user.created_at}
                className="shrink-0 text-sm text-muted-foreground"
            >
                {formatRegistrationDate(user.created_at)}
            </time>

        </Link>
    );
}

export default function UsersIndex({ users, filters }: UserIndexProps) {

    return (
        <>

            <Head title="Users" />

            <div className='flex h-full flex-1 flex-col gap-6 p-4'>

                <div className="space-y-4">
                    <Heading variant="small" title="Search" description="Search for a user by username" />

                    <Form
                        {...UserController.index.form()}
                        className="flex flex-col gap-2 sm:flex-row sm:items-start"
                        options={{
                            preserveScroll: true,
                            only: ['users'],
                            reset: ['users'],
                            replace: true,
                        }}
                    >

                        {
                            ({ processing, errors }) => (
                                <>
                                    <div className="min-w-0 flex-1">

                                        <Label htmlFor="search" className="sr-only">
                                            Username
                                        </Label>
                                        <Input
                                            id="search"
                                            type="search"
                                            name="search"
                                            placeholder="Search by username..."
                                            aria-invalid={Boolean(errors.search)}
                                            autoComplete='off'
                                            maxLength={100}
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={errors.search}
                                        />

                                    </div>


                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:shrink-0"
                                    >
                                        {processing ? 'Searching…' : 'Search'}
                                    </Button>
                                </>
                            )
                        }

                    </Form>
                    <Button>
                        Clear
                    </Button>

                </div>


                {
                    users.data.length > 0 ?
                        (
                            <InfiniteScroll data="users" buffer={300} onlyNext>

                                {
                                    users.data.map((user) => (<UserItem key={user.id} user={user}></UserItem>))
                                }

                            </InfiniteScroll>
                        ) : (
                            filters != null ?
                                (

                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        {`No user found for username ${filters.search}.`}
                                    </div>

                                ) :
                                (
                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        No user found.
                                    </div>
                                )
                        )
                }
            </div>

        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: index(),
        },
    ],
};