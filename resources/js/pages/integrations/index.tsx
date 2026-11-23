import { AppIcon } from '@/components/common/app-icon';
import { CopyableField } from '@/components/common/copyable-field';
import { EmptyState } from '@/components/common/empty-state';
import { FlashBanner } from '@/components/common/flash-banner';
import { ConnectionHealthCard } from '@/components/integrations/connection-health-card';
import { YeastarConnectionDialog } from '@/components/integrations/yeastar-connection-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type ConnectionStatusValue, type YeastarTransportModeValue } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Blocks, BookUser, Cable, KeyRound, PhoneCall, Plus, RefreshCw, Server, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

interface IntegrationsProps {
    bitrix: {
        id: string;
        member_id: string;
        domain: string;
        portal_type: string;
        status: ConnectionStatusValue;
        granted_scopes: string[];
        installed_at: string | null;
        token_expires_at: string | null;
    } | null;
    yeastarConnections: Array<{
        id: string;
        name: string;
        transport_mode: YeastarTransportModeValue;
        pbx_base_url: string | null;
        firmware_version: string | null;
        client_id: string | null;
        webhook_url: string;
        agent_token_prefix: string | null;
        agent_status: string;
        allowed_ips: string[];
        status: ConnectionStatusValue;
        last_heartbeat_at: string | null;
    }>;
    externalLines: Array<{
        id: string;
        yeastar_connection_id: string;
        yeastar_connection_name: string | null;
        bitrix_line_number: string;
        bitrix_line_name: string;
        yeastar_trunk_name: string | null;
        did_number: string | null;
        is_default: boolean;
        is_active: boolean;
    }>;
    platformMeta: {
        bitrix_install_url: string;
        bitrix_events_url: string;
        static_egress_ips: string[];
        min_firmware: string;
        saas_url: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Operations Dashboard', href: '/dashboard' },
    { title: 'Integrations', href: '/integrations' },
];

export default function IntegrationsIndex({
    bitrix,
    yeastarConnections,
    externalLines,
    platformMeta,
}: IntegrationsProps) {
    const [pbxDialogOpen, setPbxDialogOpen] = useState(false);

    const lineForm = useForm({
        yeastar_connection_id: yeastarConnections[0]?.id ?? '',
        bitrix_line_number: '',
        bitrix_line_name: '',
        yeastar_trunk_name: '',
        did_number: '',
    });

    const handleAddLine = (event: FormEvent) => {
        event.preventDefault();
        lineForm.post(route('integrations.lines.store'), {
            preserveScroll: true,
            onSuccess: () => lineForm.reset('bitrix_line_number', 'bitrix_line_name', 'yeastar_trunk_name', 'did_number'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integrations" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Bitrix24 &amp; Yeastar Integrations</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Manage Marketplace OAuth connectivity, hybrid Yeastar P-Series transports, and registered external lines.
                        </p>
                    </div>
                    <Button type="button" onClick={() => setPbxDialogOpen(true)}>
                        <AppIcon icon={Plus} size="sm" />
                        <span>Add Yeastar PBX</span>
                    </Button>
                </div>

                <FlashBanner saasUrl={platformMeta.saas_url} />

                <section className="grid gap-4 lg:grid-cols-2">
                    {bitrix ? (
                        <ConnectionHealthCard
                            title="Bitrix24 Marketplace App"
                            subtitle={bitrix.domain}
                            icon={Blocks}
                            status={bitrix.status}
                            badges={bitrix.granted_scopes}
                            metadata={[
                                { label: 'Portal Type', value: bitrix.portal_type === 'on_premise' ? 'On-Premise' : 'Cloud' },
                                { label: 'Member ID', value: `${bitrix.member_id.slice(0, 10)}...` },
                            ]}
                            footer={
                                <div className="space-y-1.5">
                                    <Label className="text-xs">Event Handler Endpoint</Label>
                                    <CopyableField value={platformMeta.bitrix_events_url} label="Bitrix24 Events URL" />
                                </div>
                            }
                        />
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AppIcon icon={Blocks} size="sm" />
                                    <span>Bitrix24 Marketplace App</span>
                                </CardTitle>
                                <CardDescription>Not yet installed for this workspace.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Label className="text-xs">Marketplace Install Callback URL</Label>
                                <CopyableField value={platformMeta.bitrix_install_url} label="Bitrix24 Install URL" />
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={PhoneCall} size="sm" />
                                <span>Register Bitrix24 External Line</span>
                            </CardTitle>
                            <CardDescription>
                                Calls `telephony.externalLine.add` to link Yeastar trunks/DIDs with your Bitrix24 portal.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {yeastarConnections.length === 0 ? (
                                <EmptyState
                                    icon={Server}
                                    title="Add a Yeastar PBX First"
                                    description="External lines must be bound to an active Yeastar P-Series connection."
                                />
                            ) : (
                                <form onSubmit={handleAddLine} className="space-y-3">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="line-pbx" className="text-xs">Yeastar PBX</Label>
                                            <Select
                                                value={lineForm.data.yeastar_connection_id}
                                                onValueChange={(value) => lineForm.setData('yeastar_connection_id', value)}
                                            >
                                                <SelectTrigger id="line-pbx">
                                                    <SelectValue placeholder="Select PBX" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {yeastarConnections.map((conn) => (
                                                        <SelectItem key={conn.id} value={conn.id}>
                                                            {conn.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="line-number" className="text-xs">Bitrix24 Line Number</Label>
                                            <Input
                                                id="line-number"
                                                value={lineForm.data.bitrix_line_number}
                                                onChange={(e) => lineForm.setData('bitrix_line_number', e.target.value)}
                                                placeholder="line-sales-1"
                                                required
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="line-name" className="text-xs">Display Name</Label>
                                            <Input
                                                id="line-name"
                                                value={lineForm.data.bitrix_line_name}
                                                onChange={(e) => lineForm.setData('bitrix_line_name', e.target.value)}
                                                placeholder="Main Sales Trunk"
                                                required
                                            />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="line-did" className="text-xs">DID / Trunk Name</Label>
                                            <Input
                                                id="line-did"
                                                value={lineForm.data.did_number}
                                                onChange={(e) => lineForm.setData('did_number', e.target.value)}
                                                placeholder="+15550100"
                                            />
                                        </div>
                                    </div>
                                    <Button type="submit" size="sm" disabled={lineForm.processing}>
                                        <AppIcon icon={Plus} size="xs" />
                                        <span>{lineForm.processing ? 'Registering...' : 'Register External Line'}</span>
                                    </Button>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Yeastar P-Series Connections</h2>
                    {yeastarConnections.length === 0 ? (
                        <EmptyState
                            icon={Server}
                            title="No Yeastar Connections"
                            description="Add your first Yeastar P-Series PBX using Direct HTTPS (FQDN/RAS) or the On-Premise Tunnel Agent."
                            action={
                                <Button type="button" onClick={() => setPbxDialogOpen(true)}>
                                    <AppIcon icon={Plus} size="sm" />
                                    <span>Add Yeastar PBX</span>
                                </Button>
                            }
                        />
                    ) : (
                        <div className="grid gap-4 lg:grid-cols-2">
                            {yeastarConnections.map((conn) => (
                                <ConnectionHealthCard
                                    key={conn.id}
                                    title={conn.name}
                                    subtitle={
                                        conn.transport_mode === 'tunnel_agent'
                                            ? 'On-Premise Outbound Tunnel Agent'
                                            : (conn.pbx_base_url ?? 'Direct HTTPS')
                                    }
                                    icon={conn.transport_mode === 'tunnel_agent' ? Cable : Server}
                                    status={conn.transport_mode === 'tunnel_agent' ? conn.agent_status : conn.status}
                                    badges={[
                                        conn.transport_mode,
                                        conn.firmware_version ? `fw ${conn.firmware_version}` : 'fw unverified',
                                    ]}
                                    metadata={[
                                        { label: 'Client ID / Prefix', value: conn.client_id ?? conn.agent_token_prefix ?? 'Not set' },
                                        { label: 'Allowed IPs', value: conn.allowed_ips.length > 0 ? conn.allowed_ips.join(', ') : 'Any verified' },
                                    ]}
                                    footer={
                                        <div className="space-y-3">
                                            {conn.transport_mode === 'direct_https' ? (
                                                <div className="space-y-1">
                                                    <Label className="text-xs">PBX Event Webhook URL</Label>
                                                    <CopyableField value={conn.webhook_url} label="Yeastar Webhook URL" />
                                                </div>
                                            ) : null}
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            route('integrations.yeastar.test', { connection: conn.id }),
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    <AppIcon icon={RefreshCw} size="xs" />
                                                    <span>Test</span>
                                                </Button>

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            route('integrations.yeastar.sync-phonebook', { connection: conn.id }),
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    <AppIcon icon={BookUser} size="xs" />
                                                    <span>Sync Phonebook</span>
                                                </Button>

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
                                                        <span>Rotate Agent Token</span>
                                                    </Button>
                                                ) : null}

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive hover:text-destructive ml-auto"
                                                    onClick={() =>
                                                        router.delete(route('integrations.yeastar.destroy', { connection: conn.id }), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    <AppIcon icon={Trash2} size="xs" />
                                                    <span>Remove</span>
                                                </Button>
                                            </div>
                                        </div>
                                    }
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Registered External Lines</h2>
                    <Card>
                        <CardContent className="pt-6">
                            {externalLines.length === 0 ? (
                                <EmptyState
                                    icon={PhoneCall}
                                    title="No External Lines Registered"
                                    description="Register at least one external line so Bitrix24 associates inbound and outbound calls with your Marketplace application."
                                />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead>
                                            <tr className="border-b text-xs text-muted-foreground">
                                                <th className="pb-2.5 pr-4 font-medium">Line Number</th>
                                                <th className="pb-2.5 pr-4 font-medium">Name</th>
                                                <th className="pb-2.5 pr-4 font-medium">PBX</th>
                                                <th className="pb-2.5 pr-4 font-medium">DID</th>
                                                <th className="pb-2.5 text-right font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {externalLines.map((line) => (
                                                <tr key={line.id}>
                                                    <td className="py-2.5 pr-4 font-mono text-xs">{line.bitrix_line_number}</td>
                                                    <td className="py-2.5 pr-4 font-medium">{line.bitrix_line_name}</td>
                                                    <td className="py-2.5 pr-4 text-xs">
                                                        <Badge variant="secondary">{line.yeastar_connection_name ?? '-'}</Badge>
                                                    </td>
                                                    <td className="py-2.5 pr-4 font-mono text-xs">{line.did_number ?? '-'}</td>
                                                    <td className="py-2.5 text-right">
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="ghost"
                                                            aria-label="Delete line"
                                                            onClick={() =>
                                                                router.delete(route('integrations.lines.destroy', { externalLine: line.id }), {
                                                                    preserveScroll: true,
                                                                })
                                                            }
                                                        >
                                                            <AppIcon icon={Trash2} size="xs" className="text-destructive" />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </section>

                <YeastarConnectionDialog
                    open={pbxDialogOpen}
                    onOpenChange={setPbxDialogOpen}
                    staticEgressIps={platformMeta.static_egress_ips}
                    minFirmware={platformMeta.min_firmware}
                />
            </div>
        </AppLayout>
    );
}
