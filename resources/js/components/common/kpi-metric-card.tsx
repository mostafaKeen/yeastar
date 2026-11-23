import { AppIcon } from '@/components/common/app-icon';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

interface KpiMetricCardProps {
    title: string;
    value?: string | number;
    description: string;
    icon: LucideIcon;
    loading?: boolean;
    iconClassName?: string;
}

export function KpiMetricCard({ title, value, description, icon, loading = false, iconClassName }: KpiMetricCardProps) {
    return (
        <Card className="transition-colors hover:border-foreground/20">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium">{title}</CardTitle>
                <div className={cn('bg-muted flex size-8 items-center justify-center rounded-md', iconClassName)}>
                    <AppIcon icon={icon} size="sm" />
                </div>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <div className="space-y-2">
                        <Skeleton className="h-7 w-20" />
                        <Skeleton className="h-3.5 w-36" />
                    </div>
                ) : (
                    <>
                        <div className="text-2xl font-semibold tracking-tight tabular-nums">{value ?? 0}</div>
                        <p className="text-muted-foreground mt-1 text-xs">{description}</p>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
