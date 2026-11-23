import { AppIcon } from '@/components/common/app-icon';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Loader2, Lock, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

interface LoginForm {
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Sign In" description="Authenticate to access your Yeastar PBX and Bitrix24 workspace">
            <Head title="Log In" />

            {status && (
                <div className="mb-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-center text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    {status}
                </div>
            )}

            <form className="flex flex-col gap-5" onSubmit={submit}>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="email" className="text-xs font-medium">Email Address</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Mail} size="xs" />
                            </div>
                            <Input
                                id="email"
                                type="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="name@company.com"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center justify-between">
                            <Label htmlFor="password" className="text-xs font-medium">Password</Label>
                            {canResetPassword && (
                                <TextLink href={route('password.request')} className="text-xs" tabIndex={5}>
                                    Forgot password?
                                </TextLink>
                            )}
                        </div>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Lock} size="xs" />
                            </div>
                            <Input
                                id="password"
                                type="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Enter your password"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center space-x-2 pt-1">
                        <Checkbox
                            id="remember"
                            name="remember"
                            tabIndex={3}
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                        />
                        <Label htmlFor="remember" className="text-xs text-muted-foreground font-normal cursor-pointer">
                            Keep me signed in on this device
                        </Label>
                    </div>

                    <Button type="submit" className="mt-2 w-full h-10 gap-2 font-medium" tabIndex={4} disabled={processing}>
                        {processing ? (
                            <AppIcon icon={Loader2} size="sm" className="animate-spin" />
                        ) : (
                            <>
                                <span>Sign In</span>
                                <AppIcon icon={ArrowRight} size="xs" />
                            </>
                        )}
                    </Button>
                </div>

                <div className="text-center text-xs text-muted-foreground">
                    Need an account?{' '}
                    <TextLink href={route('register')} tabIndex={5} className="font-semibold text-foreground">
                        Register workspace
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
