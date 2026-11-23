import { AppIcon } from '@/components/common/app-icon';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type ConnectionStatusValue } from '@/types';
import { AlertTriangle, CheckCircle2, Clock, Radio, XCircle, type LucideIcon } from 'lucide-react';

interface StatusMeta {
    label: string;
    icon: LucideIcon;
    className: string;
}

const STATUS_MAP: Record<ConnectionStatusValue | 'online' | 'offline', StatusMeta> = {
    connected: {
        label: 'Connected',
        icon: CheckCircle2,
        className: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    online: {
        label: 'Online',
        icon: Radio,
        className: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    pending: {
        label: 'Pending Setup',
        icon: Clock,
        className: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    degraded: {
        label: 'Degraded',
        icon: AlertTriangle,
        className: 'border-orange-500/30 bg-orange-500/10 text-orange-700 dark:text-orange-400',
    },
    disconnected: {
        label: 'Disconnected',
        icon: XCircle,
        className: 'border-neutral-400/30 bg-neutral-500/10 text-neutral-700 dark:text-neutral-300',
    },
    offline: {
        label: 'Offline',
        icon: XCircle,
        className: 'border-neutral-400/30 bg-neutral-500/10 text-neutral-700 dark:text-neutral-300',
    },
    revoked: {
        label: 'Revoked',
        icon: XCircle,
        className: 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400',
    },
};

interface StatusIndicatorProps {
    status: ConnectionStatusValue | 'online' | 'offline' | string;
    label?: string;
    className?: string;
}

export function StatusIndicator({ status, label, className }: StatusIndicatorProps) {
    const meta = STATUS_MAP[status as keyof typeof STATUS_MAP] ?? STATUS_MAP.disconnected;

    return (
        <Badge variant="outline" className={cn('inline-flex items-center gap-1.5 font-medium', meta.className, className)}>
            <AppIcon icon={meta.icon} size="xs" />
            <span>{label ?? meta.label}</span>
        </Badge>
    );
}
