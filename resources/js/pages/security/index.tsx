import { AppIcon } from '@/components/common/app-icon';
import { EmptyState } from '@/components/common/empty-state';
import { FlashBanner } from '@/components/common/flash-banner';
import { StatusIndicator } from '@/components/common/status-indicator';
import { RoleBadge } from '@/components/tenant/role-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type TenantRoleValue } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, KeyRound, Lock, Save, ShieldCheck, Trash2, UserPlus, Users } from 'lucide-react';
import { type FormEvent, useState } from 'react';

interface MemberRow {
    id: number;
    name: string;
    email: string;
    role: TenantRoleValue;
}

interface YeastarSecurityRow {
    id: string;
    name: string;
    transport_mode: string;
    agent_token_prefix: string | null;
    agent_status: string;
    allowed_ips: string[];
    status: string;
}

interface ComplianceCheck {
    key: string;
    label: string;
    passed: boolean;
    detail: string;
}

interface SecurityPageProps {
    members: MemberRow[];
    yeastarConnections: YeastarSecurityRow[];
    complianceChecks: ComplianceCheck[];
    callerRole: TenantRoleValue;
    currentUserId: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Operations Dashboard', href: '/dashboard' },
    { title: 'Security & RBAC', href: '/security' },
];

export default function SecurityIndex({
    members,
    yeastarConnections,
    complianceChecks,
    callerRole,
    currentUserId,
}: SecurityPageProps) {
    const inviteForm = useForm<{ name: string; email: string; role: TenantRoleValue }>({
        name: '',
        email: '',
        role: 'supervisor',
    });

    const [ipDrafts, setIpDrafts] = useState<Record<string, string>>(() =>
        Object.fromEntries(yeastarConnections.map((c) => [c.id, c.allowed_ips.join(', ')])),
    );

    const handleInvite = (event: FormEvent) => {
        event.preventDefault();
        inviteForm.post(route('security.members.store'), {
            preserveScroll: true,
            onSuccess: () => inviteForm.reset('name', 'email'),
        });
    };

    const handleRoleChange = (memberId: number, role: string) => {
        router.patch(route('security.members.update', { member: memberId }), { role }, { preserveScroll: true });
    };

    const handleSaveIps = (connectionId: string) => {
        router.patch(
            route('security.yeastar.ip-allowlist', { connection: connectionId }),
            { allowed_ips: ipDrafts[connectionId] ?? '' },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Security & RBAC" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Security, RBAC &amp; Marketplace Compliance</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Manage tenant role permissions, PBX webhook IP allowlists, On-Premise Agent token rotation, and Bitrix24 Market readiness.
                    </p>
                </div>

                <FlashBanner />

                <section className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={Users} size="sm" />
                                <span>Workspace Members ({members.length})</span>
                            </CardTitle>
                            <CardDescription>
                                Enforce least-privilege access across Owner, Admin, Supervisor, and Agent roles.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <form onSubmit={handleInvite} className="bg-muted/30 grid gap-2.5 rounded-lg border p-3.5 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label htmlFor="invite-name" className="text-xs">Name</Label>
                                    <Input
                                        id="invite-name"
                                        value={inviteForm.data.name}
                                        onChange={(e) => inviteForm.setData('name', e.target.value)}
                                        placeholder="Jordan Lee"
                                        required
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="invite-email" className="text-xs">Work Email</Label>
                                    <Input
                                        id="invite-email"
                                        type="email"
                                        value={inviteForm.data.email}
                                        onChange={(e) => inviteForm.setData('email', e.target.value)}
                                        placeholder="jordan@company.com"
                                        required
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="invite-role" className="text-xs">Role</Label>
                                    <Select
                                        value={inviteForm.data.role}
                                        onValueChange={(value) => inviteForm.setData('role', value as TenantRoleValue)}
                                    >
                                        <SelectTrigger id="invite-role">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {callerRole === 'owner' ? <SelectItem value="owner">Owner</SelectItem> : null}
                                            <SelectItem value="admin">Admin</SelectItem>
                                            <SelectItem value="supervisor">Supervisor</SelectItem>
                                            <SelectItem value="agent">Agent</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="sm:col-span-3">
                                    <Button type="submit" size="sm" disabled={inviteForm.processing}>
                                        <AppIcon icon={UserPlus} size="xs" />
                                        <span>{inviteForm.processing ? 'Adding...' : 'Add Workspace Member'}</span>
                                    </Button>
                                </div>
                            </form>

                            <div className="divide-y rounded-md border">
                                {members.map((member) => (
                                    <div key={member.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2 text-sm font-medium">
                                                <span className="truncate">{member.name}</span>
                                                <RoleBadge role={member.role} />
                                            </div>
                                            <div className="text-muted-foreground truncate text-xs">{member.email}</div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <Select
                                                value={member.role}
                                                onValueChange={(val) => handleRoleChange(member.id, val)}
                                            >
                                                <SelectTrigger className="h-8 w-32 text-xs">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {callerRole === 'owner' ? <SelectItem value="owner">Owner</SelectItem> : null}
                                                    <SelectItem value="admin">Admin</SelectItem>
                                                    <SelectItem value="supervisor">Supervisor</SelectItem>
                                                    <SelectItem value="agent">Agent</SelectItem>
                                                </SelectContent>
                                            </Select>

                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                disabled={member.id === currentUserId}
                                                aria-label="Remove member"
                                                onClick={() =>
                                                    router.delete(route('security.members.destroy', { member: member.id }), {
                                                        preserveScroll: true,
                                                    })
                                                }
                                            >
                                                <AppIcon icon={Trash2} size="xs" className="text-destructive" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={ShieldCheck} size="sm" />
                                <span>Bitrix24 Marketplace &amp; Security Readiness</span>
                            </CardTitle>
                            <CardDescription>
                                Live verification against Bitrix24 Market moderation and security requirements.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2.5">
                            {complianceChecks.map((check) => (
                                <div key={check.key} className="flex items-start gap-3 rounded-lg border p-3">
                                    <div className="mt-0.5">
                                        <AppIcon
                                            icon={check.passed ? CheckCircle2 : AlertTriangle}
                                            size="sm"
                                            className={check.passed ? 'text-emerald-600' : 'text-amber-600'}
                                        />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-sm font-medium">{check.label}</span>
                                            <Badge variant={check.passed ? 'secondary' : 'outline'} className="text-[11px]">
                                                {check.passed ? 'Passed' : 'Action Needed'}
                                            </Badge>
                                        </div>
                                        <p className="text-muted-foreground mt-0.5 text-xs">{check.detail}</p>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </section>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AppIcon icon={Lock} size="sm" />
                            <span>PBX Network IP Allowlists &amp; Tunnel Agent Credentials</span>
                        </CardTitle>
                        <CardDescription>
                            Restrict direct Yeastar webhook ingestion by source IPv4/IPv6 or rotate On-Premise Agent bearer tokens.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {yeastarConnections.length === 0 ? (
                            <EmptyState
                                icon={Lock}
                                title="No Yeastar PBXs Configured"
                                description="Add a Yeastar P-Series connection in Integrations to configure IP restrictions or rotate Tunnel Agent tokens."
                            />
                        ) : (
                            yeastarConnections.map((conn) => (
                                <div key={conn.id} className="space-y-3 rounded-lg border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <div className="text-sm font-semibold">{conn.name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                Mode: {conn.transport_mode}
                                                {conn.agent_token_prefix ? ` · Key Prefix: ${conn.agent_token_prefix}...` : ''}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <StatusIndicator
                                                status={conn.transport_mode === 'tunnel_agent' ? conn.agent_status : conn.status}
                                            />
                                            {conn.transport_mode === 'tunnel_agent' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            route('integrations.yeastar.rotate-token', { connection: conn.id }),
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    <AppIcon icon={KeyRound} size="xs" />
                                                    <span>Rotate Token</span>
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>

                                    {conn.transport_mode === 'direct_https' ? (
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                            <Input
                                                value={ipDrafts[conn.id] ?? ''}
                                                onChange={(e) =>
                                                    setIpDrafts((prev) => ({ ...prev, [conn.id]: e.target.value }))
                                                }
                                                placeholder="Comma-separated allowed PBX IPs (leave blank to allow any verified secret)"
                                                className="font-mono text-xs"
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() => handleSaveIps(conn.id)}
                                            >
                                                <AppIcon icon={Save} size="xs" />
                                                <span>Save Allowlist</span>
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
