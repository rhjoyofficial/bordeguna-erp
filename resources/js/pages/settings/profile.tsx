import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

/**
 * The person's own picture. It is the HR photo on their employee record, so
 * uploading here and HR uploading on the employee page are the same act.
 */
function ProfilePicture({ photoUrl, hasEmployee, name }: { photoUrl: string | null; hasEmployee: boolean; name: string }) {
    const getInitials = useInitials();
    const fileInput = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const upload = (file: File | null) => {
        if (!file) return;
        const form = new FormData();
        form.append('photo', file);
        setBusy(true);
        router.post(route('profile.photo.store'), form, {
            preserveScroll: true,
            forceFormData: true,
            onError: (errors) => setError(errors.photo),
            onSuccess: () => setError(undefined),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="grid gap-2">
            <Label>Picture</Label>
            <div className="flex items-center gap-4">
                <Avatar className="h-20 w-20 overflow-hidden rounded-full">
                    {photoUrl ? <AvatarImage src={photoUrl} alt={name} /> : null}
                    <AvatarFallback className="bg-primary/10 text-primary text-xl">{getInitials(name)}</AvatarFallback>
                </Avatar>
                {hasEmployee ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="hidden"
                            onChange={(e) => upload(e.target.files?.[0] ?? null)}
                        />
                        <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => fileInput.current?.click()}>
                            {photoUrl ? 'Replace' : 'Upload'}
                        </Button>
                        {photoUrl && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={busy}
                                onClick={() => router.delete(route('profile.photo.destroy'), { preserveScroll: true })}
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        No employee record is linked to this login, so there is nowhere to keep a picture yet. Ask HR to link one.
                    </p>
                )}
            </div>
            <InputError message={error} />
        </div>
    );
}

export default function Profile({
    mustVerifyEmail,
    status,
    hasEmployee,
    photo_url,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    hasEmployee: boolean;
    photo_url: string | null;
}) {
    const { auth } = usePage<SharedData>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: auth.user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />

            <SettingsLayout narrow>
                <div className="space-y-6">
                    <HeadingSmall title="Profile information" />

                    <ProfilePicture photoUrl={photo_url} hasEmployee={hasEmployee} name={auth.user.name} />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name" required>Name</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email" required>Email address</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder="Email address"
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div>
                                <p className="mt-2 text-sm text-neutral-800">
                                    Your email address is unverified.
                                    <Link
                                        href={route('verification.send')}
                                        method="post"
                                        as="button"
                                        className="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        Click here to re-send the verification email.
                                    </Link>
                                </p>

                                {status === 'verification-link-sent' && (
                                    <div className="mt-2 text-sm font-medium text-green-600">
                                        A new verification link has been sent to your email address.
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
