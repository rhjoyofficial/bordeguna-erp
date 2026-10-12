// Components
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Confirm secure access" description="For sensitive actions, re-enter your password before we continue.">
            <Head title="Confirm password" />

            <form onSubmit={submit}>
                <div className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="password" className="text-sm font-medium" required>
                            Password
                        </Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Password"
                            autoComplete="current-password"
                            required
                            value={data.password}
                            autoFocus
                            onChange={(e) => setData('password', e.target.value)}
                            className="bg-background/80 h-12 rounded-xl"
                        />

                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center">
                        <Button size="lg" className="h-12 w-full rounded-xl text-sm font-semibold" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Confirm password
                        </Button>
                    </div>

                    <p className="text-muted-foreground text-center text-sm">
                        Need a different account?{' '}
                        <TextLink href={route('logout')} method="post" className="text-primary font-medium no-underline hover:underline">
                            Log out
                        </TextLink>
                    </p>
                </div>
            </form>
        </AuthLayout>
    );
}
