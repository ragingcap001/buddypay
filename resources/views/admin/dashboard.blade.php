<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin · Aṣẹ</title>
    <style>
        :root { --bg: #0b1020; --card: #131a2e; --line: #26304d; --text: #e6ebf5; --muted: #8b96b0; --accent: #3f8cff; --ok: #2dd4a7; --warn: #f5b942; --err: #ff6b6b; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }
        header { display: flex; align-items: center; justify-content: space-between; padding: 16px 28px; border-bottom: 1px solid var(--line); background: var(--card); position: sticky; top: 0; z-index: 5; }
        header h1 { font-size: 17px; }
        header .who { color: var(--muted); font-size: 13px; margin-left: 12px; }
        header form { display: flex; gap: 10px; align-items: center; }
        main { padding: 24px 28px 60px; max-width: 1180px; margin: 0 auto; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 18px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
        .card h2 { font-size: 14px; margin-bottom: 4px; }
        .card .hint { color: var(--muted); font-size: 12px; margin-bottom: 14px; }
        label { display: block; font-size: 11px; color: var(--muted); margin: 12px 0 5px; letter-spacing: .05em; text-transform: uppercase; }
        input, textarea { width: 100%; padding: 9px 11px; border-radius: 8px; border: 1px solid var(--line); background: #0d1426; color: var(--text); font-size: 13px; font-family: inherit; }
        input:focus, textarea:focus { outline: none; border-color: var(--accent); }
        textarea { min-height: 90px; font-family: ui-monospace, monospace; font-size: 12px; }
        .row { display: flex; gap: 10px; align-items: center; }
        .badge { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--line); color: var(--muted); vertical-align: middle; }
        .badge.ok { color: var(--ok); border-color: rgba(45,212,167,.4); }
        .badge.warn { color: var(--warn); border-color: rgba(245,185,66,.4); }
        .badge.err { color: var(--err); border-color: rgba(255,107,107,.4); }
        .badge.override { color: var(--accent); border-color: rgba(63,140,255,.4); margin-left: 6px; }
        .env { color: var(--muted); font-size: 11px; margin-left: 6px; text-transform: none; letter-spacing: 0; }
        button { padding: 8px 14px; border: 1px solid var(--line); border-radius: 8px; background: #0d1426; color: var(--text); font-size: 13px; cursor: pointer; }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
        button:hover { filter: brightness(1.15); }
        button:disabled { opacity: .5; cursor: default; }
        .actions { display: flex; gap: 10px; margin-top: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; padding: 6px 8px; border-bottom: 1px solid var(--line); }
        td { padding: 7px 8px; border-bottom: 1px solid rgba(38,48,77,.5); }
        .toast { position: fixed; bottom: 22px; right: 22px; background: var(--card); border: 1px solid var(--line); border-left: 3px solid var(--ok); border-radius: 9px; padding: 12px 16px; font-size: 13px; max-width: 380px; opacity: 0; transform: translateY(8px); transition: all .25s; pointer-events: none; z-index: 50; }
        .toast.show { opacity: 1; transform: none; }
        .toast.err { border-left-color: var(--err); }
        .masked { color: var(--muted); }
        .flash { color: var(--muted); font-size: 12px; margin-top: 8px; min-height: 16px; }
        .full { grid-column: 1 / -1; }
    </style>
</head>
<body>
<header>
    <div>
        <h1>Aṣẹ Admin</h1>
        <span class="who">{{ auth()->user()->name }} · {{ auth()->user()->phone }}</span>
    </div>
    <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
</header>

<main>
    <!-- Provider health -->
    <div class="card">
        <h2>Providers</h2>
        <p class="hint">Registered rails, circuit-breaker state and the last 24h of provider attempts. A DB override on a credential is independent of provider status — disable a provider in the DB (<code>providers.status</code>) to stop traffic.</p>
        <table id="providers-table">
            <thead>
                <tr><th>Provider</th><th>Type</th><th>Status</th><th>Circuit</th><th>24h ✓</th><th>24h ✗</th><th>24h ?</th></tr>
            </thead>
            <tbody><tr><td colspan="7" class="masked">Loading…</td></tr></tbody>
        </table>
    </div>

    <div class="grid">
        <!-- Push -->
        <div class="card">
            <h2>Push (Firebase FCM — Android + iOS)</h2>
            <p class="hint">Firebase relays to both Google (Android) and Apple (APNs). Set the Firebase service account in the Firebase group below.</p>
            <div class="row" style="margin-bottom:12px">
                <span id="firebase-badge" class="badge">checking…</span>
                <span id="device-count" class="badge">0 devices</span>
            </div>
            <div class="row">
                <input id="push-token" type="text" placeholder="Device token (blank = random registered device)" style="flex:1">
                <button class="primary" id="push-test-btn">Send test push</button>
            </div>
            <div class="flash" id="push-flash"></div>
            <div style="margin-top:14px">
                <table>
                    <thead><tr><th>User</th><th>Platform</th><th>Name</th><th>Last used</th></tr></thead>
                    <tbody id="devices-tbody"><tr><td colspan="4" class="masked">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- Config groups render here -->
        <div class="card full" style="grid-column:1/-1">
            <h2>Configuration</h2>
            <p class="hint">
                Values resolve <b>DB override → environment variable → default</b>. Saved values are stored encrypted.
                Secrets are masked; re-enter a full value to change one. “Reset to env” clears the DB override so the environment variable applies again.
            </p>
            <div id="config-groups"></div>
        </div>
    </div>
</main>

<div class="toast" id="toast"></div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function api(url, options = {}) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        credentials: 'same-origin',
        ...options,
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        const msg = body?.error?.message || ('HTTP ' + res.status);
        throw new Error(msg);
    }
    return body?.data;
}

function toast(msg, isErr = false) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className = 'toast', 4200);
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function loadProviders() {
    try {
        const data = await api('/api/v1/admin/providers');
        const rows = data.providers.map(p => {
            const statusCls = p.status === 'ACTIVE' ? 'ok' : 'err';
            const circuitCls = p.circuit === 'CLOSED' ? 'ok' : (p.circuit === 'OPEN' ? 'err' : 'warn');
            return `<tr>
                <td>${esc(p.display_name || p.name)} <span class="masked">(${esc(p.name)})</span></td>
                <td>${esc(p.type)}</td>
                <td><span class="badge ${statusCls}">${esc(p.status)}</span></td>
                <td><span class="badge ${circuitCls}">${esc(p.circuit)}</span></td>
                <td>${p.attempts_24h.success}</td>
                <td>${p.attempts_24h.failure}</td>
                <td>${p.attempts_24h.ambiguous}</td>
            </tr>`;
        }).join('');
        document.querySelector('#providers-table tbody').innerHTML = rows || '<tr><td colspan="7" class="masked">No providers registered</td></tr>';
    } catch (e) { toast(e.message, true); }
}

