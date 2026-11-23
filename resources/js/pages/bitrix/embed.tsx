import { AppIcon } from '@/components/common/app-icon';
import { KpiMetricCard } from '@/components/common/kpi-metric-card';
import { StatusIndicator } from '@/components/common/status-indicator';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import BitrixIframeLayout from '@/layouts/bitrix-iframe-layout';
import { type ConnectionStatusValue, type YeastarTransportModeValue } from '@/types';
import { Head } from '@inertiajs/react';
import { Blocks, Cable, ExternalLink, PhoneCall, Server, ShieldCheck, Users } from 'lucide-react';

interface BitrixEmbedProps {
    portal: {
        domain: string;
        portal_type: string;
        status: ConnectionStatusValue;
        granted_scopes: string[];
    };
    bitrixUser: {
        id: string;
        name: string;
        is_admin: boolean;
    };
    yeastarConnections: Array<{
        id: string;
        name: string;
        transport_mode: YeastarTransportModeValue;
        firmware_version: string | null;
        agent_status: string;
        status: ConnectionStatusValue;
    }>;
    metrics: {
        mapped_extensions: number;
        external_lines: number;
        calls_24h: number;
    };
    consoleUrls: {
        dashboard: string;
        onboarding: string;
        mappings: string;
    };
}

export default function BitrixEmbed({ portal, bitrixUser, yeastarConnections, metrics, consoleUrls }: BitrixEmbedProps) {
    return (
        <BitrixIframeLayout
            title="Yeastar P-Series Telephony Bridge"
            actions={
                <Button type="button" size="sm" variant="outline" asChild>
                    <a href={consoleUrls.dashboard} target="_blank" rel="noreferrer">
                        <span>Open Full Console</span>
                        <AppIcon icon={ExternalLink} size="xs" />
                    </a>
                </Button>
            }
        >
            <Head title="Yeastar P-Series Telephony" />

            <div className="space-y-5">
                <section className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                    <div className="flex items-center gap-3">
                        <div className="bg-muted flex size-10 items-center justify-center rounded-md">
                            <AppIcon icon={Blocks} size="md" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-semibold">{portal.domain}</span>
                                <StatusIndicator status={portal.status} />
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Signed in via Bitrix24 as {bitrixUser.name || `User #${bitrixUser.id}`}
                                {bitrixUser.is_admin ? ' (Portal Admin)' : ''}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {portal.granted_scopes.map((scope) => (
                            <Badge key={scope} variant="secondary" className="font-mono text-[11px]">
                                {scope}
                            </Badge>
                        ))}
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-3">
                    <KpiMetricCard
                        title="Calls Synchronized (24h)"
                        value={metrics.calls_24h}
                        description="Inbound, outbound & callbacks"
                        icon={PhoneCall}
                    />
                    <KpiMetricCard
                        title="Mapped Extensions"
                        value={metrics.mapped_extensions}
                        description="Active Bitrix24 <-> PBX bindings"
                        icon={Users}
                    />
                    <KpiMetricCard
                        title="External Lines"
                        value={metrics.external_lines}
                        description="Registered via telephony.externalLine"
                        icon={ShieldCheck}
                    />
                </section>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={Server} size="sm" />
                                <span>Connected Yeastar P-Series PBXs</span>
                            </CardTitle>
                            <CardDescription>Real-time health of Direct HTTPS and On-Premise Tunnel Agent PBX nodes.</CardDescription>
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" size="sm" variant="outline" asChild>
                                <a href={consoleUrls.mappings} target="_blank" rel="noreferrer">
                                    <span>Manage Mappings</span>
                                    <AppIcon icon={ExternalLink} size="xs" />
                                </a>
                            </Button>
                            <Button type="button" size="sm" asChild>
                                <a href={consoleUrls.onboarding} target="_blank" rel="noreferrer">
                                    <span>Setup Wizard</span>
                                    <AppIcon icon={ExternalLink} size="xs" />
                                </a>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2.5">
                        {yeastarConnections.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-center text-xs">
                                No Yeastar P-Series PBX connected yet. Open the Setup Wizard to pair your PBX.
                            </p>
                        ) : (
                            yeastarConnections.map((conn) => (
                                <div
                                    key={conn.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <AppIcon
                                            icon={conn.transport_mode === 'tunnel_agent' ? Cable : Server}
                                            size="sm"
                                        />
                                        <div>
                                            <div className="text-sm font-medium">{conn.name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {conn.transport_mode === 'tunnel_agent' ? 'On-Premise Agent' : 'Direct HTTPS'}
                                                {conn.firmware_version ? ` · fw ${conn.firmware_version}` : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <StatusIndicator
                                        status={conn.transport_mode === 'tunnel_agent' ? conn.agent_status : conn.status}
                                    />
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
        </BitrixIframeLayout>
    );
}
