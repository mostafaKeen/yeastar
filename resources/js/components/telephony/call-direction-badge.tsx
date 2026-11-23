import { AppIcon } from '@/components/common/app-icon';
import { Badge } from '@/components/ui/badge';
import { type CallDirectionValue } from '@/types';
import { PhoneCall, PhoneIncoming, PhoneMissed, PhoneOutgoing, type LucideIcon } from 'lucide-react';

interface DirectionMeta {
    label: string;
    icon: LucideIcon;
    className: string;
}

const DIRECTION_MAP: Record<CallDirectionValue, DirectionMeta> = {
    inbound: {
        label: 'Inbound',
        icon: PhoneIncoming,
        className: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    outbound: {
        label: 'Outbound',
        icon: PhoneOutgoing,
        className: 'border-indigo-500/30 bg-indigo-500/10 text-indigo-700 dark:text-indigo-400',
    },
    internal: {
        label: 'Internal',
        icon: PhoneCall,
        className: 'border-neutral-400/30 bg-neutral-500/10 text-neutral-700 dark:text-neutral-300',
    },
    callback: {
        label: 'Callback',
        icon: PhoneCall,
        className: 'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-400',
    },
};

interface CallDirectionBadgeProps {
    direction: CallDirectionValue;
    statusCode?: string;
}

export function CallDirectionBadge({ direction, statusCode }: CallDirectionBadgeProps) {
    if (statusCode === '304') {
        return (
            <Badge
                variant="outline"
                className="inline-flex items-center gap-1.5 border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400"
            >
                <AppIcon icon={PhoneMissed} size="xs" />
                <span>Missed</span>
            </Badge>
        );
    }

    const meta = DIRECTION_MAP[direction] ?? DIRECTION_MAP.inbound;

    return (
        <Badge variant="outline" className={`inline-flex items-center gap-1.5 ${meta.className}`}>
            <AppIcon icon={meta.icon} size="xs" />
            <span>{meta.label}</span>
        </Badge>
    );
}
