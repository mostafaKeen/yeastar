import { AppIcon } from '@/components/common/app-icon';
import { StatusIndicator } from '@/components/common/status-indicator';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { type ConnectionStatusValue } from '@/types';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface MetadataItem {
    label: string;
    value: string;
}

interface ConnectionHealthCardProps {
    title: string;
    subtitle: string;
    icon: LucideIcon;
    status: ConnectionStatusValue | 'online' | 'offline' | string;
    badges?: string[];
    metadata?: MetadataItem[];
    footer?: ReactNode;
}

export function ConnectionHealthCard({
    title,
    subtitle,
    icon,
    status,
    badges = [],
    metadata = [],
    footer,
}: ConnectionHealthCardProps) {
    return (
        <Card className="flex flex-col justify-between">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="bg-muted flex size-10 items-center justify-center rounded-lg">
                            <AppIcon icon={icon} size="md" />
                        </div>
                        <div className="min-w-0">
                            <CardTitle className="truncate text-base">{title}</CardTitle>
                            <CardDescription className="truncate text-xs">{subtitle}</CardDescription>
                        </div>
                    </div>
                    <StatusIndicator status={status} />
                </div>
            </CardHeader>

            <CardContent className="space-y-3">
                {badges.length > 0 ? (
                    <div className="flex flex-wrap gap-1.5">
                        {badges.map((item) => (
                            <Badge key={item} variant="secondary" className="font-mono text-[11px]">
                                {item}
                            </Badge>
                        ))}
                    </div>
                ) : null}

                {metadata.length > 0 ? (
                    <dl className="grid grid-cols-2 gap-2 rounded-md border p-3 text-xs">
                        {metadata.map((item) => (
                            <div key={item.label} className="min-w-0">
                                <dt className="text-muted-foreground truncate">{item.label}</dt>
                                <dd className="mt-0.5 truncate font-medium">{item.value}</dd>
                            </div>
                        ))}
                    </dl>
                ) : null}

                {footer ? <div className="pt-1">{footer}</div> : null}
            </CardContent>
        </Card>
    );
}
