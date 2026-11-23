import { AppIcon } from '@/components/common/app-icon';
import { Badge } from '@/components/ui/badge';
import { type TenantRoleValue } from '@/types';
import { Eye, Headphones, ShieldCheck, UserCog, type LucideIcon } from 'lucide-react';

const ROLE_META: Record<TenantRoleValue, { label: string; icon: LucideIcon }> = {
    owner: { label: 'Owner', icon: ShieldCheck },
    admin: { label: 'Admin', icon: UserCog },
    supervisor: { label: 'Supervisor', icon: Eye },
    agent: { label: 'Agent', icon: Headphones },
};

interface RoleBadgeProps {
    role: TenantRoleValue | null | undefined;
}

export function RoleBadge({ role }: RoleBadgeProps) {
    if (!role || !(role in ROLE_META)) {
        return null;
    }

    const meta = ROLE_META[role];

    return (
        <Badge variant="secondary" className="inline-flex items-center gap-1 text-xs font-medium">
            <AppIcon icon={meta.icon} size="xs" />
            <span>{meta.label}</span>
        </Badge>
    );
}
