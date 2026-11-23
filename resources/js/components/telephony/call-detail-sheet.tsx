import { AppIcon } from '@/components/common/app-icon';
import { CallDirectionBadge } from '@/components/telephony/call-direction-badge';
import { StreamAudioPlayer } from '@/components/telephony/stream-audio-player';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { type CallDirectionValue, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, Clock, ExternalLink, FileAudio, PhoneCall, ShieldCheck } from 'lucide-react';

export interface CallDetailItem {
    id: string;
    yeastar_connection_name: string | null;
    yeastar_call_id: string;
    bitrix_call_id: string | null;
    direction: CallDirectionValue;
    caller_number: string;
    callee_number: string;
    extension: string | null;
    bitrix_user_id: string | null;
    crm_entity_type: string | null;
    crm_entity_id: string | null;
    crm_activity_id: string | null;
    status_code: string;
    duration_sec: number;
    talk_duration_sec: number;
    recording_status: string;
    has_recording?: boolean;
    bitrix_file_id: string | null;
    started_at: string | null;
    answered_at: string | null;
    ended_at: string | null;
}

interface CallDetailSheetProps {
    call: CallDetailItem | null;
    bitrixDomain: string | null;
    onClose: () => void;
}

function buildCrmUrl(domain: string | null, type: string | null, id: string | null): string | null {
    if (!domain || !type || !id) {
        return null;
    }

    const segment = type.toLowerCase();
    if (!['contact', 'lead', 'company', 'deal'].includes(segment)) {
        return null;
    }

    return `https://${domain}/crm/${segment}/details/${encodeURIComponent(id)}/`;
}

function formatIso(iso: string | null): string {
    if (!iso) {
        return 'Not recorded';
    }

    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function formatSeconds(sec: number): string {
    const mins = Math.floor(sec / 60);
    const rem = sec % 60;
    return `${mins}m ${rem.toString().padStart(2, '0')}s`;
}

export function CallDetailSheet({ call, bitrixDomain, onClose }: CallDetailSheetProps) {
    const { tenancy } = usePage<SharedData>().props;
    const canStreamRecordings = tenancy?.active?.permissions?.stream_recordings ?? false;
    const crmUrl = call ? buildCrmUrl(bitrixDomain, call.crm_entity_type, call.crm_entity_id) : null;

    return (
        <Sheet open={call !== null} onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                {call ? (
                    <>
                        <SheetHeader>
                            <div className="flex items-center gap-2">
                                <CallDirectionBadge direction={call.direction} statusCode={call.status_code} />
                                <Badge variant="secondary" className="font-mono text-xs">
                                    SIP {call.status_code}
                                </Badge>
                            </div>
                            <SheetTitle className="mt-2 font-mono text-base">
                                {call.caller_number} -&gt; {call.callee_number}
                            </SheetTitle>
                            <SheetDescription>
                                Correlated call record from {call.yeastar_connection_name ?? 'Yeastar PBX'}.
                            </SheetDescription>
                        </SheetHeader>

                        <div className="mt-6 space-y-5">
                            <section className="space-y-2.5">
                                <h3 className="text-muted-foreground text-xs font-semibold uppercase tracking-wider">
                                    Lifecycle Timeline
                                </h3>
                                <ol className="space-y-2 rounded-lg border p-3.5 text-xs">
                                    <li className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-2 font-medium">
                                            <AppIcon icon={PhoneCall} size="xs" />
                                            Started
                                        </span>
                                        <span className="text-muted-foreground font-mono">{formatIso(call.started_at)}</span>
                                    </li>
                                    <li className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-2 font-medium">
                                            <AppIcon icon={CheckCircle2} size="xs" />
                                            Answered
                                        </span>
                                        <span className="text-muted-foreground font-mono">{formatIso(call.answered_at)}</span>
                                    </li>
                                    <li className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-2 font-medium">
                                            <AppIcon icon={Clock} size="xs" />
                                            Ended
                                        </span>
                                        <span className="text-muted-foreground font-mono">{formatIso(call.ended_at)}</span>
                                    </li>
                                </ol>
                            </section>

                            <Separator />

                            <section className="space-y-2.5">
                                <h3 className="text-muted-foreground text-xs font-semibold uppercase tracking-wider">
                                    PBX &amp; Bitrix24 Correlation
                                </h3>
                                <dl className="grid grid-cols-2 gap-3 rounded-lg border p-3.5 text-xs">
                                    <div>
                                        <dt className="text-muted-foreground">Extension</dt>
                                        <dd className="mt-0.5 font-mono font-medium">{call.extension ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Bitrix24 User ID</dt>
                                        <dd className="mt-0.5 font-mono font-medium">{call.bitrix_user_id ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Talk Duration</dt>
                                        <dd className="mt-0.5 font-mono font-medium">{formatSeconds(call.talk_duration_sec)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">CRM Activity ID</dt>
                                        <dd className="mt-0.5 font-mono font-medium">{call.crm_activity_id ?? '-'}</dd>
                                    </div>
                                    <div className="col-span-2">
                                        <dt className="text-muted-foreground">Yeastar Call ID</dt>
                                        <dd className="mt-0.5 truncate font-mono">{call.yeastar_call_id}</dd>
                                    </div>
                                    <div className="col-span-2">
                                        <dt className="text-muted-foreground">Bitrix24 Call ID</dt>
                                        <dd className="mt-0.5 truncate font-mono">{call.bitrix_call_id ?? 'Not registered'}</dd>
                                    </div>
                                </dl>

                                {crmUrl ? (
                                    <Button type="button" variant="outline" size="sm" className="w-full" asChild>
                                        <a href={crmUrl} target="_blank" rel="noreferrer">
                                            <span>
                                                Open {call.crm_entity_type} #{call.crm_entity_id} in Bitrix24
                                            </span>
                                            <AppIcon icon={ExternalLink} size="xs" />
                                        </a>
                                    </Button>
                                ) : null}
                            </section>

                            <Separator />

                            <section className="space-y-2.5">
                                <h3 className="text-muted-foreground text-xs font-semibold uppercase tracking-wider">
                                    Recording Status (Zero Server Storage)
                                </h3>
                                <div className="space-y-3 rounded-lg border p-3.5 text-xs">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-2 font-medium">
                                            <AppIcon icon={FileAudio} size="sm" />
                                            <span className="capitalize">{call.recording_status}</span>
                                        </span>
                                        {call.bitrix_file_id ? (
                                            <Badge variant="secondary" className="font-mono text-[11px]">
                                                Bitrix File #{call.bitrix_file_id}
                                            </Badge>
                                        ) : null}
                                    </div>
                                    {call.has_recording && canStreamRecordings ? (
                                        <StreamAudioPlayer streamUrl={route('calls.recording', { callLogId: call.id })} />
                                    ) : (
                                        <p className="text-muted-foreground flex items-center gap-1.5">
                                            <AppIcon icon={ShieldCheck} size="xs" />
                                            <span>Audio is streamed directly between Yeastar and Bitrix24; zero media files are stored locally.</span>
                                        </p>
                                    )}
                                </div>
                            </section>
                        </div>
                    </>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
