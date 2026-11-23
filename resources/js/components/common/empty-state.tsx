import { AppIcon } from '@/components/common/app-icon';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    icon: LucideIcon;
    title: string;
    description: string;
    action?: ReactNode;
    className?: string;
}

export function EmptyState({ icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-10 text-center',
                className,
            )}
        >
            <div className="bg-muted text-muted-foreground mb-3 flex size-11 items-center justify-center rounded-full">
                <AppIcon icon={icon} size="md" />
            </div>
            <h3 className="text-sm font-semibold">{title}</h3>
            <p className="text-muted-foreground mt-1 max-w-md text-xs leading-relaxed">{description}</p>
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}
