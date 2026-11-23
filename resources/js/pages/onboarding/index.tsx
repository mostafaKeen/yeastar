import { AppIcon } from '@/components/common/app-icon';
import { CopyableField } from '@/components/common/copyable-field';
import { EmptyState } from '@/components/common/empty-state';
import { FlashBanner } from '@/components/common/flash-banner';
import { StatusIndicator } from '@/components/common/status-indicator';
import { YeastarConnectionDialog } from '@/components/integrations/yeastar-connection-dialog';
import { SetupStepper, type StepDefinition } from '@/components/onboarding/setup-stepper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type ConnectionStatusValue, type YeastarTransportModeValue } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Blocks,
    Cable,
    CheckCircle2,
    Plus,
    RefreshCw,
    Server,
    ShieldCheck,
    SlidersHorizontal,
    Users,
    Wand2,
} from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';

interface OnboardingProps {
    steps: {
        bitrix_connected: boolean;
        yeastar_connected: boolean;
        extensions_mapped: boolean;
        routing_configured: boolean;
    };
    bitrix: {
        domain: string;
        portal_type: string;
        status: ConnectionStatusValue;
        granted_scopes: string[];
    } | null;
    yeastarConnections: Array<{
        id: string;
        name: string;
        transport_mode: YeastarTransportModeValue;
        pbx_base_url: string | null;
        firmware_version: string | null;
        agent_status: string;
        status: ConnectionStatusValue;
    }>;
    counts: {
        mapped_extensions: number;
        external_lines: number;
    };
    routingPolicy: {
        enable_call_popups: boolean;
        enable_click_to_call: boolean;
        smart_crm_routing: boolean;
        auto_create_crm_lead: boolean;
        enable_recording_stream: boolean;
    };
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
    { title: 'Onboarding Wizard', href: '/onboarding' },
];

