import { AppIcon } from '@/components/common/app-icon';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export function TenantSwitcher() {
    const { tenancy } = usePage<SharedData>().props;
    const [createOpen, setCreateOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        plan_tier: 'standard',
    });

    const activeTenant = tenancy?.active ?? null;
    const workspaces = tenancy?.workspaces ?? [];

    const handleSwitch = (tenantId: string) => {
        if (tenantId === activeTenant?.id) {
            return;
        }

        router.post(
            route('tenants.switch'),
            { tenant_id: tenantId },
            { preserveScroll: false },
        );
    };

    const handleCreate = (event: FormEvent) => {
        event.preventDefault();
        post(route('tenants.store'), {
            onSuccess: () => {
                reset();
                setCreateOpen(false);
            },
        });
    };

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <SidebarMenuButton
                                size="lg"
                                className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                            >
                                <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                                    <AppIcon icon={Building2} size="sm" />
                                </div>
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">{activeTenant?.name ?? 'Select Workspace'}</span>
                                    <span className="text-muted-foreground truncate text-xs capitalize">
                                        {activeTenant ? `${activeTenant.plan_tier} plan` : 'Multi-Tenant PBX'}
                                    </span>
                                </div>
                                <AppIcon icon={ChevronsUpDown} size="sm" className="ml-auto opacity-70" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-64 rounded-lg" align="start" sideOffset={4}>
                            <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">
                                Tenant Workspaces
                            </DropdownMenuLabel>
                            {workspaces.map((workspace) => {
                                const isCurrent = workspace.id === activeTenant?.id;

                                return (
                                    <DropdownMenuItem
                                        key={workspace.id}
                                        onClick={() => handleSwitch(workspace.id)}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <AppIcon icon={Building2} size="xs" className="text-muted-foreground" />
                                            <div className="min-w-0">
                                                <div className="truncate text-sm font-medium">{workspace.name}</div>
                                                <div className="text-muted-foreground text-xs capitalize">
                                                    {workspace.role ?? 'member'} · {workspace.plan_tier}
                                                </div>
                                            </div>
                                        </div>
                                        {isCurrent ? <AppIcon icon={Check} size="xs" className="text-emerald-600" /> : null}
                                    </DropdownMenuItem>
                                );
                            })}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => setCreateOpen(true)} className="gap-2">
                                <AppIcon icon={Plus} size="xs" />
                                <span>Create Tenant Workspace</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Create Tenant Workspace</DialogTitle>
                        <DialogDescription>
                            Provision an isolated workspace for a Bitrix24 portal and its Yeastar P-Series PBX connections.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleCreate} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="tenant-name">Organization Name</Label>
                            <Input
                                id="tenant-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Acme Telecom Operations"
                                required
                            />
                            {errors.name ? <p className="text-destructive text-xs">{errors.name}</p> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="tenant-plan">Bitrix24 Rate Tier</Label>
                            <Select value={data.plan_tier} onValueChange={(value) => setData('plan_tier', value)}>
                                <SelectTrigger id="tenant-plan">
                                    <SelectValue placeholder="Select plan tier" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="standard">Standard (2 req/s, burst 50)</SelectItem>
                                    <SelectItem value="enterprise">Enterprise (5 req/s, burst 250)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Provisioning...' : 'Create Workspace'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
