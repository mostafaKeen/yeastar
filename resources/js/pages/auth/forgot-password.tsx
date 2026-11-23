import { AppIcon } from '@/components/common/app-icon';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2, Mail, Send } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthLayout title="Reset Password" description="Enter your registered email address to receive password reset instructions">
            <Head title="Forgot Password" />

            {status && (
                <div className="mb-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-center text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="email" className="text-xs font-medium">Email Address</Label>
                    <div className="relative">
                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                            <AppIcon icon={Mail} size="xs" />
                        </div>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="off"
                            value={data.email}
                            autoFocus
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="name@company.com"
                            className="pl-9 h-10"
                        />
                    </div>
                    <InputError message={errors.email} />
                </div>

                <Button type="submit" className="w-full h-10 gap-2 font-medium" disabled={processing}>
                    {processing ? (
                        <AppIcon icon={Loader2} size="sm" className="animate-spin" />
                    ) : (
                        <>
                            <span>Send Recovery Link</span>
                            <AppIcon icon={Send} size="xs" />
                        </>
                    )}
                </Button>

                <div className="text-center text-xs text-muted-foreground pt-1">
                    <TextLink href={route('login')} className="inline-flex items-center gap-1.5 font-medium">
                        <AppIcon icon={ArrowLeft} size="xs" />
                        <span>Return to Sign In</span>
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
