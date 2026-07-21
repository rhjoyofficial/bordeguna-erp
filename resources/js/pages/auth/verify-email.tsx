// Components
import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <AuthLayout title="Verify your email" description="Check your inbox and confirm your email address to unlock the full workspace.">
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="border-primary/20 bg-primary/8 text-primary mb-6 flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm font-medium">
                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>A fresh verification link has been sent to the email address you used during registration.</span>
                </div>
            )}

            <div className="border-border/70 bg-background/70 mb-6 rounded-2xl border p-5">
                <div className="bg-primary/10 text-primary mb-3 flex h-11 w-11 items-center justify-center rounded-2xl">
                    <Mail className="h-5 w-5" />
                </div>
                <p className="text-muted-foreground text-sm leading-6">
                    Open the verification email we sent you, then return here. If it does not arrive, you can request another message below.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-6 text-center">
                <Button disabled={processing} size="lg" className="h-12 w-full rounded-xl text-sm font-semibold">
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Resend verification email
                </Button>

                <TextLink
                    href={route('logout')}
                    method="post"
                    className="text-primary mx-auto block text-sm font-medium no-underline hover:underline"
                >
                    Log out
                </TextLink>
            </form>
        </AuthLayout>
    );
}
