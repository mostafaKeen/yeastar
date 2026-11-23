import { AppIcon } from '@/components/common/app-icon';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { Cpu, Lock, PhoneCall, ShieldCheck } from 'lucide-react';
import React from 'react';

export default function AuthCardLayout({
    children,
    title,
    description,
}: {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center p-4 sm:p-6 md:p-10 bg-background text-foreground selection:bg-primary selection:text-primary-foreground">
            {/* Ambient Background Glow */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden">
                <div className="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 h-96 w-96 rounded-full bg-primary/10 blur-3xl opacity-50 dark:opacity-20" />
                <div className="absolute bottom-1/4 right-1/4 h-80 w-80 rounded-full bg-blue-500/10 blur-3xl opacity-40 dark:opacity-15" />
            </div>

            <div className="relative z-10 flex w-full max-w-md flex-col gap-6">
                {/* Brand Header */}
                <div className="flex flex-col items-center gap-2 text-center">
                    <Link
                        href={route('home')}
                        className="group flex items-center gap-3 transition-transform hover:scale-105"
                    >
                        <div className="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-md transition-shadow group-hover:shadow-lg">
                            <AppIcon icon={PhoneCall} size="md" />
                        </div>
                        <div className="text-left">
                            <div className="flex items-center gap-2">
                                <span className="text-lg font-bold tracking-tight">Yeastar Bridge</span>
                                <Badge variant="outline" className="border-primary/30 bg-primary/5 text-[10px] text-primary">
                                    Bitrix24
                                </Badge>
                            </div>
                            <p className="text-xs text-muted-foreground">Carrier-Grade Telephony Gateway</p>
                        </div>
                    </Link>
                </div>

                {/* Main Auth Card */}
                <Card className="border-border/70 bg-card/95 shadow-xl backdrop-blur-md rounded-2xl">
                    <CardHeader className="space-y-1.5 px-6 pt-6 pb-2 text-center sm:px-8">
                        {title && <CardTitle className="text-xl font-bold tracking-tight">{title}</CardTitle>}
                        {description && (
                            <CardDescription className="text-xs sm:text-sm text-muted-foreground">
                                {description}
                            </CardDescription>
                        )}
                    </CardHeader>
                    <CardContent className="px-6 py-6 sm:px-8">{children}</CardContent>
                </Card>

                {/* Enterprise Trust Indicators */}
                <div className="flex flex-wrap items-center justify-center gap-4 text-xs text-muted-foreground">
                    <div className="inline-flex items-center gap-1.5">
                        <AppIcon icon={ShieldCheck} size="xs" className="text-emerald-500" />
                        <span>Tenant Isolation</span>
                    </div>
                    <span className="text-muted-foreground/40">•</span>
                    <div className="inline-flex items-center gap-1.5">
                        <AppIcon icon={Cpu} size="xs" className="text-primary" />
                        <span>Zero-Disk Audio</span>
                    </div>
                    <span className="text-muted-foreground/40">•</span>
                    <div className="inline-flex items-center gap-1.5">
                        <AppIcon icon={Lock} size="xs" className="text-amber-500" />
                        <span>OAuth 2.0 PBX</span>
                    </div>
                </div>
            </div>
        </div>
    );
}
