import { AppIcon } from '@/components/common/app-icon';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { Loader2, MailCheck, RotateCcw } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthLayout
            title="Verify Email Address"
            description="A confirmation link was sent to your email. Click it to verify your account and activate your workspace."
        >
            <Head title="Email Verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-center text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    A fresh verification link has been dispatched to your email address.
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-4 text-center">
                <Button type="submit" disabled={processing} className="w-full h-10 gap-2 font-medium">
                    {processing ? (
                        <AppIcon icon={Loader2} size="sm" className="animate-spin" />
                    ) : (
                        <>
                            <AppIcon icon={RotateCcw} size="xs" />
                            <span>Resend Verification Email</span>
                        </>
                    )}
                </Button>

                <div className="flex items-center justify-center gap-2 pt-2 text-xs text-muted-foreground">
                    <AppIcon icon={MailCheck} size="xs" className="text-emerald-500" />
                    <span>Check your spam or junk folder if not received</span>
                </div>

                <div className="pt-2">
                    <TextLink href={route('logout')} method="post" className="text-xs font-semibold">
                        Sign out of account
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