async function loadPush() {
    try {
        const data = await api('/api/v1/admin/push/devices');
        const badge = document.getElementById('firebase-badge');
        badge.textContent = data.firebase_configured ? 'Firebase configured' : 'Firebase NOT configured';
        badge.className = 'badge ' + (data.firebase_configured ? 'ok' : 'warn');
        document.getElementById('device-count').textContent = data.total + ' device' + (data.total === 1 ? '' : 's');
        document.querySelector('#devices-tbody').innerHTML = data.devices.length
            ? data.devices.map(d => `<tr><td>${esc(d.user)} <span class="masked">${esc(d.phone || '')}</span></td><td>${esc(d.platform)}</td><td>${esc(d.name || '—')}</td><td>${esc(d.last_used_at || 'never')}</td></tr>`).join('')
            : '<tr><td colspan="4" class="masked">No devices registered yet</td></tr>';
    } catch (e) { toast(e.message, true); }
}

let configData = null;

async function loadConfig() {
    try {
        configData = await api('/api/v1/admin/config');
        const wrap = document.getElementById('config-groups');
        wrap.innerHTML = Object.entries(configData).map(([group, keys]) => `
            <div class="card" id="group-${group}" style="margin-top:14px">
                <h2>${esc(group)} <span class="masked" style="font-weight:400">— ${esc(keys.label || '')}</span></h2>
                ${Object.entries(keys).filter(([, k]) => k.label).map(([key, k]) => `
                    <label for="cfg-${group}-${key}">
                        ${esc(key)}
                        ${k.overridden ? '<span class="badge override">DB override</span>' : ''}
                        ${k.env ? `<span class="env" title="Environment fallback">${esc(k.env)}</span>` : ''}
                    </label>
                    ${k.multiline
                        ? `<textarea id="cfg-${group}-${key}" data-group="${group}" data-key="${key}" data-secret="${k.secret ? 1 : 0}" placeholder="${k.secret ? (k.masked || 'not set') : (k.value || '')}">${k.secret ? '' : esc(k.value || '')}</textarea>`
                        : `<input id="cfg-${group}-${key}" type="${k.secret ? 'password' : 'text'}" data-group="${group}" data-key="${key}" data-secret="${k.secret ? 1 : 0}" placeholder="${k.secret ? (k.masked || 'not set') : esc(k.value || '')}" value="${k.secret ? '' : esc(k.value || '')}">`}
                `).join('')}
                <div class="actions">
                    <button class="primary" data-save="${group}">Save changes</button>
                    <button data-reset="${group}">Reset to env</button>
                    <span class="flash" id="flash-${group}"></span>
                </div>
            </div>
        `).join('');
    } catch (e) { toast(e.message, true); }
}

