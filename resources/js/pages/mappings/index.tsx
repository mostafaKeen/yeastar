import { AppIcon } from '@/components/common/app-icon';
import { EmptyState } from '@/components/common/empty-state';
import { FlashBanner } from '@/components/common/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeftRight, Bell, PhoneOutgoing, Plus, Search, Trash2, Users, Wand2 } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';

interface MappingRow {
    id: string;
    yeastar_connection_id: string;
    yeastar_connection_name: string | null;
    bitrix_user_id: string;
    bitrix_user_name: string | null;
    bitrix_user_email: string | null;
    yeastar_extension: string;
    yeastar_caller_name: string | null;
    is_active: boolean;
    click_to_call_enabled: boolean;
    popup_enabled: boolean;
}

interface MappingsPageProps {
    mappings: MappingRow[];
    yeastarConnections: Array<{
        id: string;
        name: string;
        transport_mode: string;
        status: string;
        agent_status: string;
    }>;
    hasBitrixConnection: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Operations Dashboard', href: '/dashboard' },
    { title: 'Extension Mappings', href: '/mappings' },
];

export default function MappingsIndex({ mappings, yeastarConnections, hasBitrixConnection }: MappingsPageProps) {
    const [search, setSearch] = useState('');
    const [dialogOpen, setDialogOpen] = useState(false);

    const form = useForm({
        yeastar_connection_id: yeastarConnections[0]?.id ?? '',
        bitrix_user_id: '',
        bitrix_user_name: '',
        bitrix_user_email: '',
        yeastar_extension: '',
        yeastar_caller_name: '',
    });

    const filteredMappings = useMemo(() => {
        const needle = search.trim().toLowerCase();
        if (!needle) {
            return mappings;
        }

        return mappings.filter(
            (m) =>
                m.yeastar_extension.toLowerCase().includes(needle) ||
                m.bitrix_user_id.toLowerCase().includes(needle) ||
                (m.bitrix_user_name ?? '').toLowerCase().includes(needle) ||
                (m.bitrix_user_email ?? '').toLowerCase().includes(needle),
        );
    }, [mappings, search]);

    const handleCreate = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mappings.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('bitrix_user_id', 'bitrix_user_name', 'bitrix_user_email', 'yeastar_extension', 'yeastar_caller_name');
                setDialogOpen(false);
            },
        });
    };

    const handleToggle = (row: MappingRow, field: 'click_to_call_enabled' | 'popup_enabled') => {
        router.patch(
            route('mappings.update', { mapping: row.id }),
            { [field]: !row[field] },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Extension Mappings" />

            <div className="flex flex-1 flex-col gap-6 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Bitrix24 User to Yeastar Extension Mappings</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Bind Bitrix24 CRM users to Yeastar P-Series SIP extensions for inbound popups and outbound Click-to-Call.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {hasBitrixConnection && yeastarConnections[0] ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        route('mappings.auto-match'),
                                        { yeastar_connection_id: yeastarConnections[0].id },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <AppIcon icon={Wand2} size="sm" />
                                <span>Auto-Match by Email</span>
                            </Button>
                        ) : null}
                        <Button type="button" onClick={() => setDialogOpen(true)} disabled={yeastarConnections.length === 0}>
                            <AppIcon icon={Plus} size="sm" />
                            <span>Add Mapping</span>
                        </Button>
                    </div>
                </div>

                <FlashBanner />

                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AppIcon icon={ArrowLeftRight} size="sm" />
                                <span>Directory Bindings ({mappings.length})</span>
                            </CardTitle>
                            <CardDescription>
                                Changes invalidate the per-tenant Redis hot-path lookup cache immediately.
                            </CardDescription>
                        </div>
                        <div className="relative w-full sm:w-64">
                            <span className="text-muted-foreground pointer-events-none absolute top-2.5 left-3">
                                <AppIcon icon={Search} size="xs" />
                            </span>
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Filter by extension, name, email..."
                                className="pl-8 text-xs"
                            />
                        </div>
                    </CardHeader>
                    <CardContent>
                        {filteredMappings.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title={mappings.length === 0 ? 'No Extension Mappings Configured' : 'No Matching Mappings'}
                                description="Run Auto-Match by Email after connecting Bitrix24 and Yeastar, or add a manual user-to-extension binding."
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-xs text-muted-foreground">
                                            <th className="pb-2.5 pr-4 font-medium">Bitrix24 User</th>
                                            <th className="pb-2.5 pr-4 font-medium">Yeastar Extension</th>
                                            <th className="pb-2.5 pr-4 font-medium">PBX</th>
                                            <th className="pb-2.5 pr-4 font-medium">Features</th>
                                            <th className="pb-2.5 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {filteredMappings.map((row) => (
                                            <tr key={row.id} className="hover:bg-muted/40">
                                                <td className="py-2.5 pr-4">
                                                    <div className="font-medium">
                                                        {row.bitrix_user_name ?? `User #${row.bitrix_user_id}`}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        ID {row.bitrix_user_id}
                                                        {row.bitrix_user_email ? ` · ${row.bitrix_user_email}` : ''}
                                                    </div>
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <Badge variant="outline" className="font-mono text-xs">
                                                        {row.yeastar_extension}
                                                    </Badge>
                                                    {row.yeastar_caller_name ? (
                                                        <span className="text-muted-foreground ml-2 text-xs">
                                                            {row.yeastar_caller_name}
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="py-2.5 pr-4 text-xs">
                                                    <Badge variant="secondary">{row.yeastar_connection_name ?? '-'}</Badge>
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <div className="flex flex-wrap gap-1.5">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant={row.click_to_call_enabled ? 'default' : 'outline'}
                                                            className="h-7 px-2 text-xs"
                                                            onClick={() => handleToggle(row, 'click_to_call_enabled')}
                                                        >
                                                            <AppIcon icon={PhoneOutgoing} size="xs" />
                                                            <span>Click-to-Call</span>
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant={row.popup_enabled ? 'default' : 'outline'}
                                                            className="h-7 px-2 text-xs"
                                                            onClick={() => handleToggle(row, 'popup_enabled')}
                                                        >
                                                            <AppIcon icon={Bell} size="xs" />
                                                            <span>Call Popup</span>
                                                        </Button>
                                                    </div>
                                                </td>
                                                <td className="py-2.5 text-right">
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        aria-label="Remove mapping"
                                                        onClick={() =>
                                                            router.delete(route('mappings.destroy', { mapping: row.id }), {
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

                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add Extension Mapping</DialogTitle>
                            <DialogDescription>
                                Manually associate a Bitrix24 CRM User ID with a Yeastar P-Series SIP extension.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={handleCreate} className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="map-pbx">Yeastar PBX</Label>
                                <Select
                                    value={form.data.yeastar_connection_id}
                                    onValueChange={(value) => form.setData('yeastar_connection_id', value)}
                                >
                                    <SelectTrigger id="map-pbx">
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
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="map-b24-id">Bitrix24 User ID</Label>
                                    <Input
                                        id="map-b24-id"
                                        value={form.data.bitrix_user_id}
                                        onChange={(e) => form.setData('bitrix_user_id', e.target.value)}
                                        placeholder="1269"
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="map-ext">Yeastar Extension</Label>
                                    <Input
                                        id="map-ext"
                                        value={form.data.yeastar_extension}
                                        onChange={(e) => form.setData('yeastar_extension', e.target.value)}
                                        placeholder="1001"
                                        required
                                    />
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="map-name">Employee Name</Label>
                                    <Input
                                        id="map-name"
                                        value={form.data.bitrix_user_name}
                                        onChange={(e) => form.setData('bitrix_user_name', e.target.value)}
                                        placeholder="Alex Morgan"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="map-email">Work Email</Label>
                                    <Input
                                        id="map-email"
                                        type="email"
                                        value={form.data.bitrix_user_email}
                                        onChange={(e) => form.setData('bitrix_user_email', e.target.value)}
                                        placeholder="alex@company.com"
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? 'Saving...' : 'Save Mapping'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
