import { AppIcon } from '@/components/common/app-icon';
import { EmptyState } from '@/components/common/empty-state';
import { FlashBanner } from '@/components/common/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, RotateCcw, ShieldCheck, Terminal, Webhook } from 'lucide-react';
import { useState } from 'react';

interface WebhookRow {
    id: string;
    source: string;
    event_name: string;
    idempotency_key: string | null;
    status: string;
    http_status: number;
    latency_ms: number | null;
    sanitized_payload: Record<string, unknown> | null;
    error_message: string | null;
    created_at: string | null;
}

interface AuditLogRow {
    id: string;
    provider: string;
    method: string;
    http_status: number;
    error_code: string | null;
    duration_ms: number;
    b24_operating_sec: number | null;
    created_at: string | null;
}

interface DiagnosticsPageProps {
    webhooks: WebhookRow[];
    auditLogs: AuditLogRow[];
    retentionDays: number;
    canReplay: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Operations Dashboard', href: '/dashboard' },
    { title: 'Diagnostics & Audit', href: '/diagnostics' },
];

export default function DiagnosticsIndex({ webhooks, auditLogs, retentionDays, canReplay }: DiagnosticsPageProps) {
    const [activeTab, setActiveTab] = useState<'webhooks' | 'audit'>('webhooks');
    const [expandedId, setExpandedId] = useState<string | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Diagnostics & Audit" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Diagnostics, Webhook Replay &amp; API Audit</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Sanitized telemetry retaining Bitrix24 &amp; Yeastar API logs for {retentionDays} days (all OAuth secrets redacted).
                        </p>
                    </div>
                    <Badge variant="outline" className="inline-flex items-center gap-1.5 text-xs">
                        <AppIcon icon={ShieldCheck} size="xs" />
                        <span>Redacted Secret Logging Active</span>
                    </Badge>
                </div>

                <FlashBanner />

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant={activeTab === 'webhooks' ? 'default' : 'outline'}
                        onClick={() => setActiveTab('webhooks')}
                    >
                        <AppIcon icon={Webhook} size="xs" />
                        <span>Webhook Deliveries ({webhooks.length})</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={activeTab === 'audit' ? 'default' : 'outline'}
                        onClick={() => setActiveTab('audit')}
                    >
                        <AppIcon icon={Terminal} size="xs" />
                        <span>REST API Audit Logs ({auditLogs.length})</span>
                    </Button>
                </div>

                {activeTab === 'webhooks' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={Webhook} size="sm" />
                                <span>Incoming Webhook &amp; Agent Event Stream</span>
                            </CardTitle>
                            <CardDescription>Click a row to inspect its sanitized JSON payload or replay failed deliveries.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {webhooks.length === 0 ? (
                                <EmptyState
                                    icon={Webhook}
                                    title="No Webhook Deliveries Yet"
                                    description="Incoming Bitrix24 events and Yeastar PBX call status pushes will be recorded here."
                                />
                            ) : (
                                <div className="divide-y rounded-md border">
                                    {webhooks.map((item) => {
                                        const isExpanded = expandedId === item.id;

                                        return (
                                            <div key={item.id} className="p-3.5">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => setExpandedId(isExpanded ? null : item.id)}
                                                        className="flex flex-wrap items-center gap-2 text-left"
                                                    >
                                                        <Badge variant="secondary" className="font-mono text-[11px]">
                                                            {item.source}
                                                        </Badge>
                                                        <span className="font-mono text-xs font-semibold">{item.event_name}</span>
                                                        <Badge
                                                            variant="outline"
                                                            className="inline-flex items-center gap-1 text-[11px]"
                                                        >
                                                            <AppIcon
                                                                icon={item.status === 'failed' ? AlertTriangle : CheckCircle2}
                                                                size="xs"
                                                            />
                                                            <span>{item.status}</span>
                                                        </Badge>
                                                        {item.latency_ms !== null ? (
                                                            <span className="text-muted-foreground font-mono text-xs">
                                                                {item.latency_ms}ms
                                                            </span>
                                                        ) : null}
                                                    </button>

                                                    <div className="flex items-center gap-2">
                                                        <span className="text-muted-foreground font-mono text-xs">
                                                            {item.created_at ? new Date(item.created_at).toLocaleString() : '-'}
                                                        </span>
                                                        {canReplay ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-7 px-2 text-xs"
                                                                onClick={() =>
                                                                    router.post(
                                                                        route('diagnostics.webhooks.replay', { deliveryId: item.id }),
                                                                        {},
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                <AppIcon icon={RotateCcw} size="xs" />
                                                                <span>Replay</span>
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                {item.error_message ? (
                                                    <p className="text-destructive mt-2 font-mono text-xs">{item.error_message}</p>
                                                ) : null}

                                                {isExpanded && item.sanitized_payload ? (
                                                    <pre className="bg-muted mt-3 max-h-64 overflow-auto rounded-md p-3 font-mono text-xs">
                                                        {JSON.stringify(item.sanitized_payload, null, 2)}
                                                    </pre>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={Terminal} size="sm" />
                                <span>Outbound REST API Audit Logs</span>
                            </CardTitle>
                            <CardDescription>
                                Tracks Bitrix24 &amp; Yeastar response latency, HTTP status, error codes, and Bitrix24 `operating` time budget.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {auditLogs.length === 0 ? (
                                <EmptyState
                                    icon={Terminal}
                                    title="No API Requests Logged Yet"
                                    description="All server-to-server Bitrix24 and Yeastar Open API calls are automatically audited here."
                                />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead>
                                            <tr className="border-b text-xs text-muted-foreground">
                                                <th className="pb-2.5 pr-4 font-medium">Provider</th>
                                                <th className="pb-2.5 pr-4 font-medium">Method</th>
                                                <th className="pb-2.5 pr-4 font-medium">HTTP</th>
                                                <th className="pb-2.5 pr-4 font-medium">Error Code</th>
                                                <th className="pb-2.5 pr-4 font-medium">Latency</th>
                                                <th className="pb-2.5 pr-4 font-medium">B24 Operating</th>
                                                <th className="pb-2.5 font-medium">Timestamp</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {auditLogs.map((log) => (
                                                <tr key={log.id}>
                                                    <td className="py-2 pr-4">
                                                        <Badge variant="secondary" className="font-mono text-[11px]">
                                                            {log.provider}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-2 pr-4 font-mono text-xs">{log.method}</td>
                                                    <td className="py-2 pr-4 font-mono text-xs">{log.http_status}</td>
                                                    <td className="py-2 pr-4 font-mono text-xs">
                                                        {log.error_code ? (
                                                            <Badge variant="destructive" className="font-mono text-[11px]">
                                                                {log.error_code}
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-muted-foreground">OK</span>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-4 font-mono text-xs tabular-nums">{log.duration_ms}ms</td>
                                                    <td className="py-2 pr-4 font-mono text-xs tabular-nums">
                                                        {log.b24_operating_sec !== null ? `${log.b24_operating_sec.toFixed(2)}s` : '-'}
                                                    </td>
                                                    <td className="text-muted-foreground py-2 font-mono text-xs">
                                                        {log.created_at ? new Date(log.created_at).toLocaleString() : '-'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
