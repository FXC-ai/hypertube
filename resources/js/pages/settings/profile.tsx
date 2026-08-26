import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import ProfilepictureController from '@/actions/App/Http/Controllers/Settings/ProfilePictureController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import ProfilePictureController from '@/actions/App/Http/Controllers/Settings/ProfilePictureController';


type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const getInitials = useInitials();
    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your profile"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>

                                <Input
                                    id="username"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.username}
                                    name="username"
                                    required
                                    autoComplete="username"
                                    placeholder="Username"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.username}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="firstname">First Name</Label>

                                <Input
                                    id="firstname"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.firstname}
                                    name="firstname"
                                    required
                                    autoComplete="First Name"
                                    placeholder="First Name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.firstname}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="lastname">Last Name</Label>

                                <Input
                                    id="lastname"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.lastname}
                                    name="lastname"
                                    required
                                    autoComplete="Last Name"
                                    placeholder="Last Name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.lastname}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="preferredlanguage">Preferred Language</Label>

                                <Select
                                    name="preferredlanguage"
                                    defaultValue={auth.user.preferredlanguage}
                                    required
                                >
                                    <SelectTrigger
                                        id="preferredlanguage"
                                        className='mt-1 w-full'
                                        aria-invalid={Boolean(errors.preferredlanguage)}
                                    >
                                        <SelectValue placeholder="Select a language" />
                                    </SelectTrigger>

                                    <SelectContent>
                                        <SelectItem value="english">English</SelectItem>
                                        <SelectItem value="french">French</SelectItem>
                                        <SelectItem value="german">German</SelectItem>
                                        <SelectItem value="spanish">Spanish</SelectItem>
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.preferredlanguage}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                                <div className="mt-2 text-sm font-medium text-green-600">
                                                    A new verification link has been
                                                    sent to your email address.
                                                </div>
                                            )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <Form
                    {...ProfilePictureController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Profile picture</Label>
                                <Avatar className="h-40 w-64 overflow-hidden rounded-xl">
                                    <AvatarImage
                                        src={auth.user.profilepicture ? `/storage/${auth.user.profilepicture}` : undefined}
                                        alt={auth.user.username}
                                        className="h-full w-full object-contain"
                                    />
                                    <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                        {getInitials(auth.user.username)}
                                    </AvatarFallback>
                                </Avatar>
                                <Input
                                    id="profilepicture"
                                    type="file"
                                    className="mt-1 block w-full"
                                    name="profilepicture"
                                    required
                                    autoComplete="Profile Picture"
                                    placeholder="Profile Picture"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.profilepicture}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
