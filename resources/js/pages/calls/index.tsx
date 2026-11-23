import { AppIcon } from '@/components/common/app-icon';
import { EmptyState } from '@/components/common/empty-state';
import { CallDetailSheet, type CallDetailItem } from '@/components/telephony/call-detail-sheet';
import { CallDirectionBadge } from '@/components/telephony/call-direction-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileAudio, PhoneCall, Search, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface CallsPageProps {
    calls: {
        data: CallDetailItem[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: {
        direction: string;
        status: string;
        search: string;
    };
    bitrixDomain: string | null;
    canViewAllCalls: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Operations Dashboard', href: '/dashboard' },
    { title: 'Call Logs', href: '/calls' },
];

function formatDuration(seconds: number): string {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}m ${secs.toString().padStart(2, '0')}s`;
}

export default function CallsIndex({ calls, filters, bitrixDomain, canViewAllCalls }: CallsPageProps) {
    const [selectedCall, setSelectedCall] = useState<CallDetailItem | null>(null);
    const [searchText, setSearchText] = useState(filters.search);

    const applyFilter = (next: Partial<CallsPageProps['filters']>) => {
        const merged = {
            direction: next.direction ?? filters.direction,
            status: next.status ?? filters.status,
            search: next.search ?? searchText,
        };

        router.get(route('calls.index'), merged, {
            preserveState: true,
            preserveScroll: true,
            only: ['calls', 'filters'],
            replace: true,
        });
    };

    const navigateCursor = (url: string | null) => {
        if (!url) {
            return;
        }

        router.get(url, {}, { preserveState: true, preserveScroll: true, only: ['calls', 'filters'] });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Call Logs" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Call Explorer</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Cursor-paginated CDR records correlated across Yeastar P-Series and Bitrix24 CRM activities.
                        </p>
                    </div>
                    {!canViewAllCalls ? (
                        <Badge variant="outline" className="inline-flex items-center gap-1.5 text-xs">
                            <AppIcon icon={ShieldCheck} size="xs" />
                            <span>Scoped to Your Mapped Extensions</span>
                        </Badge>
                    ) : null}
                </div>

                <Card>
                    <CardHeader className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={PhoneCall} size="sm" />
                                <span>Correlated Call Records</span>
                            </CardTitle>
                            <CardDescription>Select any call row to inspect its lifecycle timeline and CRM link.</CardDescription>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    applyFilter({ search: searchText });
                                }}
                                className="relative w-full sm:w-56"
                            >
                                <span className="text-muted-foreground pointer-events-none absolute top-2.5 left-3">
                                    <AppIcon icon={Search} size="xs" />
                                </span>
                                <Input
                                    value={searchText}
                                    onChange={(e) => setSearchText(e.target.value)}
                                    placeholder="Phone, extension, call ID..."
                                    className="pl-8 text-xs"
                                />
                            </form>

                            <Select
                                value={filters.direction || 'all'}
                                onValueChange={(value) => applyFilter({ direction: value === 'all' ? '' : value })}
                            >
                                <SelectTrigger className="w-36 text-xs">
                                    <SelectValue placeholder="Direction" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Directions</SelectItem>
                                    <SelectItem value="inbound">Inbound</SelectItem>
                                    <SelectItem value="outbound">Outbound</SelectItem>
                                    <SelectItem value="callback">Callback</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(value) => applyFilter({ status: value === 'all' ? '' : value })}
                            >
                                <SelectTrigger className="w-36 text-xs">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="answered">Answered (200)</SelectItem>
                                    <SelectItem value="missed">Missed (304)</SelectItem>
                                    <SelectItem value="failed">Failed / Busy</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        {calls.data.length === 0 ? (
                            <EmptyState
                                icon={PhoneCall}
                                title="No Matching Call Records"
                                description="Adjust your filters or place a test inbound/outbound call once Bitrix24 and Yeastar are paired."
                            />
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead>
                                            <tr className="border-b text-xs text-muted-foreground">
                                                <th className="pb-2.5 pr-4 font-medium">Direction</th>
                                                <th className="pb-2.5 pr-4 font-medium">Caller / Callee</th>
                                                <th className="pb-2.5 pr-4 font-medium">Ext</th>
                                                <th className="pb-2.5 pr-4 font-medium">CRM Entity</th>
                                                <th className="pb-2.5 pr-4 font-medium">Talk Time</th>
                                                <th className="pb-2.5 pr-4 font-medium">Recording</th>
                                                <th className="pb-2.5 font-medium">Started</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {calls.data.map((call) => (
                                                <tr
                                                    key={call.id}
                                                    onClick={() => setSelectedCall(call)}
                                                    className="hover:bg-muted/50 cursor-pointer transition-colors"
                                                >
                                                    <td className="py-2.5 pr-4">
                                                        <CallDirectionBadge direction={call.direction} statusCode={call.status_code} />
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
                                                        {formatDuration(call.talk_duration_sec)}
                                                    </td>
                                                    <td className="py-2.5 pr-4">
                                                        <Badge variant="outline" className="inline-flex items-center gap-1 text-xs">
                                                            <AppIcon icon={FileAudio} size="xs" />
                                                            <span className="capitalize">{call.recording_status}</span>
                                                        </Badge>
                                                    </td>
                                                    <td className="text-muted-foreground py-2.5 font-mono text-xs">
                                                        {call.started_at ? new Date(call.started_at).toLocaleString() : '-'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="flex items-center justify-end gap-2 pt-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={!calls.prev_page_url}
                                        onClick={() => navigateCursor(calls.prev_page_url)}
                                    >
                                        <AppIcon icon={ChevronLeft} size="xs" />
                                        <span>Previous</span>
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={!calls.next_page_url}
                                        onClick={() => navigateCursor(calls.next_page_url)}
                                    >
                                        <span>Next</span>
                                        <AppIcon icon={ChevronRight} size="xs" />
                                    </Button>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                <CallDetailSheet call={selectedCall} bitrixDomain={bitrixDomain} onClose={() => setSelectedCall(null)} />
            </div>
        </AppLayout>
    );
}
