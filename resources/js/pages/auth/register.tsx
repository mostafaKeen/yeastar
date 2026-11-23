import { AppIcon } from '@/components/common/app-icon';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Loader2, Lock, Mail, User } from 'lucide-react';
import { FormEventHandler } from 'react';

interface RegisterForm {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Create Account" description="Set up your tenant workspace and manage telephony integrations">
            <Head title="Register" />

            <form className="flex flex-col gap-4" onSubmit={submit}>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="text-xs font-medium">Full Name</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={User} size="xs" />
                            </div>
                            <Input
                                id="name"
                                type="text"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                disabled={processing}
                                placeholder="Admin or Operator Name"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email" className="text-xs font-medium">Work Email</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Mail} size="xs" />
                            </div>
                            <Input
                                id="email"
                                type="email"
                                required
                                tabIndex={2}
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                disabled={processing}
                                placeholder="name@company.com"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password" className="text-xs font-medium">Password</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Lock} size="xs" />
                            </div>
                            <Input
                                id="password"
                                type="password"
                                required
                                tabIndex={3}
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                disabled={processing}
                                placeholder="Minimum 8 characters"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation" className="text-xs font-medium">Confirm Password</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Lock} size="xs" />
                            </div>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required
                                tabIndex={4}
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                disabled={processing}
                                placeholder="Re-enter password"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button type="submit" className="mt-2 w-full h-10 gap-2 font-medium" tabIndex={5} disabled={processing}>
                        {processing ? (
                            <AppIcon icon={Loader2} size="sm" className="animate-spin" />
                        ) : (
                            <>
                                <span>Create Workspace Account</span>
                                <AppIcon icon={ArrowRight} size="xs" />
                            </>
                        )}
                    </Button>
                </div>

                <div className="text-center text-xs text-muted-foreground pt-1">
                    Already registered?{' '}
                    <TextLink href={route('login')} tabIndex={6} className="font-semibold text-foreground">
                        Sign in
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