export default function OnboardingIndex({
    steps,
    bitrix,
    yeastarConnections,
    counts,
    routingPolicy,
    platformMeta,
}: OnboardingProps) {
    const initialStep = !steps.bitrix_connected
        ? 1
        : !steps.yeastar_connected
          ? 2
          : !steps.extensions_mapped
            ? 3
            : 4;

    const [activeStep, setActiveStep] = useState<number>(initialStep);
    const [pbxDialogOpen, setPbxDialogOpen] = useState(false);

    const policyForm = useForm(routingPolicy);

    const stepItems: StepDefinition[] = useMemo(
        () => [
            {
                id: 1,
                title: 'Bitrix24 Marketplace',
                subtitle: steps.bitrix_connected ? (bitrix?.domain ?? 'Connected') : 'OAuth 2.0 App Install',
                icon: Blocks,
                completed: steps.bitrix_connected,
            },
            {
                id: 2,
                title: 'Yeastar P-Series PBX',
                subtitle: `${yeastarConnections.length} PBX configured`,
                icon: Server,
                completed: steps.yeastar_connected,
            },
            {
                id: 3,
                title: 'Extension Mapping',
                subtitle: `${counts.mapped_extensions} users mapped`,
                icon: Users,
                completed: steps.extensions_mapped,
            },
            {
                id: 4,
                title: 'Routing & Policies',
                subtitle: steps.routing_configured ? 'Active policy saved' : 'Popups & recording stream',
                icon: SlidersHorizontal,
                completed: steps.routing_configured,
            },
        ],
        [steps, bitrix, yeastarConnections.length, counts.mapped_extensions],
    );

    const handleSavePolicies = (event: FormEvent) => {
        event.preventDefault();
        policyForm.patch(route('onboarding.routing'), { preserveScroll: true });
    };

    const handleTestConnection = (connectionId: string) => {
        router.post(route('integrations.yeastar.test', { connection: connectionId }), {}, { preserveScroll: true });
    };

    const handleAutoMatch = (connectionId: string) => {
        router.post(
            route('mappings.auto-match'),
            { yeastar_connection_id: connectionId },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Onboarding Wizard" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Tenant Telephony Onboarding</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Complete these four steps to pair your Bitrix24 Marketplace installation with Yeastar P-Series PBX.
                    </p>
                </div>

                <FlashBanner saasUrl={platformMeta.saas_url} />

                <SetupStepper steps={stepItems} activeStep={activeStep} onSelectStep={setActiveStep} />

                {activeStep === 1 ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <AppIcon icon={Blocks} size="sm" />
                                        <span>Step 1: Bitrix24 Marketplace Application</span>
                                    </CardTitle>
                                    <CardDescription>
                                        Uses central Marketplace OAuth credentials (`client_id` & `client_secret`) with per-portal encrypted tokens keyed by `member_id`.
                                    </CardDescription>
                                </div>
                                <StatusIndicator status={bitrix?.status ?? 'pending'} />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {bitrix ? (
                                <div className="rounded-lg border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <div className="text-sm font-semibold">{bitrix.domain}</div>
                                            <div className="text-muted-foreground text-xs capitalize">
                                                {bitrix.portal_type.replace('_', '-')} Portal · {counts.external_lines} External Lines
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {bitrix.granted_scopes.map((scope) => (
                                                <Badge key={scope} variant="secondary" className="font-mono text-xs">
                                                    {scope}
                                                </Badge>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-3 rounded-lg border p-4">
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <AppIcon icon={ShieldCheck} size="sm" />
                                        <span>Bitrix24 Marketplace Callback Endpoints</span>
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        Configure these HTTPS URLs in your Bitrix24 Partner / Developer Area with least-privilege scopes: `telephony`, `crm`, and `user_basic`.
                                    </p>
                                    <div className="space-y-2">
                                        <Label className="text-xs">Application Installer URL (`ONAPPINSTALL`)</Label>
                                        <CopyableField value={platformMeta.bitrix_install_url} label="Bitrix24 Installer URL" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label className="text-xs">Telephony Event Handler URL (`ONEXTERNALCALLSTART`)</Label>
                                        <CopyableField value={platformMeta.bitrix_events_url} label="Bitrix24 Event URL" />
                                    </div>
                                </div>
                            )}
                        </CardContent>
                        <CardFooter className="justify-end">
                            <Button type="button" onClick={() => setActiveStep(2)}>
                                <span>Continue to Yeastar PBX</span>
                                <AppIcon icon={ArrowRight} size="sm" />
                            </Button>
                        </CardFooter>
                    </Card>
                ) : null}

                {activeStep === 2 ? (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AppIcon icon={Server} size="sm" />
                                    <span>Step 2: Connect Yeastar P-Series PBX</span>
                                </CardTitle>
                                <CardDescription>
                                    Choose Direct HTTPS (FQDN/RAS) or On-Premise Outbound Tunnel Agent for private-NAT PBXs.
                                </CardDescription>
                            </div>
                            <Button type="button" onClick={() => setPbxDialogOpen(true)}>
                                <AppIcon icon={Plus} size="sm" />
                                <span>Add PBX Connection</span>
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {yeastarConnections.length === 0 ? (
                                <EmptyState
                                    icon={Server}
                                    title="No Yeastar PBX Configured Yet"
                                    description={`Connect a Yeastar P-Series PBX (firmware ${platformMeta.min_firmware}+, Enterprise or Ultimate Plan) via FQDN Open API or On-Premise Tunnel Agent.`}
                                    action={
                                        <Button type="button" variant="outline" onClick={() => setPbxDialogOpen(true)}>
                                            <AppIcon icon={Plus} size="sm" />
                                            <span>Configure Yeastar PBX</span>
                                        </Button>
                                    }
                                />
                            ) : (
                                yeastarConnections.map((conn) => (
                                    <div
                                        key={conn.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="bg-muted flex size-9 items-center justify-center rounded-md">
                                                <AppIcon
                                                    icon={conn.transport_mode === 'tunnel_agent' ? Cable : Server}
                                                    size="sm"
                                                />
                                            </div>
                                            <div>
                                                <div className="text-sm font-semibold">{conn.name}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {conn.transport_mode === 'tunnel_agent'
                                                        ? 'On-Premise Outbound Tunnel Agent'
                                                        : (conn.pbx_base_url ?? 'Direct HTTPS')}
                                                    {conn.firmware_version ? ` · fw ${conn.firmware_version}` : ''}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <StatusIndicator
                                                status={conn.transport_mode === 'tunnel_agent' ? conn.agent_status : conn.status}
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => handleTestConnection(conn.id)}
                                            >
                                                <AppIcon icon={RefreshCw} size="xs" />
                                                <span>Test Connection</span>
                                            </Button>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                        <CardFooter className="justify-between">
                            <Button type="button" variant="outline" onClick={() => setActiveStep(1)}>
                                Back
                            </Button>
                            <Button type="button" onClick={() => setActiveStep(3)}>
                                <span>Continue to Extension Mapping</span>
                                <AppIcon icon={ArrowRight} size="sm" />
                            </Button>
                        </CardFooter>
                    </Card>
                ) : null}

                {activeStep === 3 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={Users} size="sm" />
                                <span>Step 3: Associate Bitrix24 Users with PBX Extensions</span>
                            </CardTitle>
                            <CardDescription>
                                Automatically match users sharing the same email address across Bitrix24 and Yeastar P-Series, or bind them manually.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                                <div>
                                    <div className="text-sm font-semibold">
                                        {counts.mapped_extensions} Active Extension Mappings
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        Active mappings are cached in Redis per tenant for zero-DB-query hot-path call routing.
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {yeastarConnections[0] ? (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => handleAutoMatch(yeastarConnections[0].id)}
                                        >
                                            <AppIcon icon={Wand2} size="sm" />
                                            <span>Auto-Match by Email</span>
                                        </Button>
                                    ) : null}
                                    <Button type="button" variant="outline" asChild>
                                        <Link href="/mappings" prefetch>
                                            <span>Open Mapping Table</span>
                                            <AppIcon icon={ArrowRight} size="sm" />
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                        <CardFooter className="justify-between">
                            <Button type="button" variant="outline" onClick={() => setActiveStep(2)}>
                                Back
                            </Button>
                            <Button type="button" onClick={() => setActiveStep(4)}>
                                <span>Continue to Routing Policies</span>
                                <AppIcon icon={ArrowRight} size="sm" />
                            </Button>
                        </CardFooter>
                    </Card>
                ) : null}

                {activeStep === 4 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={SlidersHorizontal} size="sm" />
                                <span>Step 4: Telephony Routing & Zero-Disk Recording Policy</span>
                            </CardTitle>
                            <CardDescription>
                                Configure call card behavior, click-to-call, CRM lead automation, and direct Yeastar-to-Bitrix24 recording streaming.
                            </CardDescription>
                        </CardHeader>
                        <form onSubmit={handleSavePolicies}>
                            <CardContent className="space-y-4">
                                {[
                                    {
                                        key: 'enable_call_popups' as const,
                                        title: 'Real-Time Bitrix24 Call Card Popups',
                                        description: 'Show call card to ringing agents (telephony.externalCall.show) and hide for non-answering queue members.',
                                    },
                                    {
                                        key: 'enable_click_to_call' as const,
                                        title: 'CRM Click-to-Call (ONEXTERNALCALLSTART)',
                                        description: 'Ring the employee extension on Yeastar P-Series and bridge outbound CRM calls automatically.',
                                    },
                                    {
                                        key: 'smart_crm_routing' as const,
                                        title: 'Smart Responsible Manager Routing',
                                        description: 'Query searchCrmEntities on inbound calls to route directly to the assigned CRM owner when available.',
                                    },
                                    {
                                        key: 'auto_create_crm_lead' as const,
                                        title: 'Auto-Create Lead for Unknown Callers',
                                        description: 'Instruct Bitrix24 to open a new CRM Lead when an inbound caller matches no Contact, Lead, or Company.',
                                    },
                                    {
                                        key: 'enable_recording_stream' as const,
                                        title: 'Direct Recording Stream (Zero Server Storage)',
                                        description: 'Stream audio chunks directly from Yeastar to Bitrix24 attachRecord uploadUrl without persisting files on this server.',
                                    },
                                ].map((item) => (
                                    <div key={item.key} className="flex items-start gap-3 rounded-lg border p-3.5">
                                        <Checkbox
                                            id={item.key}
                                            checked={policyForm.data[item.key]}
                                            onCheckedChange={(checked) => policyForm.setData(item.key, Boolean(checked))}
                                            className="mt-0.5"
                                        />
                                        <div className="space-y-0.5">
                                            <Label htmlFor={item.key} className="cursor-pointer text-sm font-medium">
                                                {item.title}
                                            </Label>
                                            <p className="text-muted-foreground text-xs">{item.description}</p>
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                            <CardFooter className="justify-between">
                                <Button type="button" variant="outline" onClick={() => setActiveStep(3)}>
                                    Back
                                </Button>
                                <Button type="submit" disabled={policyForm.processing}>
                                    <AppIcon icon={CheckCircle2} size="sm" />
                                    <span>{policyForm.processing ? 'Saving...' : 'Save & Complete Setup'}</span>
                                </Button>
                            </CardFooter>
                        </form>
                    </Card>
                ) : null}

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