function bindGroupButtons() {
    document.querySelectorAll('[data-save]').forEach(btn => btn.addEventListener('click', () => saveGroup(btn.dataset.save)));
    document.querySelectorAll('[data-reset]').forEach(btn => btn.addEventListener('click', () => resetGroup(btn.dataset.reset)));
}

async function saveGroup(group) {
    const values = {};
    document.querySelectorAll(`input[data-group="${group}"], textarea[data-group="${group}"]`).forEach(el => {
        const v = el.value.trim();
        // Only send what the admin actually typed — blank means "keep current".
        if (v !== '') values[el.dataset.key] = v;
    });
    if (Object.keys(values).length === 0) {
        toast('Nothing to save — enter at least one value.');
        return;
    }
    try {
        const data = await api('/api/v1/admin/config', { method: 'PUT', body: JSON.stringify({ group, values }) });
        document.getElementById('flash-' + group).textContent = 'Saved: ' + data.applied.join(', ');
        toast('Configuration updated.');
        loadConfig(); loadProviders();
    } catch (e) { toast(e.message, true); }
}

async function resetGroup(group) {
    if (!confirm('Clear all DB overrides for "' + group + '"? The environment variables will apply again.')) return;
    const keys = Object.keys(configData[group] || {}).filter(k => configData[group][k].label);
    const values = {};
    keys.forEach(k => values[k] = null);
    try {
        const data = await api('/api/v1/admin/config', { method: 'PUT', body: JSON.stringify({ group, values }) });
        toast('Group "' + group + '" reset to environment defaults.');
        loadConfig();
    } catch (e) { toast(e.message, true); }
}

async function testPush() {
    const btn = document.getElementById('push-test-btn');
    const flash = document.getElementById('push-flash');
    const token = document.getElementById('push-token').value.trim();
    btn.disabled = true; flash.textContent = 'Sending…';
    try {
        const data = await api('/api/v1/admin/push/test', { method: 'POST', body: JSON.stringify({ device_token: token }) });
        flash.textContent = 'Sent — FCM message ' + (data.message_id || '');
        toast('Test push sent.');
    } catch (e) {
        flash.textContent = e.message;
        toast(e.message, true);
    } finally { btn.disabled = false; }
}

document.getElementById('push-test-btn').addEventListener('click', testPush);
loadProviders(); loadPush(); loadConfig(); bindGroupButtons();
</script>
</body>
</html>
