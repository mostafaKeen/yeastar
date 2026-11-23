import { AppIcon } from '@/components/common/app-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Loader2, Lock } from 'lucide-react';
import { FormEventHandler } from 'react';

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
        <AuthLayout
            title="Confirm Security Password"
            description="You are entering a protected section of the platform. Please confirm your password to proceed."
        >
            <Head title="Confirm Password" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="password" className="text-xs font-medium">Password</Label>
                    <div className="relative">
                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                            <AppIcon icon={Lock} size="xs" />
                        </div>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Current account password"
                            autoComplete="current-password"
                            value={data.password}
                            autoFocus
                            onChange={(e) => setData('password', e.target.value)}
                            className="pl-9 h-10"
                        />
                    </div>
                    <InputError message={errors.password} />
                </div>

                <Button type="submit" className="w-full h-10 gap-2 font-medium" disabled={processing}>
                    {processing ? (
                        <AppIcon icon={Loader2} size="sm" className="animate-spin" />
                    ) : (
                        <>
                            <span>Confirm & Continue</span>
                            <AppIcon icon={ArrowRight} size="xs" />
                        </>
                    )}
                </Button>
            </form>
        </AuthLayout>
    );
}
