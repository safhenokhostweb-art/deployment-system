@extends('layouts.app')

@section('title', __('agent_dashboard.title'))

@section('content')
<link rel="stylesheet" href="/vendor/drawflow/drawflow.min.css">
<style>
    /* Scoped dark theme: the rest of the panel stays light. */
    .ad-shell {
        --ad-bg: #0b0f14; --ad-panel: #111821; --ad-panel-2: #16202b; --ad-border: #233041;
        --ad-text: #e6edf3; --ad-muted: #8b98a8; --ad-accent: #58a6ff;
        --ad-ready: #2fbf71; --ad-attention: #f0a020; --ad-blocked: #e5484d; --ad-running: #3b9eff;
        --ad-idle: #8b949e; --ad-disabled: #4b5563; --ad-system: #a371f7;
        position: relative; background: var(--ad-bg); color: var(--ad-text);
        border: 1px solid var(--ad-border); border-radius: 12px; overflow: hidden;
        box-shadow: 0 18px 48px rgba(3, 8, 15, 0.35);
    }
    .ad-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; padding: 0.85rem 1rem; background: linear-gradient(180deg, #121b26, #0f161f); border-bottom: 1px solid var(--ad-border); }
    .ad-toolbar h1 { margin: 0; font-size: 1.1rem; color: var(--ad-text); }
    .ad-toolbar p { margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--ad-muted); max-width: 620px; }
    .ad-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .ad-btn { appearance: none; border: 1px solid var(--ad-border); background: var(--ad-panel-2); color: var(--ad-text); border-radius: 8px; padding: 0.4rem 0.8rem; font-size: 0.8rem; cursor: pointer; transition: background .15s, border-color .15s, opacity .15s; }
    .ad-btn:hover:not(:disabled) { border-color: var(--ad-accent); }
    .ad-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .ad-btn--primary { background: #1f6feb; border-color: #1f6feb; }
    .ad-btn--run { background: rgba(47, 191, 113, 0.14); border-color: rgba(47, 191, 113, 0.5); color: #7ee2a8; }
    .ad-btn--stop { background: rgba(229, 72, 77, 0.14); border-color: rgba(229, 72, 77, 0.5); color: #ff9c9f; }
    .ad-dirty { font-size: 0.75rem; color: var(--ad-attention); display: none; }
    .ad-dirty.is-visible { display: inline; }
    .ad-notice { padding: 0.5rem 1rem; font-size: 0.78rem; color: var(--ad-muted); border-bottom: 1px solid var(--ad-border); background: #0d141c; }
    .ad-legend { display: flex; gap: 0.9rem; flex-wrap: wrap; padding: 0.5rem 1rem; font-size: 0.75rem; color: var(--ad-muted); border-bottom: 1px solid var(--ad-border); }
    .ad-legend span { display: inline-flex; align-items: center; gap: 0.35rem; }

    .ad-canvas { height: max(560px, calc(100vh - 290px)); background-color: var(--ad-bg); background-image: radial-gradient(#1c2836 1px, transparent 1px); background-size: 22px 22px; }
    .ad-empty { position: absolute; inset: 40% 0 auto; text-align: center; color: var(--ad-muted); font-size: 0.9rem; padding: 0 1rem; }

    /* Drawflow overrides */
    .ad-canvas .drawflow .drawflow-node { background: transparent; border: none; padding: 0; width: 290px; color: var(--ad-text); }
    .ad-canvas .drawflow .drawflow-node.selected { background: transparent; }
    .ad-canvas .drawflow .drawflow-node.selected .ad-node { border-color: var(--ad-accent); box-shadow: 0 0 0 1px var(--ad-accent), 0 12px 30px rgba(0,0,0,.45); }
    .ad-canvas .drawflow .drawflow-node .input, .ad-canvas .drawflow .drawflow-node .output { width: 16px; height: 16px; background: var(--ad-bg); border: 2px solid var(--ad-accent); }
    .ad-canvas .drawflow .drawflow-node .input { left: -9px; }
    .ad-canvas .drawflow .drawflow-node .output { right: 9px; }
    .ad-canvas .drawflow .drawflow-node .output:hover, .ad-canvas .drawflow .drawflow-node .input:hover { background: var(--ad-accent); }
    .ad-canvas .drawflow .connection .main-path { stroke: #3f7fd8; stroke-width: 3px; }
    .ad-canvas .drawflow .connection .main-path:hover { stroke: var(--ad-accent); }
    .ad-canvas .drawflow .connection .main-path.selected { stroke: var(--ad-attention); }
    .ad-canvas .parent-node .drawflow-delete { display: none; }
    .ad-canvas .drawflow > .drawflow-delete { background: var(--ad-blocked); border-color: #fff; }

    /* Agent custom node */
    .ad-node { background: linear-gradient(180deg, var(--ad-panel-2), var(--ad-panel)); border: 1px solid var(--ad-border); border-radius: 12px; padding: 0.7rem 0.8rem 0.75rem; box-shadow: 0 10px 26px rgba(0,0,0,.35); font-size: 0.78rem; direction: ltr; text-align: left; transition: border-color .2s, opacity .2s; }
    .ad-node.is-disabled { opacity: 0.55; }
    .ad-node--system { border-style: dashed; border-color: rgba(163, 113, 247, 0.6); }
    .ad-node__head { display: flex; align-items: center; gap: 0.5rem; }
    .ad-node__title { font-weight: 700; font-size: 0.88rem; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ad-node__sub { margin-top: 0.15rem; color: var(--ad-muted); font-family: ui-monospace, Consolas, monospace; font-size: 0.72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ad-node__row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 0.55rem; }
    .ad-node__ports { margin-top: 0.5rem; display: grid; gap: 0.2rem; font-family: ui-monospace, Consolas, monospace; font-size: 0.7rem; color: var(--ad-muted); }
    .ad-node__ports b { color: #c9d4e0; font-weight: 600; margin-right: 0.3rem; }
    .ad-node__summary { margin-top: 0.45rem; color: #b8c4d2; font-size: 0.72rem; line-height: 1.35; max-height: 2.8em; overflow: hidden; }
    .ad-node__actions { display: flex; gap: 0.4rem; margin-top: 0.65rem; }
    .ad-node__actions .ad-btn { padding: 0.28rem 0.65rem; font-size: 0.74rem; }

    .ad-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; background: var(--ad-idle); position: relative; }
    .ad-status-READY .ad-dot { background: var(--ad-ready); box-shadow: 0 0 8px rgba(47,191,113,.7); }
    .ad-status-ATTENTION .ad-dot { background: var(--ad-attention); box-shadow: 0 0 8px rgba(240,160,32,.7); }
    .ad-status-BLOCKED .ad-dot { background: var(--ad-blocked); box-shadow: 0 0 8px rgba(229,72,77,.7); }
    .ad-status-DISABLED .ad-dot { background: var(--ad-disabled); }
    .ad-status-SYSTEM .ad-dot { background: var(--ad-system); }
    .ad-status-RUNNING .ad-dot { background: var(--ad-running); }
    .ad-status-RUNNING .ad-dot::after { content: ''; position: absolute; inset: -5px; border-radius: 50%; border: 2px solid var(--ad-running); animation: ad-pulse 1.4s ease-out infinite; }
    .ad-status-RUNNING.ad-node { border-color: rgba(59,158,255,.6); }
    @keyframes ad-pulse { 0% { transform: scale(.6); opacity: 1; } 100% { transform: scale(1.8); opacity: 0; } }

    .ad-chip { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.68rem; font-weight: 700; letter-spacing: .02em; background: #1f2937; color: #c9d4e0; }
    .ad-chip-READY { background: rgba(47,191,113,.16); color: #7ee2a8; }
    .ad-chip-ATTENTION { background: rgba(240,160,32,.16); color: #ffc66b; }
    .ad-chip-BLOCKED, .ad-chip-blocker { background: rgba(229,72,77,.16); color: #ff9c9f; }
    .ad-chip-RUNNING { background: rgba(59,158,255,.16); color: #8cc8ff; }
    .ad-chip-SYSTEM { background: rgba(163,113,247,.16); color: #cdb0ff; }
    .ad-chip-warning { background: rgba(240,160,32,.16); color: #ffc66b; }
    .ad-chip-info { background: rgba(88,166,255,.14); color: #8cc8ff; }

    .ad-switch { position: relative; display: inline-flex; align-items: center; gap: 0.35rem; cursor: pointer; margin: 0; font-weight: 500; font-size: 0.7rem; color: var(--ad-muted); }
    .ad-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ad-switch__track { width: 30px; height: 17px; border-radius: 999px; background: #2b3645; position: relative; transition: background .2s; }
    .ad-switch__track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 13px; height: 13px; border-radius: 50%; background: #cbd5e1; transition: transform .2s; }
    .ad-switch input:checked + .ad-switch__track { background: #238636; }
    .ad-switch input:checked + .ad-switch__track::after { transform: translateX(13px); background: #fff; }
    .ad-switch input:disabled + .ad-switch__track { opacity: .5; cursor: not-allowed; }
    .ad-switch input:focus-visible + .ad-switch__track { outline: 2px solid var(--ad-accent); outline-offset: 2px; }

    /* Drawer */
    .ad-drawer { position: absolute; top: 0; bottom: 0; left: 0; width: min(460px, 100%); background: #0e151d; border-right: 1px solid var(--ad-border); transform: translateX(-102%); transition: transform .25s ease; z-index: 20; display: flex; flex-direction: column; box-shadow: 12px 0 32px rgba(0,0,0,.45); }
    .ad-drawer.is-open { transform: translateX(0); }
    .ad-drawer__head { display: flex; align-items: center; gap: 0.6rem; padding: 0.85rem 1rem; border-bottom: 1px solid var(--ad-border); }
    .ad-drawer__head h2 { margin: 0; font-size: 0.95rem; flex: 1; direction: ltr; text-align: left; }
    .ad-drawer__body { overflow-y: auto; padding: 0.4rem 1rem 1.2rem; flex: 1; }
    .ad-section { border-bottom: 1px solid var(--ad-border); padding: 0.8rem 0; }
    .ad-section:last-child { border-bottom: none; }
    .ad-section h3 { margin: 0 0 0.5rem; font-size: 0.8rem; color: #c9d4e0; }
    .ad-kv { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 0.8rem; font-size: 0.76rem; }
    .ad-kv dt { color: var(--ad-muted); }
    .ad-kv dd { margin: 0; direction: ltr; text-align: left; word-break: break-all; font-family: ui-monospace, Consolas, monospace; }
    .ad-drawer pre { background: #070b10; color: #d1dae4; border: 1px solid var(--ad-border); max-height: 260px; overflow: auto; font-size: 0.72rem; margin: 0.5rem 0 0; white-space: pre-wrap; word-break: break-word; }
    .ad-finding { border: 1px solid var(--ad-border); border-radius: 8px; padding: 0.5rem 0.6rem; margin-bottom: 0.45rem; direction: ltr; text-align: left; font-size: 0.74rem; background: #0b1118; }
    .ad-finding__head { display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; }
    .ad-finding code { color: #e6edf3; font-weight: 700; }
    .ad-finding__subject { color: var(--ad-muted); font-family: ui-monospace, Consolas, monospace; }
    .ad-finding p { margin: 0.3rem 0 0; color: #b8c4d2; line-height: 1.45; }
    .ad-journal { list-style: none; margin: 0; padding: 0; direction: ltr; text-align: left; font-family: ui-monospace, Consolas, monospace; font-size: 0.7rem; }
    .ad-journal li { display: grid; grid-template-columns: 9.5rem 1fr; gap: 0.5rem; padding: 0.25rem 0; border-bottom: 1px dashed #1b2533; }
    .ad-journal time { color: var(--ad-muted); }
    .ad-muted { color: var(--ad-muted); font-size: 0.76rem; }
    .ad-error { color: #ff9c9f; font-size: 0.76rem; }

    .ad-toast { position: absolute; bottom: 1rem; right: 1rem; z-index: 30; background: #16202b; border: 1px solid var(--ad-border); color: var(--ad-text); padding: 0.55rem 0.9rem; border-radius: 8px; font-size: 0.8rem; opacity: 0; transform: translateY(8px); transition: opacity .2s, transform .2s; pointer-events: none; max-width: 70%; }
    .ad-toast.is-visible { opacity: 1; transform: translateY(0); }
    .ad-toast.is-error { border-color: rgba(229,72,77,.7); color: #ffb3b5; }

    @media (prefers-reduced-motion: reduce) {
        .ad-status-RUNNING .ad-dot::after { animation: none; }
        .ad-drawer { transition: none; }
    }
    @media (max-width: 820px) {
        .ad-canvas { height: 520px; }
    }
</style>

<section class="ad-shell" id="ad-shell">
    <div class="ad-toolbar">
        <div>
            <h1>{{ __('agent_dashboard.title') }}</h1>
            <p>{{ __('agent_dashboard.subtitle') }}</p>
        </div>
        <div class="ad-actions">
            <span class="ad-dirty" id="ad-dirty">{{ __('agent_dashboard.unsaved') }}</span>
            <button type="button" class="ad-btn" id="ad-fit">{{ __('agent_dashboard.fit') }}</button>
            <button type="button" class="ad-btn" id="ad-refresh">{{ __('agent_dashboard.refresh') }}</button>
            @if ($canManage)
                <button type="button" class="ad-btn ad-btn--primary" id="ad-save" disabled>{{ __('agent_dashboard.save') }}</button>
            @endif
        </div>
    </div>
    <div class="ad-notice">{{ $canManage ? __('agent_dashboard.hint') : __('agent_dashboard.read_only') }}</div>
    <div class="ad-legend" aria-hidden="true">
        @foreach (['READY', 'ATTENTION', 'BLOCKED', 'RUNNING', 'IDLE', 'DISABLED', 'SYSTEM'] as $legendStatus)
            <span class="ad-status-{{ $legendStatus }}"><span class="ad-dot"></span>{{ __('agent_dashboard.status.'.$legendStatus) }}</span>
        @endforeach
    </div>

    <div class="ad-canvas" id="ad-canvas" dir="ltr"></div>
    <div class="ad-empty" id="ad-empty" hidden></div>

    <aside class="ad-drawer" id="ad-drawer" aria-hidden="true" aria-labelledby="ad-drawer-title">
        <div class="ad-drawer__head">
            <span class="ad-dot" id="ad-drawer-dot"></span>
            <h2 id="ad-drawer-title"></h2>
            <button type="button" class="ad-btn" id="ad-drawer-close">{{ __('agent_dashboard.drawer.close') }}</button>
        </div>
        <div class="ad-drawer__body" id="ad-drawer-body"></div>
    </aside>

    <div class="ad-toast" id="ad-toast" role="status" aria-live="polite"></div>
</section>

@php
    // The placeholder must itself satisfy the routes' [a-z][a-z0-9-]* id
    // constraint; the script swaps it for the real agent id.
    $agentDashboardConfig = [
        'canManage' => $canManage,
        'urls' => [
            'graph' => route('agent-dashboard.graph'),
            'save' => route('agent-dashboard.save'),
            'toggle' => route('agent-dashboard.toggle', ['id' => 'agent-id-placeholder']),
            'run' => route('agent-dashboard.run', ['id' => 'agent-id-placeholder']),
            'stop' => route('agent-dashboard.stop', ['id' => 'agent-id-placeholder']),
            'logs' => route('agent-dashboard.logs', ['id' => 'agent-id-placeholder']),
        ],
        't' => trans('agent_dashboard'),
    ];
@endphp
<script type="application/json" id="ad-config">@json($agentDashboardConfig)</script>
<script src="/vendor/drawflow/drawflow.min.js"></script>
<script>
(() => {
    const cfg = JSON.parse(document.getElementById('ad-config').textContent);
    const t = cfg.t;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (id) => document.getElementById(id);
    const canvas = $('ad-canvas');
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const url = (name, id) => cfg.urls[name].replace('agent-id-placeholder', encodeURIComponent(id));

    const editor = new Drawflow(canvas);
    editor.reroute = false;
    editor.draggable_inputs = false;
    editor.zoom_min = 0.4;
    editor.zoom_max = 1.6;
    editor.editor_mode = cfg.canManage ? 'edit' : 'fixed';
    editor.start();
    // Nodes mirror agents.json entries; they are never deleted from the canvas.
    editor.removeNodeId = () => {};

    const state = { graph: null, nodes: {}, dfByNode: {}, nodeByDf: {}, dirty: false, building: false, drawerId: null, busy: new Set() };
    let pollTimer = null;
    let drawerTimer = null;

    async function api(method, target, body) {
        const response = await fetch(target, {
            method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.message || response.statusText);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function toast(message, isError = false) {
        const el = $('ad-toast');
        el.textContent = message;
        el.classList.toggle('is-error', isError);
        el.classList.add('is-visible');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => el.classList.remove('is-visible'), 3200);
    }

    function setDirty(dirty) {
        state.dirty = dirty;
        $('ad-dirty').classList.toggle('is-visible', dirty);
        if ($('ad-save')) $('ad-save').disabled = !dirty;
    }

    function nodeHtml(node) {
        const statusLabel = t.status[node.status] || node.status;
        const ports = `
            <div class="ad-node__ports">
                <div><b>${esc(t.inputs)}</b>${esc(node.inputs.join(', ') || t.none)}</div>
                ${node.type === 'agent' ? `<div><b>${esc(t.outputs)}</b>${esc(node.outputs.join(', ') || t.none)}</div>` : ''}
            </div>`;
        if (node.type === 'system') {
            return `<div class="ad-node ad-node--system ad-status-SYSTEM" data-node="${esc(node.id)}">
                <div class="ad-node__head"><span class="ad-dot"></span><span class="ad-node__title">${esc(node.label)}</span><span class="ad-chip ad-chip-SYSTEM">${esc(statusLabel)}</span></div>
                <div class="ad-node__sub" title="${esc(node.description)}">${esc(node.description)}</div>
                ${ports}
                <div class="ad-node__actions"><button type="button" class="ad-btn" data-action="details" data-no-drag>${esc(t.details)}</button></div>
            </div>`;
        }
        const running = node.status === 'RUNNING';
        const busy = state.busy.has(node.id);
        const manage = cfg.canManage;
        const outcome = node.last_outcome;
        return `<div class="ad-node ad-status-${esc(node.status)} ${node.enabled ? '' : 'is-disabled'}" data-node="${esc(node.id)}">
            <div class="ad-node__head">
                <span class="ad-dot" title="${esc(statusLabel)}"></span>
                <span class="ad-node__title" title="${esc(node.label)}">${esc(node.label)}</span>
                <label class="ad-switch" data-no-drag title="${esc(node.enabled ? t.enabled : t.disabled)}">
                    <input type="checkbox" data-action="toggle" ${node.enabled ? 'checked' : ''} ${manage && !busy ? '' : 'disabled'} aria-label="${esc(node.label)} — ${esc(t.enabled)}">
                    <span class="ad-switch__track"></span>
                </label>
            </div>
            <div class="ad-node__sub" title="${esc(node.description)}">${esc(t.task)}: ${esc(node.tasks.join(', ') || t.none)}</div>
            <div class="ad-node__row"><span class="ad-chip ad-chip-${esc(node.status)}">${esc(statusLabel)}</span>
                <span class="ad-muted">${esc(outcome?.occurred_at ? outcome.occurred_at.replace('T', ' ').slice(0, 19) : '')}</span></div>
            ${outcome?.summary ? `<div class="ad-node__summary" title="${esc(outcome.summary)}">${esc(outcome.summary)}</div>` : ''}
            ${ports}
            <div class="ad-node__actions">
                <button type="button" class="ad-btn ad-btn--run" data-action="run" data-no-drag ${manage && !running && node.enabled && !busy ? '' : 'disabled'}>▶ ${esc(t.run)}</button>
                <button type="button" class="ad-btn ad-btn--stop" data-action="stop" data-no-drag ${manage && running && !busy ? '' : 'disabled'}>■ ${esc(t.stop)}</button>
                <button type="button" class="ad-btn" data-action="details" data-no-drag>${esc(t.details)}</button>
            </div>
        </div>`;
    }

    function structureKey(graph) {
        return graph.nodes.map((n) => n.id).join('|') + '#' + graph.edges.map((e) => e.id).join('|');
    }

    function build(graph) {
        state.building = true;
        editor.clear();
        state.dfByNode = {};
        state.nodeByDf = {};
        state.nodes = {};
        for (const node of graph.nodes) {
            const dfId = editor.addNode(node.id, 1, node.type === 'system' ? 0 : 1, node.position.x, node.position.y, 'ad-df ad-df--' + node.type, { id: node.id }, nodeHtml(node));
            state.dfByNode[node.id] = dfId;
            state.nodeByDf[dfId] = node.id;
            state.nodes[node.id] = node;
        }
        for (const edge of graph.edges) {
            editor.addConnection(state.dfByNode[edge.source], state.dfByNode[edge.target], 'output_1', 'input_1');
        }
        state.graph = graph;
        state.building = false;
        setDirty(false);
    }

    function repaint(node) {
        state.nodes[node.id] = node;
        const content = canvas.querySelector(`#node-${state.dfByNode[node.id]} .drawflow_content_node`);
        if (content) content.innerHTML = nodeHtml(node);
    }

    function apply(graph) {
        $('ad-empty').hidden = true;
        if (!state.graph || (!state.dirty && structureKey(graph) !== structureKey(state.graph))) {
            build(graph);
        } else {
            graph.nodes.forEach((node) => { if (state.dfByNode[node.id] !== undefined) repaint(node); });
            state.graph = graph;
        }
        schedulePoll();
    }

    async function load() {
        try {
            apply(await api('GET', cfg.urls.graph));
        } catch (error) {
            if (!state.graph) {
                $('ad-empty').hidden = false;
                $('ad-empty').textContent = `${t.runtime_unavailable}: ${error.message}`;
            } else {
                toast(error.message, true);
            }
            schedulePoll();
        }
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        const anyRunning = state.graph?.nodes.some((n) => n.status === 'RUNNING');
        pollTimer = setTimeout(load, anyRunning ? 2000 : 6000);
    }

    function canvasState() {
        const nodes = [];
        const edges = [];
        for (const [nodeId, dfId] of Object.entries(state.dfByNode)) {
            const df = editor.getNodeFromId(dfId);
            const node = state.nodes[nodeId];
            nodes.push(node.type === 'agent'
                ? { id: nodeId, enabled: node.enabled, position: { x: df.pos_x, y: df.pos_y } }
                : { id: nodeId, position: { x: df.pos_x, y: df.pos_y } });
            for (const connection of df.outputs?.output_1?.connections ?? []) {
                edges.push({ source: nodeId, target: state.nodeByDf[connection.node] });
            }
        }
        return { nodes, edges };
    }

    async function save() {
        const button = $('ad-save');
        button.disabled = true;
        button.textContent = t.saving;
        try {
            const graph = await api('POST', cfg.urls.save, canvasState());
            state.graph = null;
            apply(graph);
            toast(t.saved);
        } catch (error) {
            toast(error.message, true);
            setDirty(true);
        } finally {
            button.textContent = t.save;
        }
    }

    async function act(action, nodeId, input) {
        const node = state.nodes[nodeId];
        if (!node || node.type !== 'agent') return;
        if (action === 'toggle' && !input.checked && !confirm(t.confirm_disable)) { input.checked = true; return; }
        if (action === 'stop' && !confirm(t.confirm_stop)) return;
        state.busy.add(nodeId);
        repaint(node);
        try {
            if (action === 'toggle') {
                const result = await api('POST', url('toggle', nodeId), { enabled: input.checked });
                toast(result.enabled ? t.toggled_on : t.toggled_off);
            } else if (action === 'run') {
                const result = await api('POST', url('run', nodeId));
                toast(t.run_started.replace(':pid', result.run?.pid ?? '?'));
            } else if (action === 'stop') {
                await api('POST', url('stop', nodeId));
                toast(t.run_stopped);
            }
        } catch (error) {
            toast(error.message, true);
        } finally {
            state.busy.delete(nodeId);
            await load();
            if (state.drawerId === nodeId) refreshDrawer();
        }
    }

    // Controls inside a node must not start a node drag or a selection.
    ['mousedown', 'touchstart'].forEach((type) => canvas.addEventListener(type, (event) => {
        if (event.target.closest('[data-no-drag]')) event.stopPropagation();
    }, true));

    let pressAt = null;
    canvas.addEventListener('pointerdown', (event) => { pressAt = [event.clientX, event.clientY]; }, true);
    canvas.addEventListener('click', (event) => {
        const control = event.target.closest('[data-action]');
        const nodeEl = event.target.closest('[data-node]');
        if (!nodeEl || event.target.closest('.ad-switch')) return;
        const nodeId = nodeEl.dataset.node;
        if (control && control.dataset.action !== 'details') {
            act(control.dataset.action, nodeId);
            return;
        }
        const moved = pressAt && Math.hypot(event.clientX - pressAt[0], event.clientY - pressAt[1]) > 4;
        if (control || !moved) openDrawer(nodeId);
    });
    canvas.addEventListener('change', (event) => {
        const input = event.target.closest('[data-action="toggle"]');
        if (input) act('toggle', input.closest('[data-node]').dataset.node, input);
    });

    editor.on('connectionCreated', (c) => {
        if (state.building) return;
        if (state.nodeByDf[c.input_id] === state.nodeByDf[c.output_id]) {
            editor.removeSingleConnection(c.output_id, c.input_id, c.output_class, c.input_class);
            return;
        }
        setDirty(true);
    });
    editor.on('connectionRemoved', () => { if (!state.building) setDirty(true); });
    editor.on('nodeMoved', () => setDirty(true));

    $('ad-refresh').addEventListener('click', () => { state.graph = state.dirty ? state.graph : null; load(); });
    $('ad-fit').addEventListener('click', () => {
        editor.zoom_reset();
        editor.canvas_x = 0;
        editor.canvas_y = 0;
        editor.precanvas.style.transform = 'translate(0px, 0px) scale(1)';
    });
    if ($('ad-save')) $('ad-save').addEventListener('click', save);
    window.addEventListener('beforeunload', (event) => { if (state.dirty) { event.preventDefault(); event.returnValue = ''; } });

    // ---- Drawer ----------------------------------------------------------
    const drawer = $('ad-drawer');
    $('ad-drawer-close').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && state.drawerId) closeDrawer(); });

    function openDrawer(nodeId) {
        const node = state.nodes[nodeId];
        if (!node) return;
        state.drawerId = nodeId;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        $('ad-drawer-title').textContent = node.label;
        $('ad-drawer-dot').parentElement.className = `ad-drawer__head ad-status-${node.status}`;
        if (node.type === 'system') {
            clearTimeout(drawerTimer);
            $('ad-drawer-body').innerHTML = section(t.details, `<p class="ad-muted">${esc(t.drawer.system_node)}</p>
                <dl class="ad-kv"><dt>path</dt><dd>${esc(node.description)}</dd><dt>${esc(t.inputs)}</dt><dd>${esc(node.inputs.join(', '))}</dd></dl>`);
            return;
        }
        $('ad-drawer-body').innerHTML = `<p class="ad-muted">${esc(t.drawer.loading)}</p>`;
        refreshDrawer();
    }

    function closeDrawer() {
        state.drawerId = null;
        clearTimeout(drawerTimer);
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    }

    async function refreshDrawer() {
        const nodeId = state.drawerId;
        if (!nodeId || state.nodes[nodeId]?.type !== 'agent') return;
        clearTimeout(drawerTimer);
        try {
            const data = await api('GET', url('logs', nodeId));
            if (state.drawerId !== nodeId) return;
            const status = state.nodes[nodeId]?.status;
            $('ad-drawer-dot').parentElement.className = `ad-drawer__head ad-status-${status}`;
            $('ad-drawer-body').innerHTML = renderLogs(data);
            const pre = $('ad-drawer-body').querySelector('[data-log-tail]');
            if (pre) pre.scrollTop = pre.scrollHeight;
            drawerTimer = setTimeout(refreshDrawer, data.run?.running ? 2500 : 10000);
        } catch (error) {
            $('ad-drawer-body').innerHTML = `<p class="ad-error">${esc(error.message)}</p>`;
        }
    }

    const section = (title, body) => `<div class="ad-section"><h3>${esc(title)}</h3>${body}</div>`;
    const kv = (rows) => `<dl class="ad-kv">${rows.filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>`;

    function renderLogs(data) {
        const d = t.drawer;
        const o = data.last_outcome;
        const outcome = o
            ? `<div class="ad-node__row" style="margin:0 0 .5rem"><span class="ad-chip ad-chip-${esc(o.verdict)}">${esc(t.status[o.verdict] || o.verdict)}</span><span class="ad-muted">${esc(o.status)}</span></div>`
              + `<p class="ad-muted" style="direction:ltr;text-align:left">${esc(o.summary)}</p>`
              + kv([[t.task, o.task_type], [d.duration, o.duration_ms !== null ? `${o.duration_ms} ms` : null], ['correlation', o.correlation_id], ['at', o.occurred_at], ['error', o.error]])
            : `<p class="ad-muted">${esc(t.status.IDLE)}</p>`;

        const r = data.run;
        const run = r
            ? kv([[d.pid, r.pid], [t.task, r.task], [d.started, r.started_at], [d.stopped, r.stopped_at], ['state', r.running ? d.running : d.finished], ['cmd', r.command]])
              + `<pre data-log-tail>${esc(r.log_tail || '')}</pre>`
            : `<p class="ad-muted">${esc(d.no_run)}</p>`;

        const findings = data.findings.length
            ? data.findings.map((f) => `<div class="ad-finding">
                <div class="ad-finding__head"><span class="ad-chip ad-chip-${esc(f.severity)}">${esc(String(f.severity).toUpperCase())}</span><code>${esc(f.code)}</code>${f.subject ? `<span class="ad-finding__subject">${esc(f.subject)}</span>` : ''}</div>
                <p>${esc(f.message)}</p>${f.remedy ? `<p><strong>${esc(d.remedy)}:</strong> ${esc(f.remedy)}</p>` : ''}</div>`).join('')
            : `<p class="ad-muted">${esc(d.no_findings)}</p>`;

        const a = data.artifact;
        let artifact = `<p class="ad-muted">${esc(d.no_artifact)}</p>`;
        if (a) {
            let pretty = a.content || '';
            if (!a.truncated) { try { pretty = JSON.stringify(JSON.parse(pretty), null, 2); } catch (e) { /* keep raw */ } }
            artifact = `<div class="ad-node__row" style="margin:0 0 .5rem"><span class="ad-chip ad-chip-${a.verified ? 'READY' : 'BLOCKED'}">${esc(a.verified ? d.verified : d.invalid)}</span><span class="ad-muted">${esc(a.kind || '')}</span></div>`
                + kv([['id', a.artifact_id], [d.recorded_digest, a.digest], [d.computed_digest, a.computed_digest], ['created', a.created_at], ['path', a.path]])
                + (a.errors.length ? `<ul class="ad-error">${a.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul>` : '')
                + (a.truncated ? `<p class="ad-muted">${esc(d.truncated)}</p>` : '')
                + (pretty ? `<pre>${esc(pretty)}</pre>` : '');
        }

        const journal = data.journal.length
            ? `<ul class="ad-journal">${data.journal.slice().reverse().map((m) => `<li><time>${esc((m.occurred_at || '').replace('T', ' ').slice(0, 19))}</time><span>${esc(m.topic)} <span class="ad-muted">&lt;${esc(m.source)}&gt;</span></span></li>`).join('')}</ul>`
            : `<p class="ad-muted">${esc(d.no_journal)}</p>`;

        return section(d.last_outcome, outcome) + section(d.run, run) + section(d.findings, findings)
            + section(d.artifact, artifact) + section(d.journal, journal);
    }

    load();
})();
</script>
@endsection
