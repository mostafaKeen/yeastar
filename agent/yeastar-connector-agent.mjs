#!/usr/bin/env node
/**
 * Yeastar P-Series On-Premise Connector Agent (Outbound-Only)
 *
 * Bridges a private-LAN Yeastar P-Series PBX behind corporate NAT to the
 * multi-tenant Bitrix24-Yeastar SaaS control plane with zero inbound firewall ports.
 *
 * Supports TWO local PBX protocols:
 *   1. AMI Mode (Works on ALL Yeastar P-Series plans: Basic, Standard, Enterprise, Ultimate)
 *      --saas-url="https://yeastar.keenenter.com"
 *      --agent-token="ysa_..."
 *      --pbx-host="10.255.254.200"
 *      --ami-port="5038"
 *      --ami-user="bitrix_ami"
 *      --ami-secret="<AMI_PASSWORD>"
 *
 *   2. OpenAPI Mode (Requires Yeastar Enterprise / Ultimate Plan)
 *      --saas-url="https://yeastar.keenenter.com"
 *      --agent-token="ysa_..."
 *      --pbx-url="https://10.255.254.200:8088"
 *      --client-id="<CLIENT_ID>"
 *      --client-secret="<CLIENT_SECRET>"
 */

import net from 'node:net';
import crypto from 'node:crypto';

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

// AMI arguments (auto-extract host from --pbx-url if --pbx-host omitted)
let defaultHost = '';
if (PBX_URL) {
    try {
        defaultHost = new URL(PBX_URL).hostname;
    } catch {
        defaultHost = '';
    }
}
const PBX_HOST = getArg('pbx-host', 'PBX_HOST', defaultHost);
const AMI_PORT = Number(getArg('ami-port', 'AMI_PORT', '5038'));
const AMI_USER = getArg('ami-user', 'AMI_USER');
const AMI_SECRET = getArg('ami-secret', 'AMI_SECRET');
const POLL_INTERVAL_MS = Number(getArg('poll-interval', 'POLL_INTERVAL_MS', '2000'));

const USE_AMI = Boolean(AMI_USER && AMI_SECRET && PBX_HOST);
const USE_OPENAPI = Boolean(!USE_AMI && PBX_URL && PBX_CLIENT_ID && PBX_CLIENT_SECRET);

const isPrivateIp = /^https:\/\/(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|127\.)/i.test(PBX_URL);
if (isPrivateIp || args.includes('--insecure') || process.env.ALLOW_INSECURE === '1') {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

if (!SAAS_URL || !AGENT_TOKEN || (!USE_AMI && !USE_OPENAPI)) {
    console.error(`
Usage (AMI Mode - works on ALL Yeastar P-Series plans including Basic/Standard):
  node yeastar-connector-agent.mjs \\
    --saas-url="https://yeastar.keenenter.com" \\
    --agent-token="<AGENT_TOKEN>" \\
    --pbx-host="10.255.254.200" \\
    --ami-port="5038" \\
    --ami-user="<AMI_USERNAME>" \\
    --ami-secret="<AMI_PASSWORD>"

Usage (OpenAPI Mode - requires Yeastar Enterprise/Ultimate Plan):
  node yeastar-connector-agent.mjs \\
    --saas-url="https://yeastar.keenenter.com" \\
    --agent-token="<AGENT_TOKEN>" \\
    --pbx-url="https://10.255.254.200:8088" \\
    --client-id="<CLIENT_ID>" \\
    --client-secret="<CLIENT_SECRET>"
`);
    process.exit(1);
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

// ============================================================================
// MODE 1: Asterisk Manager Interface (AMI - TCP 5038)
// ============================================================================
let amiSocket = null;
let amiConnected = false;
let amiBuffer = '';
let amiSeq = 1;
const pendingAmi = new Map();
const activeCalls = new Map();

function parseAmiPacket(raw) {
    const pkt = {};
    for (const line of raw.split(/\r?\n/)) {
        const idx = line.indexOf(':');
        if (idx > 0) {
            const k = line.slice(0, idx).trim();
            const v = line.slice(idx + 1).trim();
            pkt[k] = pkt[k] !== undefined ? `${pkt[k]}\n${v}` : v;
        }
    }
    return pkt;
}

function extractPjsipPeer(channel = '') {
    const m = /^PJSIP\/([^/-]+)-/i.exec(channel);
    return m ? m[1] : null;
}

function isNumericExt(peer) {
    return Boolean(peer && /^\d+$/.test(peer));
}

function parseCallerIdHeader(raw = '', fallbackNum = '') {
    const m = /^"?([^"<]*)"?\s*<([^>]*)>/.exec(raw.trim());
    if (m) {
        return {
            name: m[1].trim() || m[2].trim() || fallbackNum,
            number: m[2].trim() || fallbackNum,
        };
    }
    return { name: raw.trim() || fallbackNum, number: fallbackNum };
}

