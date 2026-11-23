import { AppIcon } from '@/components/common/app-icon';
import { EmptyState } from '@/components/common/empty-state';
import { KpiMetricCard } from '@/components/common/kpi-metric-card';
import { ConnectionHealthCard } from '@/components/integrations/connection-health-card';
import { CallDirectionBadge } from '@/components/telephony/call-direction-badge';
import { RoleBadge } from '@/components/tenant/role-badge';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type DashboardPageProps, type SharedData } from '@/types';
import { Deferred, Head, usePage } from '@inertiajs/react';
import {
    Activity,
    Blocks,
    Cable,
    FileAudio,
    Gauge,
    PhoneCall,
    PhoneIncoming,
    PhoneMissed,
    PhoneOutgoing,
    Server,
    ShieldCheck,
    Users,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Operations Dashboard',
        href: '/dashboard',
    },
];

function formatDuration(seconds: number): string {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}m ${secs.toString().padStart(2, '0')}s`;
}

export default function Dashboard({ connections, summary, callMetrics, recentCalls }: DashboardPageProps) {
    const { tenancy } = usePage<SharedData>().props;
    const activeTenant = tenancy?.active;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Operations Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <section className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {activeTenant?.name ?? 'Tenant Workspace'}
                            </h1>
                            <RoleBadge role={activeTenant?.role} />
                            <Badge variant="outline" className="inline-flex items-center gap-1.5 text-xs">
                                <AppIcon icon={ShieldCheck} size="xs" />
                                <span>Zero-Disk Recording Stream</span>
                            </Badge>
                        </div>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Isolated Bitrix24 Marketplace telephony bridge and Yeastar P-Series PBX control plane.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant="secondary" className="inline-flex items-center gap-1.5 font-mono text-xs">
                            <AppIcon icon={Gauge} size="xs" />
                            <span>
                                {summary.rate_limit_rps} req/s · Burst {summary.rate_burst_limit}
                            </span>
                        </Badge>
                        <Badge variant="secondary" className="inline-flex items-center gap-1.5 font-mono text-xs">
                            <AppIcon icon={Users} size="xs" />
                            <span>{summary.mapped_extensions} Mapped Extensions</span>
                        </Badge>
                    </div>
                </section>

                <section aria-label="24-hour telephony metrics" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Deferred
                        data="callMetrics"
                        fallback={
                            <>
                                <KpiMetricCard title="Total Calls (24h)" description="Inbound and outbound" icon={PhoneCall} loading />
                                <KpiMetricCard title="Inbound Calls" description="Delivered via Yeastar" icon={PhoneIncoming} loading />
                                <KpiMetricCard title="Outbound Click-to-Call" description="Initiated from Bitrix24 CRM" icon={PhoneOutgoing} loading />
                                <KpiMetricCard title="Missed Calls" description="SIP 304 unanswered" icon={PhoneMissed} loading />
                            </>
                        }
                    >
                        <>
                            <KpiMetricCard
                                title="Total Calls (24h)"
                                value={callMetrics?.total_24h ?? 0}
                                description="Correlated PBX and CRM calls"
                                icon={PhoneCall}
                            />
                            <KpiMetricCard
                                title="Inbound Calls"
                                value={callMetrics?.inbound_24h ?? 0}
                                description="Matched via searchCrmEntities"
                                icon={PhoneIncoming}
                            />
                            <KpiMetricCard
                                title="Outbound Click-to-Call"
                                value={callMetrics?.outbound_24h ?? 0}
                                description="Triggered by ONEXTERNALCALLSTART"
                                icon={PhoneOutgoing}
                            />
                            <KpiMetricCard
                                title="Missed Calls"
                                value={callMetrics?.missed_24h ?? 0}
                                description="Logged with Bitrix24 status 304"
                                icon={PhoneMissed}
                            />
                        </>
                    </Deferred>
                </section>

                <section aria-label="Integration connectivity" className="grid gap-4 lg:grid-cols-2">
                    {connections.bitrix ? (
                        <ConnectionHealthCard
                            title="Bitrix24 Marketplace Portal"
                            subtitle={connections.bitrix.domain}
                            icon={Blocks}
                            status={connections.bitrix.status}
                            badges={connections.bitrix.granted_scopes}
                            metadata={[
                                { label: 'Portal Type', value: connections.bitrix.portal_type === 'on_premise' ? 'On-Premise' : 'Cloud' },
                                { label: 'External Lines', value: String(summary.external_lines) },
                            ]}
                        />
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AppIcon icon={Blocks} size="sm" />
                                    <span>Bitrix24 Marketplace Portal</span>
                                </CardTitle>
                                <CardDescription>OAuth 2.0 application context required for Telephony REST APIs.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <EmptyState
                                    icon={Blocks}
                                    title="No Bitrix24 Portal Connected"
                                    description="Install the Marketplace application on your Bitrix24 Cloud or On-Premise portal to register external lines and bind ONEXTERNALCALLSTART."
                                />
                            </CardContent>
                        </Card>
                    )}

                    {connections.yeastar.length > 0 ? (
                        <div className="space-y-4">
                            {connections.yeastar.map((pbx) => (
                                <ConnectionHealthCard
                                    key={pbx.id}
                                    title={pbx.name}
                                    subtitle={
                                        pbx.transport_mode === 'tunnel_agent'
                                            ? 'On-Premise Outbound WSS Tunnel Agent'
                                            : (pbx.pbx_base_url ?? 'Direct HTTPS FQDN')
                                    }
                                    icon={pbx.transport_mode === 'tunnel_agent' ? Cable : Server}
                                    status={pbx.transport_mode === 'tunnel_agent' ? pbx.agent_status : pbx.status}
                                    badges={[
                                        pbx.transport_mode === 'tunnel_agent' ? 'tunnel_agent' : 'direct_https',
                                        pbx.firmware_version ? `fw ${pbx.firmware_version}` : 'fw pending',
                                    ]}
                                    metadata={[
                                        {
                                            label: 'Transport',
                                            value: pbx.transport_mode === 'tunnel_agent' ? 'On-Premise Agent' : 'Direct HTTPS (FQDN)',
                                        },
                                        {
                                            label: 'Agent Key Prefix',
                                            value: pbx.agent_token_prefix ? `${pbx.agent_token_prefix}...` : 'N/A',
                                        },
                                    ]}
                                />
                            ))}
                        </div>
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AppIcon icon={Server} size="sm" />
                                    <span>Yeastar P-Series PBX</span>
                                </CardTitle>
                                <CardDescription>Supports Direct HTTPS (FQDN/RAS) and On-Premise Tunnel Agent modes.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <EmptyState
                                    icon={Server}
                                    title="No Yeastar P-Series PBX Configured"
                                    description="Connect a Yeastar P-Series PBX (firmware 37.7.0.16+) via HTTPS Open API credentials or deploy the outbound-only On-Premise Tunnel Agent."
                                />
                            </CardContent>
                        </Card>
                    )}
                </section>

                <section aria-label="Recent call activity">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AppIcon icon={Activity} size="sm" />
                                    <span>Recent Correlated Call Activity</span>
                                </CardTitle>
                                <CardDescription>
                                    Latest tenant-scoped call logs synchronized between Yeastar P-Series and Bitrix24 CRM.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Deferred
                                data="recentCalls"
                                fallback={
                                    <div className="space-y-3">
                                        <Skeleton className="h-10 w-full" />
                                        <Skeleton className="h-10 w-full" />
                                        <Skeleton className="h-10 w-full" />
                                    </div>
                                }
                            >
                                {recentCalls && recentCalls.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm">
                                            <thead>
                                                <tr className="border-b text-xs text-muted-foreground">
                                                    <th className="pb-2.5 pr-4 font-medium">Direction</th>
                                                    <th className="pb-2.5 pr-4 font-medium">Caller / Callee</th>
                                                    <th className="pb-2.5 pr-4 font-medium">Extension</th>
                                                    <th className="pb-2.5 pr-4 font-medium">CRM Entity</th>
                                                    <th className="pb-2.5 pr-4 font-medium">Duration</th>
                                                    <th className="pb-2.5 font-medium">Recording</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {recentCalls.map((call) => (
                                                    <tr key={call.id} className="hover:bg-muted/40">
                                                        <td className="py-2.5 pr-4">
                                                            <CallDirectionBadge
                                                                direction={call.direction}
                                                                statusCode={call.status_code}
                                                            />
                                                        </td>
                                                        <td className="py-2.5 pr-4 font-mono text-xs">
                                                            {call.caller_number} -&gt; {call.callee_number}
                                                        </td>
                                                        <td className="py-2.5 pr-4 font-mono text-xs">{call.extension ?? '-'}</td>
                                                        <td className="py-2.5 pr-4 text-xs">
                                                            {call.crm_entity_type && call.crm_entity_id
                                                                ? `${call.crm_entity_type} #${call.crm_entity_id}`
                                                                : 'Unmatched'}
                                                        </td>
                                                        <td className="py-2.5 pr-4 font-mono text-xs tabular-nums">
                                                            {formatDuration(call.duration_sec)}
                                                        </td>
                                                        <td className="py-2.5">
                                                            <Badge variant="outline" className="inline-flex items-center gap-1 text-xs">
                                                                <AppIcon icon={FileAudio} size="xs" />
                                                                <span className="capitalize">{call.recording_status}</span>
                                                            </Badge>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <EmptyState
                                        icon={PhoneCall}
                                        title="No Calls Logged Yet"
                                        description="Once your Bitrix24 portal and Yeastar P-Series PBX are connected, live call events and streamed recordings will appear here."
                                    />
                                )}
                            </Deferred>
                        </CardContent>
                    </Card>
                </section>
            </div>
        </AppLayout>
    );
}
