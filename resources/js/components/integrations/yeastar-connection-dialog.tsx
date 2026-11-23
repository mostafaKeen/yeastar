import { AppIcon } from '@/components/common/app-icon';
import { CopyableField } from '@/components/common/copyable-field';
import { SecretInput } from '@/components/common/secret-input';
import { Button } from '@/components/ui/button';
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
import { cn } from '@/lib/utils';
import { type YeastarTransportModeValue } from '@/types';
import { useForm } from '@inertiajs/react';
import { Cable, Globe, ShieldCheck } from 'lucide-react';
import { type FormEvent } from 'react';

interface YeastarConnectionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    staticEgressIps: string[];
    minFirmware: string;
}

export function YeastarConnectionDialog({
    open,
    onOpenChange,
    staticEgressIps,
    minFirmware,
}: YeastarConnectionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        transport_mode: YeastarTransportModeValue;
        pbx_base_url: string;
        client_id: string;
        client_secret: string;
        allowed_ips: string;
    }>({
        name: '',
        transport_mode: 'direct_https',
        pbx_base_url: '',
        client_id: '',
        client_secret: '',
        allowed_ips: '',
    });

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        post(route('integrations.yeastar.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Connect Yeastar P-Series PBX</DialogTitle>
                    <DialogDescription>
                        Requires Yeastar P-Series Enterprise or Ultimate Plan with firmware {minFirmware}+ and Open API enabled.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-3">
                        <button
                            type="button"
                            onClick={() => setData('transport_mode', 'direct_https')}
                            className={cn(
                                'flex flex-col items-start gap-1.5 rounded-lg border p-3 text-left transition-colors',
                                data.transport_mode === 'direct_https'
                                    ? 'border-foreground bg-accent/60'
                                    : 'border-border hover:border-foreground/30',
                            )}
                        >
                            <div className="flex items-center gap-2 text-sm font-semibold">
                                <AppIcon icon={Globe} size="sm" />
                                <span>Direct HTTPS (FQDN)</span>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Connect directly over TLS to Yeastar FQDN / RAS with SSRF & IP restrictions.
                            </p>
                        </button>

                        <button
                            type="button"
                            onClick={() => setData('transport_mode', 'tunnel_agent')}
                            className={cn(
                                'flex flex-col items-start gap-1.5 rounded-lg border p-3 text-left transition-colors',
                                data.transport_mode === 'tunnel_agent'
                                    ? 'border-foreground bg-accent/60'
                                    : 'border-border hover:border-foreground/30',
                            )}
                        >
                            <div className="flex items-center gap-2 text-sm font-semibold">
                                <AppIcon icon={Cable} size="sm" />
                                <span>On-Premise Agent</span>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Outbound-only encrypted tunnel for private-NAT PBXs with zero open ports.
                            </p>
                        </button>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="ys-name">Connection Label</Label>
                        <Input
                            id="ys-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="HQ P570 PBX"
                            required
                        />
                        {errors.name ? <p className="text-destructive text-xs">{errors.name}</p> : null}
                    </div>

                    {data.transport_mode === 'direct_https' ? (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="ys-url">Yeastar FQDN / HTTPS Base URL</Label>
                                <Input
                                    id="ys-url"
                                    type="url"
                                    value={data.pbx_base_url}
                                    onChange={(e) => setData('pbx_base_url', e.target.value)}
                                    placeholder="https://company.ras.yeastar.com"
                                    required
                                />
                                {errors.pbx_base_url ? <p className="text-destructive text-xs">{errors.pbx_base_url}</p> : null}
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="ys-client-id">Open API Client ID</Label>
                                    <Input
                                        id="ys-client-id"
                                        value={data.client_id}
                                        onChange={(e) => setData('client_id', e.target.value)}
                                        placeholder="From Integrations > API"
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="ys-client-secret">Open API Client Secret</Label>
                                    <SecretInput
                                        id="ys-client-secret"
                                        value={data.client_secret}
                                        onChange={(e) => setData('client_secret', e.target.value)}
                                        placeholder="Encrypted at rest"
                                        required
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="ys-allowed-ips">Allowed PBX Webhook Source IPs (Optional, comma-separated)</Label>
                                <Input
                                    id="ys-allowed-ips"
                                    value={data.allowed_ips}
                                    onChange={(e) => setData('allowed_ips', e.target.value)}
                                    placeholder="203.0.113.10, 203.0.113.11"
                                />
                            </div>

                            {staticEgressIps.length > 0 ? (
                                <div className="bg-muted/40 space-y-1.5 rounded-md border p-3">
                                    <div className="flex items-center gap-1.5 text-xs font-medium">
                                        <AppIcon icon={ShieldCheck} size="xs" />
                                        <span>Allowlist our egress IP(s) in Yeastar Integrations &gt; API &gt; IP Restriction</span>
                                    </div>
                                    <CopyableField value={staticEgressIps.join(', ')} label="Platform Egress IPs" />
                                </div>
                            ) : null}
                        </>
                    ) : (
                        <div className="bg-muted/40 space-y-2 rounded-md border p-3.5 text-xs leading-relaxed">
                            <div className="flex items-center gap-2 font-semibold">
                                <AppIcon icon={ShieldCheck} size="sm" />
                                <span>Zero Inbound Ports Required</span>
                            </div>
                            <p className="text-muted-foreground">
                                Saving this connection generates a one-time SHA-256 hashed bearer token. Run the lightweight Connector Agent inside your corporate LAN to bridge your Yeastar P-Series Open API outbound over HTTPS.
                            </p>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving...'
                                : data.transport_mode === 'tunnel_agent'
                                  ? 'Create & Generate Agent Token'
                                  : 'Save PBX Connection'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
