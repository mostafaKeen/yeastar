import { AppIcon } from '@/components/common/app-icon';
import { RoleBadge } from '@/components/tenant/role-badge';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { type ReactNode } from 'react';

interface BitrixIframeLayoutProps {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}

/**
 * Compact, zero-overflow layout designed for Bitrix24 Marketplace embedded slider iframes (>= 1280px).
 */
export default function BitrixIframeLayout({ title, actions, children }: BitrixIframeLayoutProps) {
    const { tenancy } = usePage<SharedData>().props;
    const active = tenancy?.active;

    return (
        <div className="bg-background text-foreground flex min-h-screen w-full max-w-full flex-col overflow-x-hidden">
            <header className="border-border flex items-center justify-between gap-4 border-b px-5 py-3">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="bg-primary text-primary-foreground flex size-8 items-center justify-center rounded-md">
                        <AppIcon icon={Building2} size="sm" />
                    </div>
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">{title}</h1>
                        {active ? (
                            <div className="flex items-center gap-2 text-xs">
                                <span className="text-muted-foreground truncate">{active.name}</span>
                                <RoleBadge role={active.role} />
                            </div>
                        ) : null}
                    </div>
                </div>
                {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
            </header>
            <main className="mx-auto w-full max-w-7xl flex-1 p-5">{children}</main>
        </div>
    );
}
