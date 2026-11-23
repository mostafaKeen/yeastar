import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

export type AppIconSize = 'xs' | 'sm' | 'md' | 'lg';

const SIZE_CLASSES: Record<AppIconSize, string> = {
    xs: 'size-3.5',
    sm: 'size-4',
    md: 'size-5',
    lg: 'size-6',
};

interface AppIconProps {
    icon: LucideIcon;
    size?: AppIconSize;
    className?: string;
    strokeWidth?: number;
}

export function AppIcon({ icon: Icon, size = 'sm', className, strokeWidth = 1.75 }: AppIconProps) {
    return <Icon className={cn(SIZE_CLASSES[size], 'shrink-0', className)} strokeWidth={strokeWidth} aria-hidden="true" />;
}
