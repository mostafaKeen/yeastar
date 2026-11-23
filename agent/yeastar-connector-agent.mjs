#!/usr/bin/env node
/**
 * Yeastar P-Series On-Premise Connector Agent (Outbound-Only)
 *
 * Bridges a private-LAN Yeastar P-Series PBX behind corporate NAT to the
 * multi-tenant Bitrix24-Yeastar SaaS control plane with zero inbound firewall ports.
 *
 * Required environment variables:
 *   SAAS_URL           e.g. https://telephony.your-domain.com
 *   AGENT_TOKEN        One-time bearer token generated in Integrations (ysa_...)
 *   PBX_URL            Local LAN PBX URL, e.g. https://192.168.1.150:8088
 *   PBX_CLIENT_ID      Yeastar P-Series Open API Client ID
 *   PBX_CLIENT_SECRET  Yeastar P-Series Open API Client Secret
 */

const SAAS_URL = (process.env.SAAS_URL || '').replace(/\/+$/, '');
const AGENT_TOKEN = process.env.AGENT_TOKEN || '';
const PBX_URL = (process.env.PBX_URL || '').replace(/\/+$/, '');
const PBX_CLIENT_ID = process.env.PBX_CLIENT_ID || '';
const PBX_CLIENT_SECRET = process.env.PBX_CLIENT_SECRET || '';
const POLL_INTERVAL_MS = Number(process.env.POLL_INTERVAL_MS || 2000);

if (!SAAS_URL || !AGENT_TOKEN || !PBX_URL || !PBX_CLIENT_ID || !PBX_CLIENT_SECRET) {
    console.error('[agent] Missing required env vars: SAAS_URL, AGENT_TOKEN, PBX_URL, PBX_CLIENT_ID, PBX_CLIENT_SECRET');
    process.exit(1);
}

let pbxAccessToken = null;
let pbxTokenExpiresAt = 0;

async function ensurePbxToken() {
    if (pbxAccessToken && Date.now() < pbxTokenExpiresAt - 300_000) {
        return pbxAccessToken;
    }

    const res = await fetch(`${PBX_URL}/openapi/v1.0/get_token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ username: PBX_CLIENT_ID, password: PBX_CLIENT_SECRET }),
    });

    const body = await res.json();
    if (!res.ok || Number(body.errcode) !== 0 || !body.access_token) {
        throw new Error(`PBX auth failed: ${body.errmsg || res.status}`);
    }

    pbxAccessToken = body.access_token;
    pbxTokenExpiresAt = Date.now() + Number(body.access_token_expire_time || 1800) * 1000;
    return pbxAccessToken;
}

async function callLocalPbx(method, path, payload = {}) {
    const token = await ensurePbxToken();
    const url = new URL(`${PBX_URL}/openapi/v1.0${path}`);
    url.searchParams.set('access_token', token);

    const upper = method.toUpperCase();
    const init = { method: upper, headers: { Accept: 'application/json' } };

    if (upper === 'GET') {
        for (const [k, v] of Object.entries(payload || {})) {
            url.searchParams.set(k, String(v));
        }
    } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(payload || {});
    }

    const res = await fetch(url, init);
    const data = await res.json();
    return { status: res.status, data };
}

async function saasPost(path, payload) {
    const res = await fetch(`${SAAS_URL}${path}`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${AGENT_TOKEN}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify(payload),
    });

    if (!res.ok) {
        throw new Error(`SaaS gateway ${path} returned HTTP ${res.status}`);
    }

    return res.json();
}

async function sendHeartbeat() {
    try {
        const info = await callLocalPbx('GET', '/system/info');
        const firmware = info.data?.firmware_version || info.data?.data?.firmware_version || null;
        await saasPost('/api/agent/heartbeat', { firmware_version: firmware, pbx_reachable: true });
    } catch (error) {
        console.warn('[agent] Heartbeat degraded:', error.message);
        try {
            await saasPost('/api/agent/heartbeat', { pbx_reachable: false });
        } catch {
            // Will retry on next interval
        }
    }
}

async function pollLoop() {
    try {
        const { commands = [] } = await saasPost('/api/agent/poll', {});
        for (const cmd of commands) {
            try {
                const { status, data } = await callLocalPbx(cmd.method, cmd.path, cmd.payload);
                const ok = status >= 200 && status < 300 && Number(data?.errcode ?? 0) === 0;
                await saasPost('/api/agent/respond', {
                    request_id: cmd.request_id,
                    ok,
                    http_status: status,
                    errcode: data?.errcode ?? null,
                    errmsg: data?.errmsg ?? null,
                    data,
                });
            } catch (error) {
                await saasPost('/api/agent/respond', {
                    request_id: cmd.request_id,
                    ok: false,
                    http_status: 502,
                    errcode: 'LOCAL_PBX_ERROR',
                    errmsg: error.message,
                });
            }
        }
    } catch (error) {
        console.warn('[agent] Poll failed:', error.message);
    } finally {
        setTimeout(pollLoop, POLL_INTERVAL_MS);
    }
}

console.log(`[agent] Starting Yeastar On-Premise Connector Agent -> ${SAAS_URL}`);
sendHeartbeat();
setInterval(sendHeartbeat, 30_000);
pollLoop();