function getOrCreateCall(callId) {
    let call = activeCalls.get(callId);
    if (!call) {
        call = {
            callId,
            isClickToCall: callId.startsWith('c2c_'),
            direction: 'unknown',
            callerNumber: null,
            calleeNumber: null,
            targetExtensions: new Set(),
            answeredBy: null,
            ringTimer: null,
            ringSent: false,
            answerSent: false,
            hangupSent: false,
            startedAt: Date.now(),
            answeredAt: null,
        };
        activeCalls.set(callId, call);
    }
    return call;
}

function scheduleRingingPush(call) {
    if (call.ringSent || call.isClickToCall || call.ringTimer) {
        return;
    }
    call.ringTimer = setTimeout(async () => {
        call.ringTimer = null;
        if (call.ringSent || call.targetExtensions.size === 0) {
            return;
        }
        call.ringSent = true;
        const targets = [...call.targetExtensions];
        const payload = {
            event: 'ringing',
            msg_id: `${call.callId}_ring`,
            call_id: call.callId,
            caller_number: call.callerNumber || 'unknown',
            callee_number: call.calleeNumber || targets[0],
            target_extensions: targets,
        };
        try {
            await saasPost('/api/agent/events', payload);
            console.log(`[agent][ami] Inbound ringing pushed: ${payload.caller_number} -> [${targets.join(', ')}]`);
        } catch (err) {
            console.warn('[agent][ami] Failed pushing ringing event:', err.message);
        }
    }, 250);
}

async function handleAmiEvent(pkt) {
    const ev = pkt.Event;
    if (!ev) return;

    const callId = pkt.Linkedid || pkt.Uniqueid;
    if (!callId) return;

    const peer = extractPjsipPeer(pkt.Channel || '');

    if (ev === 'Newchannel') {
        const call = getOrCreateCall(callId);
        if (callId.startsWith('c2c_')) {
            call.isClickToCall = true;
            call.direction = 'outbound';
        } else if (peer && !isNumericExt(peer)) {
            call.direction = 'inbound';
            if (pkt.CallerIDNum && pkt.CallerIDNum !== '<unknown>') {
                call.callerNumber = pkt.CallerIDNum;
            }
            if (pkt.Exten && pkt.Exten !== 's') {
                call.calleeNumber = pkt.Exten;
            }
        } else if (isNumericExt(peer) && call.direction === 'unknown') {
            call.direction = 'internal_or_outbound';
            call.callerNumber = peer;
        }
        return;
    }

    if (ev === 'DialBegin') {
        const destPeer = extractPjsipPeer(pkt.DestChannel || '');
        const destId = pkt.DestLinkedid || callId;
        const call = getOrCreateCall(destId);
        if (isNumericExt(destPeer) && !call.isClickToCall && call.direction !== 'internal_or_outbound') {
            call.direction = 'inbound';
            call.targetExtensions.add(destPeer);
            if (!call.callerNumber && pkt.CallerIDNum && pkt.CallerIDNum !== '<unknown>') {
                call.callerNumber = pkt.CallerIDNum;
            }
            scheduleRingingPush(call);
        }
        return;
    }

    if (ev === 'Newstate') {
        const call = getOrCreateCall(callId);
        if (pkt.ChannelStateDesc === 'Ringing' && isNumericExt(peer) && !call.isClickToCall && call.direction === 'inbound') {
            call.targetExtensions.add(peer);
            if (!call.callerNumber && pkt.ConnectedLineNum && pkt.ConnectedLineNum !== '<unknown>') {
                call.callerNumber = pkt.ConnectedLineNum;
            }
            scheduleRingingPush(call);
        } else if (pkt.ChannelStateDesc === 'Up' && isNumericExt(peer)) {
            if (!call.answeredAt) {
                call.answeredAt = Date.now();
                call.answeredBy = peer;
            }
            if (call.direction === 'inbound' && !call.answerSent) {
                call.answerSent = true;
                try {
                    await saasPost('/api/agent/events', {
                        event: 'answered',
                        msg_id: `${call.callId}_ans`,
                        call_id: call.callId,
                        extension: peer,
                    });
                    console.log(`[agent][ami] Call answered by ext ${peer} (${call.callId})`);
                } catch (err) {
                    console.warn('[agent][ami] Failed pushing answered event:', err.message);
                }
            }
        }
        return;
    }

    if (ev === 'Hangup' && pkt.Uniqueid === pkt.Linkedid) {
        const call = activeCalls.get(callId);
        if (!call || call.hangupSent) return;
        if (!call.ringSent && !call.isClickToCall && !call.answeredBy) {
            activeCalls.delete(callId);
            return;
        }
        call.hangupSent = true;
        if (call.ringTimer) clearTimeout(call.ringTimer);

        const durationSec = Math.max(1, Math.round((Date.now() - call.startedAt) / 1000));
        const talkSec = call.answeredAt ? Math.max(1, Math.round((Date.now() - call.answeredAt) / 1000)) : 0;
        const disposition = call.answeredAt ? 'ANSWERED' : (pkt.Cause === '17' ? 'BUSY' : 'NO ANSWER');

        try {
            await saasPost('/api/agent/events', {
                event: 'hangup',
                msg_id: `${call.callId}_end`,
                call_id: call.callId,
                disposition,
                duration_sec: durationSec,
                talk_duration_sec: talkSec,
                extension: call.answeredBy || [...call.targetExtensions][0] || null,
            });
            console.log(`[agent][ami] Call ended (${disposition}, talk: ${talkSec}s, id: ${call.callId})`);
        } catch (err) {
            console.warn('[agent][ami] Failed pushing hangup event:', err.message);
        } finally {
            setTimeout(() => activeCalls.delete(callId), 30_000);
        }
    }
}

