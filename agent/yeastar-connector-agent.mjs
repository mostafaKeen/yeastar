#!/usr/bin/env node
/**
 * Yeastar P-Series On-Premise Connector Agent (Outbound-Only)
 *
 * Bridges a private-LAN Yeastar P-Series PBX behind corporate NAT to the
 * multi-tenant Bitrix24-Yeastar SaaS control plane with zero inbound firewall ports.
 *
 * Accepts CLI arguments or environment variables:
 *   --saas-url=https://yeastar.keenenter.com
 *   --agent-token=ysa_...
 *   --pbx-url=https://10.255.254.200:8088
 *   --client-id=...
 *   --client-secret=...
 */

// Parse CLI flags and environment variables
const args = process.argv.slice(2);
function getArg(name, envVar, fallback = '') {
    for (const arg of args) {
        if (arg.startsWith(`--${name}=`)) {
            return arg.slice(name.length + 3).trim();
        }
    }
    return (process.env[envVar] || fallback).trim();
}

const SAAS_URL = (getArg('saas-url', 'SAAS_URL') || getArg('cloud-url', 'CLOUD_URL')).replace(/\/+$/, '');
const AGENT_TOKEN = getArg('agent-token', 'AGENT_TOKEN') || getArg('token', 'TOKEN');
const PBX_URL = getArg('pbx-url', 'PBX_URL').replace(/\/+$/, '');
const PBX_CLIENT_ID = getArg('client-id', 'PBX_CLIENT_ID') || getArg('pbx-client-id', 'PBX_CLIENT_ID');
const PBX_CLIENT_SECRET = getArg('client-secret', 'PBX_CLIENT_SECRET') || getArg('pbx-client-secret', 'PBX_CLIENT_SECRET');
const POLL_INTERVAL_MS = Number(getArg('poll-interval', 'POLL_INTERVAL_MS', '2000'));

// Local PBXs on private IPs (10.x, 192.168.x, 172.16-31.x) use self-signed TLS certificates
const isPrivateIp = /^https:\/\/(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|127\.)/i.test(PBX_URL);
if (isPrivateIp || args.includes('--insecure') || process.env.ALLOW_INSECURE === '1') {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

if (!SAAS_URL || !AGENT_TOKEN || !PBX_URL || !PBX_CLIENT_ID || !PBX_CLIENT_SECRET) {
    console.error(`
Usage:
  node agent/yeastar-connector-agent.mjs \\
    --saas-url="https://yeastar.keenenter.com" \\
    --agent-token="<AGENT_TOKEN>" \\
    --pbx-url="https://10.255.254.200:8088" \\
    --client-id="<CLIENT_ID>" \\
    --client-secret="<CLIENT_SECRET>"

Or set environment variables:
  SAAS_URL, AGENT_TOKEN, PBX_URL, PBX_CLIENT_ID, PBX_CLIENT_SECRET
`);
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
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'User-Agent': 'OpenAPI',
        },
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
    const init = {
        method: upper,
        headers: {
            Accept: 'application/json',
            'User-Agent': 'OpenAPI',
        },
    };

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
