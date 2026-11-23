import { LucideIcon } from 'lucide-react';

export type TenantRoleValue = 'owner' | 'admin' | 'supervisor' | 'agent';
export type ConnectionStatusValue = 'pending' | 'connected' | 'degraded' | 'disconnected' | 'revoked';
export type YeastarTransportModeValue = 'direct_https' | 'tunnel_agent';
export type CallDirectionValue = 'inbound' | 'outbound' | 'internal' | 'callback';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface TenantPermissions {
    manage_integrations: boolean;
    manage_security: boolean;
    view_all_calls: boolean;
    stream_recordings: boolean;
}

export interface TenantWorkspace {
    id: string;
    name: string;
    slug: string;
    plan_tier: 'standard' | 'enterprise' | string;
    status: string;
    role: TenantRoleValue | null;
}

export interface ActiveTenant extends TenantWorkspace {
    permissions: TenantPermissions;
}

export interface TenancySharedData {
    active: ActiveTenant | null;
    workspaces: TenantWorkspace[];
}

export interface GeneratedAgentToken {
    connection_id: string;
    connection_name: string;
    token: string;
}

export interface FlashMessages {
    status: string | null;
    error: string | null;
    generated_agent_token: GeneratedAgentToken | null;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    tenancy: TenancySharedData;
    flash?: FlashMessages;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    is_super_admin?: boolean;
    email_verified_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    [key: string]: unknown;
}

export interface BitrixConnectionSummary {
    id: string;
    domain: string;
    portal_type: 'cloud' | 'on_premise' | string;
    status: ConnectionStatusValue;
    granted_scopes: string[];
    token_expires_at: string | null;
    last_synced_at: string | null;
}

export interface YeastarConnectionSummary {
    id: string;
    name: string;
    transport_mode: YeastarTransportModeValue;
    pbx_base_url: string | null;
    firmware_version: string | null;
    agent_status: 'online' | 'offline' | string;
    agent_token_prefix: string | null;
    status: ConnectionStatusValue;
    last_heartbeat_at: string | null;
}

export interface CallMetricsSummary {
    total_24h: number;
    inbound_24h: number;
    outbound_24h: number;
    missed_24h: number;
}

export interface CallLogSummary {
    id: string;
    direction: CallDirectionValue;
    caller_number: string;
    callee_number: string;
    extension: string | null;
    crm_entity_type: string | null;
    crm_entity_id: string | null;
    status_code: string;
    duration_sec: number;
    recording_status: 'none' | 'pending' | 'attached' | 'failed' | string;
    started_at: string | null;
}

export interface DashboardPageProps {
    connections: {
        bitrix: BitrixConnectionSummary | null;
        yeastar: YeastarConnectionSummary[];
    };
    summary: {
        mapped_extensions: number;
        external_lines: number;
        rate_limit_rps: number;
        rate_burst_limit: number;
        recording_storage_mode: string;
    };
    callMetrics?: CallMetricsSummary;
    recentCalls?: CallLogSummary[];
}