function sendRawAmi(action, fields = {}, completeEvent = null, timeoutMs = 8000) {
    return new Promise((resolve, reject) => {
        if (!amiSocket || amiSocket.destroyed) {
            return reject(new Error('AMI socket is not connected'));
        }
        const actionId = `act_${amiSeq++}_${Date.now()}`;
        const lines = [`Action: ${action}`, `ActionID: ${actionId}`];
        for (const [k, v] of Object.entries(fields)) {
            if (v !== undefined && v !== null) {
                lines.push(`${k}: ${v}`);
            }
        }
        const timer = setTimeout(() => {
            pendingAmi.delete(actionId);
            reject(new Error(`AMI action ${action} timed out`));
        }, timeoutMs);

        pendingAmi.set(actionId, {
            completeEvent,
            response: null,
            events: [],
            resolve: (val) => {
                clearTimeout(timer);
                resolve(val);
            },
            reject: (err) => {
                clearTimeout(timer);
                reject(err);
            },
        });

        amiSocket.write(lines.join('\r\n') + '\r\n\r\n');
    });
}

function connectAmi() {
    if (amiSocket && !amiSocket.destroyed) {
        amiSocket.destroy();
    }
    amiConnected = false;
    amiBuffer = '';

    const sock = net.createConnection({ host: PBX_HOST, port: AMI_PORT });
    amiSocket = sock;

    sock.setEncoding('utf8');
    sock.setKeepAlive(true, 15_000);

    sock.on('connect', async () => {
        console.log(`[agent][ami] TCP connected to ${PBX_HOST}:${AMI_PORT}, authenticating...`);
        try {
            const res = await sendRawAmi('Login', {
                Username: AMI_USER,
                Secret: AMI_SECRET,
                Events: 'on',
            });
            if (res.response?.Response === 'Success') {
                amiConnected = true;
                console.log(`[agent][ami] Authenticated to Yeastar AMI on ${PBX_HOST}:${AMI_PORT}`);
                sendHeartbeat();
            } else {
                console.error(`[agent][ami] Authentication rejected: ${res.response?.Message || 'Invalid credentials'}`);
                sock.destroy();
            }
        } catch (err) {
            console.error('[agent][ami] Login error:', err.message);
            sock.destroy();
        }
    });

    sock.on('data', (chunk) => {
        amiBuffer += chunk;
        if (amiBuffer.startsWith('Asterisk Call Manager')) {
            const nl = amiBuffer.indexOf('\n');
            if (nl === -1) return;
            amiBuffer = amiBuffer.slice(nl + 1);
        }

        let sep;
        while ((sep = amiBuffer.indexOf('\r\n\r\n')) !== -1) {
            const rawPacket = amiBuffer.slice(0, sep);
            amiBuffer = amiBuffer.slice(sep + 4);
            if (!rawPacket.trim()) continue;

            const pkt = parseAmiPacket(rawPacket);
            const actionId = pkt.ActionID;

            if (actionId && pendingAmi.has(actionId)) {
                const entry = pendingAmi.get(actionId);
                if (pkt.Response) {
                    entry.response = pkt;
                    if (pkt.Response === 'Error' || !entry.completeEvent) {
                        pendingAmi.delete(actionId);
                        entry.resolve({ response: entry.response, events: entry.events });
                    }
                } else if (pkt.Event) {
                    if (pkt.Event === entry.completeEvent) {
                        pendingAmi.delete(actionId);
                        entry.resolve({ response: entry.response, events: entry.events });
                    } else {
                        entry.events.push(pkt);
                    }
                }
            } else if (pkt.Event) {
                handleAmiEvent(pkt);
            }
        }
    });

    sock.on('error', (err) => {
        console.warn(`[agent][ami] Socket error (${PBX_HOST}:${AMI_PORT}): ${err.message}`);
    });

    sock.on('close', () => {
        amiConnected = false;
        for (const [, entry] of pendingAmi) {
            entry.reject(new Error('AMI connection closed'));
        }
        pendingAmi.clear();
        setTimeout(connectAmi, 4000);
    });
}

