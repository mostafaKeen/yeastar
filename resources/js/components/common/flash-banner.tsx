import { AppIcon } from '@/components/common/app-icon';
import { CopyableField } from '@/components/common/copyable-field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, KeyRound, Terminal } from 'lucide-react';

interface FlashBannerProps {
    saasUrl?: string;
}

export function FlashBanner({ saasUrl }: FlashBannerProps) {
    const { flash } = usePage<SharedData>().props;

    if (!flash?.status && !flash?.error && !flash?.generated_agent_token) {
        return null;
    }

    const tokenData = flash.generated_agent_token;
    const baseUrl = saasUrl ?? window.location.origin;

    return (
        <div className="space-y-3">
            {flash.status ? (
                <Alert className="border-emerald-500/30 bg-emerald-500/5">
                    <AppIcon icon={CheckCircle2} size="sm" className="text-emerald-600" />
                    <AlertTitle>Success</AlertTitle>
                    <AlertDescription>{flash.status}</AlertDescription>
                </Alert>
            ) : null}

            {flash.error ? (
                <Alert variant="destructive">
                    <AppIcon icon={AlertTriangle} size="sm" />
                    <AlertTitle>Action Required</AlertTitle>
                    <AlertDescription>{flash.error}</AlertDescription>
                </Alert>
            ) : null}

            {tokenData ? (
                <Alert className="border-amber-500/40 bg-amber-500/5">
                    <AppIcon icon={KeyRound} size="sm" className="text-amber-600" />
                    <AlertTitle>One-Time On-Premise Agent Token ({tokenData.connection_name})</AlertTitle>
                    <AlertDescription className="mt-2 space-y-3">
                        <p className="text-xs">
                            Copy and store this bearer token securely. Only its SHA-256 hash is persisted on the server; it will not be shown again.
                        </p>
                        <CopyableField value={tokenData.token} label="On-Premise Agent Bearer Token" />
                        <div className="space-y-1.5">
                            <div className="flex items-center gap-1.5 text-xs font-medium">
                                <AppIcon icon={Terminal} size="xs" />
                                <span>Start the Outbound-Only Connector Agent inside your PBX LAN</span>
                            </div>
                            <CopyableField
                                value={`SAAS_URL="${baseUrl}" AGENT_TOKEN="${tokenData.token}" PBX_URL="https://<pbx-lan-ip>:8088" PBX_CLIENT_ID="<id>" PBX_CLIENT_SECRET="<secret>" node agent/yeastar-connector-agent.mjs`}
                                label="Connector Agent Launch Command"
                            />
                        </div>
                    </AlertDescription>
                </Alert>
            ) : null}
        </div>
    );
}
