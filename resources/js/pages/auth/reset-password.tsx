import { AppIcon } from '@/components/common/app-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Loader2, Lock } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ResetPasswordProps {
    token: string;
    email: string;
}

interface ResetPasswordForm {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ResetPasswordForm>({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Choose New Password" description="Enter your updated credentials to regain access to your workspace">
            <Head title="Reset Password" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="email" className="text-xs font-medium">Email Address</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="email"
                            value={data.email}
                            readOnly
                            className="bg-muted text-muted-foreground h-10"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password" className="text-xs font-medium">New Password</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Lock} size="xs" />
                            </div>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                autoComplete="new-password"
                                value={data.password}
                                autoFocus
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Enter new password"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation" className="text-xs font-medium">Confirm New Password</Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <AppIcon icon={Lock} size="xs" />
                            </div>
                            <Input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="Re-enter new password"
                                className="pl-9 h-10"
                            />
                        </div>
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button type="submit" className="mt-2 w-full h-10 gap-2 font-medium" disabled={processing}>
                        {processing ? (
                            <AppIcon icon={Loader2} size="sm" className="animate-spin" />
                        ) : (
                            <>
                                <span>Save New Password</span>
                                <AppIcon icon={ArrowRight} size="xs" />
                            </>
                        )}
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