async function callAmiPbx(method, path, payload = {}) {
    if (!amiConnected) {
        throw new Error(`AMI not connected to ${PBX_HOST}:${AMI_PORT}`);
    }

    if (path === '/system/info') {
        return {
            status: 200,
            data: { errcode: 0, errmsg: 'SUCCESS', firmware_version: '37.18.0.0' },
        };
    }

    if (path === '/extension/list') {
        const { events } = await sendRawAmi('PJSIPShowEndpoints', {}, 'EndpointListComplete', 9000);
        const extensions = [];
        for (const ev of events) {
            const name = ev.ObjectName || '';
            if (!isNumericExt(name)) continue;
            const cid = parseCallerIdHeader(ev.Callerid || '', name);
            extensions.push({
                number: name,
                caller_id_name: cid.name || name,
                email_addr: null,
                presence_status: ev.DeviceState || 'Idle',
            });
        }
        return {
            status: 200,
            data: { errcode: 0, errmsg: 'SUCCESS', data: extensions },
        };
    }

    if (path === '/trunk/list') {
        const { events } = await sendRawAmi('PJSIPShowEndpoints', {}, 'EndpointListComplete', 9000);
        const trunks = [];
        for (const ev of events) {
            const name = ev.ObjectName || '';
            if (!name || isNumericExt(name) || name === 'anonymous') continue;
            trunks.push({
                id: name,
                name,
                trunk_type: 'sip',
                status: ev.DeviceState || 'OK',
            });
        }
        return {
            status: 200,
            data: { errcode: 0, errmsg: 'SUCCESS', data: trunks },
        };
    }

    if (path === '/call/dial') {
        const caller = String(payload.caller || '').trim().replace(/[^\dA-Za-z_-]/g, '');
        const rawCallee = String(payload.callee || '').trim();
        const cleanedCallee = rawCallee.replace(/[^\d+]/g, '');
        if (!caller || !cleanedCallee) {
            return { status: 400, data: { errcode: 400, errmsg: 'Missing caller or callee' } };
        }

        // Build ordered candidate dial strings (handles UAE +971 / 00971 / 971 -> 05xxxxxxxx local format)
        const extenCandidates = [];
        const addCandidate = (val) => {
            if (val && !extenCandidates.includes(val)) extenCandidates.push(val);
        };

        const uaeMatch = /^(?:\+971|00971|971)(\d{8,9})$/.exec(cleanedCallee);
        if (uaeMatch) {
            addCandidate(`0${uaeMatch[1]}`);
            addCandidate(`971${uaeMatch[1]}`);
            addCandidate(`00971${uaeMatch[1]}`);
            addCandidate(`+971${uaeMatch[1]}`);
        } else {
            addCandidate(cleanedCallee.replace(/^\+/, ''));
            addCandidate(cleanedCallee);
            if (cleanedCallee.startsWith('+')) {
                addCandidate(`00${cleanedCallee.slice(1)}`);
            }
        }

        let epContext = null;
        let epState = 'Unknown';
        try {
            const detail = await sendRawAmi('PJSIPShowEndpoint', { Endpoint: caller }, 'EndpointDetailComplete', 5000);
            const ep = detail.events.find((e) => e.Event === 'EndpointDetail');
            if (ep?.Context) epContext = ep.Context;
            if (ep?.DeviceState) epState = ep.DeviceState;
        } catch {
            // Fallback below
        }

        console.log(`[agent][ami] Click-to-Call request: ext ${caller} (state: ${epState}, context: ${epContext || 'auto'}) -> ${rawCallee} (candidates: ${extenCandidates.join(', ')})`);
        if (/unavailable/i.test(epState)) {
            console.warn(`[agent][ami] WARNING: Extension ${caller} is currently OFFLINE (Unavailable). Log into extension ${caller} on an IP phone or Yeastar Linkus app so it can ring!`);
        }

        const contextCandidates = [];
        for (const ctx of [epContext, `DLPN_DialPlan${caller}`, 'from-internal', 'default']) {
            if (ctx && !contextCandidates.includes(ctx)) contextCandidates.push(ctx);
        }

        const callId = `c2c_${crypto.randomUUID().slice(0, 12)}`;
        const call = getOrCreateCall(callId);
        call.isClickToCall = true;
        call.direction = 'outbound';
        call.callerNumber = caller;
        call.calleeNumber = extenCandidates[0];

        let origSuccess = false;
        let lastErrMsg = 'AMI Originate failed';

        for (const ctx of contextCandidates) {
            for (const exten of extenCandidates) {
                const orig = await sendRawAmi('Originate', {
                    Channel: `PJSIP/${caller}`,
                    Context: ctx,
                    Exten: exten,
                    Priority: 1,
                    CallerID: `"${exten}" <${caller}>`,
                    ChannelId: callId,
                    Async: 'true',
                });

                if (orig.response?.Response === 'Success') {
                    console.log(`[agent][ami] Originate queued via dialplan (Context: ${ctx}, Exten: ${exten})`);
                    origSuccess = true;
                    break;
                }
                lastErrMsg = orig.response?.Message || lastErrMsg;
                if (!/extension does not exist/i.test(lastErrMsg)) {
                    break;
                }
            }
            if (origSuccess) break;
        }

        // Fallback: Direct Application Dial if no dialplan context matched
        if (!origSuccess) {
            const dialTarget = extenCandidates[0];
            let dialData = `PJSIP/${dialTarget},45`;

            if (dialTarget.length > 5) {
                try {
                    const { events } = await sendRawAmi('PJSIPShowEndpoints', {}, 'EndpointListComplete', 6000);
                    const trunkEp = events.find((e) => e.ObjectName && !isNumericExt(e.ObjectName) && e.ObjectName !== 'anonymous');
                    if (trunkEp?.ObjectName) {
                        dialData = `PJSIP/${dialTarget}@${trunkEp.ObjectName},60`;
                    }
                } catch {}
            }

            console.log(`[agent][ami] Dialplan context did not match (${lastErrMsg}); falling back to direct Application: Dial (${dialData})`);
            const fallbackOrig = await sendRawAmi('Originate', {
                Channel: `PJSIP/${caller}`,
                Application: 'Dial',
                Data: dialData,
                CallerID: `"${dialTarget}" <${caller}>`,
                ChannelId: callId,
                Async: 'true',
            });

            if (fallbackOrig.response?.Response === 'Error') {
                return {
                    status: 502,
                    data: { errcode: 502, errmsg: fallbackOrig.response.Message || lastErrMsg },
                };
            }
        }

        return {
            status: 200,
            data: { errcode: 0, errmsg: 'SUCCESS', call_id: callId },
        };
    }

    if (path === '/contact/create') {
        return { status: 200, data: { errcode: 0, errmsg: 'SUCCESS' } };
    }

    return {
        status: 501,
        data: { errcode: 501, errmsg: `Unsupported endpoint in AMI mode: ${path}` },
    };
}

// ============================================================================
// MODE 2: Yeastar OpenAPI (HTTPS 8088 - Enterprise / Ultimate Plan)
// ============================================================================
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

async function callOpenApiPbx(method, path, payload = {}) {
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

function callLocalPbx(method, path, payload = {}) {
    return USE_AMI ? callAmiPbx(method, path, payload) : callOpenApiPbx(method, path, payload);
}

// ============================================================================
// Heartbeat & Command Polling Loop
// ============================================================================
async function sendHeartbeat() {
    try {
        const info = await callLocalPbx('GET', '/system/info');
        const firmware = info.data?.firmware_version || info.data?.data?.firmware_version || null;
        await saasPost('/api/agent/heartbeat', { firmware_version: firmware, pbx_reachable: true });
        console.log(`[agent] Heartbeat sent successfully (mode: ${USE_AMI ? 'AMI' : 'OpenAPI'}, status: online)`);
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

console.log(`[agent] Starting Yeastar On-Premise Connector Agent (${USE_AMI ? 'AMI Mode' : 'OpenAPI Mode'}) -> ${SAAS_URL}`);
if (USE_AMI) {
    connectAmi();
} else {
    sendHeartbeat();
}
setInterval(sendHeartbeat, 30_000);
pollLoop();
