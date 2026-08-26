/* Semantic UI foundation: presentation tokens and additive ui-* primitives. */
:root {
    --ui-font-sans: 'Vazirmatn', Tahoma, "Segoe UI", Arial, sans-serif;
    --ui-font-mono: "Courier New", ui-monospace, monospace;
    --ui-text-xs: 0.75rem;
    --ui-text-sm: 0.85rem;
    --ui-text-md: 1rem;
    --ui-text-lg: 1.25rem;
    --ui-text-xl: 1.75rem;
    --ui-leading: 1.65;

    --ui-space-1: 0.25rem;
    --ui-space-2: 0.5rem;
    --ui-space-3: 0.75rem;
    --ui-space-4: 1rem;
    --ui-space-5: 1.25rem;
    --ui-space-6: 1.5rem;
    --ui-space-8: 2rem;

    --ui-radius-sm: 0.375rem;
    --ui-radius-md: 0.5rem;
    --ui-radius-lg: 0.75rem;
    --ui-radius-pill: 999px;
    --ui-shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.06);
    --ui-shadow-md: 0 8px 24px rgba(15, 23, 42, 0.10);

    --ui-canvas: #f5f6f8;
    --ui-surface: #ffffff;
    --ui-surface-subtle: #f7f8fa;
    --ui-text: #1c1e21;
    --ui-text-muted: #57606a;
    --ui-border: #e1e4e8;
    --ui-brand: #1c2733;
    --ui-focus: #2563eb;

    --ui-success-bg: #d4f7dc;
    --ui-success-text: #14622e;
    --ui-success-border: #9fdfaf;
    --ui-warning-bg: #fff3cd;
    --ui-warning-text: #7a5b00;
    --ui-warning-border: #ecd478;
    --ui-danger-bg: #ffd7d7;
    --ui-danger-text: #8a1f1f;
    --ui-danger-border: #eeaaaa;
    --ui-info-bg: #cdeaff;
    --ui-info-text: #045d8a;
    --ui-info-border: #9acfee;
    --ui-neutral-bg: #eef1f4;
    --ui-neutral-text: #4b5563;
    --ui-neutral-border: #d5dae0;
}

:where(a, button, input, textarea, select, summary, [tabindex]):focus-visible {
    outline: 3px solid color-mix(in srgb, var(--ui-focus) 45%, transparent);
    outline-offset: 2px;
}

.ui-page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ui-space-4); flex-wrap: wrap; margin-block-end: var(--ui-space-5); }
.ui-page-header__content { min-width: 0; }
.ui-page-header__eyebrow { color: var(--ui-text-muted); font-size: var(--ui-text-xs); font-weight: 600; margin-block-end: var(--ui-space-1); }
.ui-page-header__title { font-size: var(--ui-text-xl); line-height: 1.35; margin: 0; overflow-wrap: anywhere; }
.ui-page-header__description { color: var(--ui-text-muted); margin: var(--ui-space-2) 0 0; max-width: 70ch; }
.ui-page-header__actions { display: flex; align-items: center; gap: var(--ui-space-2); flex-wrap: wrap; }

.ui-card { background: var(--ui-surface); border: 1px solid var(--ui-border); border-radius: var(--ui-radius-md); box-shadow: var(--ui-shadow-sm); padding: var(--ui-space-5); }
.ui-card__header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ui-space-3); margin-block-end: var(--ui-space-4); }
.ui-card__title { font-size: var(--ui-text-md); margin: 0; }
.ui-card__description { color: var(--ui-text-muted); font-size: var(--ui-text-sm); margin: var(--ui-space-1) 0 0; }
.ui-card__actions { display: flex; gap: var(--ui-space-2); flex-wrap: wrap; }

.ui-stat-card { min-width: 0; }
.ui-stat-card__label { color: var(--ui-text-muted); display: block; font-size: var(--ui-text-sm); }
.ui-stat-card__value { display: block; font-size: var(--ui-text-xl); line-height: 1.25; margin-block-start: var(--ui-space-2); }
.ui-stat-card__context { color: var(--ui-text-muted); display: block; font-size: var(--ui-text-xs); margin-block-start: var(--ui-space-2); }
.ui-stat-card--success .ui-stat-card__value { color: var(--ui-success-text); }
.ui-stat-card--warning .ui-stat-card__value { color: var(--ui-warning-text); }
.ui-stat-card--danger .ui-stat-card__value { color: var(--ui-danger-text); }
.ui-stat-card--info .ui-stat-card__value { color: var(--ui-info-text); }

.ui-status { align-items: center; border: 1px solid; border-radius: var(--ui-radius-pill); display: inline-flex; font-size: var(--ui-text-xs); font-weight: 600; gap: var(--ui-space-1); line-height: 1.4; padding: 0.15rem 0.55rem; white-space: nowrap; }
.ui-status__icon { align-items: center; display: inline-flex; font-family: var(--ui-font-sans); justify-content: center; min-inline-size: 1em; }
.ui-status--small { font-size: 0.68rem; padding: 0.1rem 0.4rem; }
.ui-status--success { background: var(--ui-success-bg); border-color: var(--ui-success-border); color: var(--ui-success-text); }
.ui-status--warning { background: var(--ui-warning-bg); border-color: var(--ui-warning-border); color: var(--ui-warning-text); }
.ui-status--danger { background: var(--ui-danger-bg); border-color: var(--ui-danger-border); color: var(--ui-danger-text); }
.ui-status--info { background: var(--ui-info-bg); border-color: var(--ui-info-border); color: var(--ui-info-text); }
.ui-status--neutral { background: var(--ui-neutral-bg); border-color: var(--ui-neutral-border); color: var(--ui-neutral-text); }

.ui-alert { border: 1px solid; border-radius: var(--ui-radius-md); display: flex; gap: var(--ui-space-3); padding: var(--ui-space-4); }
.ui-alert__icon { flex: none; font-weight: 700; }
.ui-alert__body { min-width: 0; }
.ui-alert__title { display: block; margin-block-end: var(--ui-space-1); }
.ui-alert--success { background: var(--ui-success-bg); border-color: var(--ui-success-border); color: var(--ui-success-text); }
.ui-alert--warning { background: var(--ui-warning-bg); border-color: var(--ui-warning-border); color: var(--ui-warning-text); }
.ui-alert--danger { background: var(--ui-danger-bg); border-color: var(--ui-danger-border); color: var(--ui-danger-text); }
.ui-alert--info { background: var(--ui-info-bg); border-color: var(--ui-info-border); color: var(--ui-info-text); }
.ui-alert--neutral { background: var(--ui-neutral-bg); border-color: var(--ui-neutral-border); color: var(--ui-neutral-text); }

.ui-empty { color: var(--ui-text-muted); padding: var(--ui-space-6); text-align: center; }
.ui-empty__icon { display: block; font-size: var(--ui-text-lg); margin-block-end: var(--ui-space-2); }
.ui-empty__title { color: var(--ui-text); display: block; }
.ui-empty__description { margin: var(--ui-space-2) auto 0; max-width: 55ch; }
.ui-empty__actions { display: flex; justify-content: center; gap: var(--ui-space-2); margin-block-start: var(--ui-space-4); }

.ui-health { align-items: flex-start; display: inline-flex; gap: var(--ui-space-2); }
.ui-health__dot { background: currentColor; border-radius: 50%; flex: none; block-size: 0.65rem; inline-size: 0.65rem; margin-block-start: 0.35em; }
.ui-health__label { font-weight: 600; }
.ui-health__detail { color: var(--ui-text-muted); display: block; font-size: var(--ui-text-xs); }
.ui-health--success { color: var(--ui-success-text); }
.ui-health--warning { color: var(--ui-warning-text); }
.ui-health--danger { color: var(--ui-danger-text); }
.ui-health--info { color: var(--ui-info-text); }
.ui-health--neutral { color: var(--ui-neutral-text); }

.ui-timeline { list-style: none; margin: 0; padding: 0; }
.ui-timeline > * { border-inline-start: 2px solid var(--ui-border); padding-block-end: var(--ui-space-4); padding-inline-start: var(--ui-space-5); position: relative; }
.ui-timeline > *::before { background: var(--ui-neutral-border); border: 2px solid var(--ui-surface); border-radius: 50%; content: ''; inline-size: 0.75rem; block-size: 0.75rem; inset-inline-start: -0.45rem; position: absolute; inset-block-start: 0.25rem; }
.ui-timeline > *:last-child { border-inline-start-color: transparent; padding-block-end: 0; }
.ui-timeline__time { color: var(--ui-text-muted); display: block; font-size: var(--ui-text-xs); }
.ui-timeline__event { align-items: center; display: flex; gap: var(--ui-space-2); flex-wrap: wrap; }
.ui-timeline__title { font-weight: 600; }
.ui-timeline > .ui-timeline__item--success::before { background: var(--ui-success-text); }
.ui-timeline > .ui-timeline__item--warning::before { background: var(--ui-warning-text); }
.ui-timeline > .ui-timeline__item--danger::before { background: var(--ui-danger-text); }
.ui-timeline > .ui-timeline__item--info::before { background: var(--ui-info-text); }

.ui-deployment-verdict { border: 1px solid var(--ui-border); border-inline-start-width: 0.35rem; border-radius: var(--ui-radius-lg); display: grid; gap: var(--ui-space-5); grid-template-columns: minmax(0, 1fr) auto; margin-block-end: var(--ui-space-5); padding: var(--ui-space-5); }
.ui-deployment-verdict--success { background: color-mix(in srgb, var(--ui-success-bg) 42%, var(--ui-surface)); border-inline-start-color: var(--ui-success-text); }
.ui-deployment-verdict--warning { background: color-mix(in srgb, var(--ui-warning-bg) 42%, var(--ui-surface)); border-inline-start-color: var(--ui-warning-text); }
.ui-deployment-verdict--danger { background: color-mix(in srgb, var(--ui-danger-bg) 42%, var(--ui-surface)); border-inline-start-color: var(--ui-danger-text); }
.ui-deployment-verdict--info { background: color-mix(in srgb, var(--ui-info-bg) 42%, var(--ui-surface)); border-inline-start-color: var(--ui-info-text); }
.ui-deployment-verdict--neutral { background: var(--ui-surface); border-inline-start-color: var(--ui-neutral-text); }
.ui-deployment-verdict__summary h2 { font-size: var(--ui-text-lg); margin: var(--ui-space-3) 0 var(--ui-space-1); }
.ui-deployment-verdict__summary p { color: var(--ui-text-muted); margin: 0; }
.ui-deployment-verdict__facts { display: grid; gap: var(--ui-space-2) var(--ui-space-5); grid-template-columns: repeat(2, minmax(8rem, auto)); margin: 0; }
.ui-deployment-verdict__facts div { min-width: 0; }
.ui-deployment-verdict__facts dt, .ui-meta-grid dt { color: var(--ui-text-muted); font-size: var(--ui-text-xs); }
.ui-deployment-verdict__facts dd, .ui-meta-grid dd { margin: var(--ui-space-1) 0 0; overflow-wrap: anywhere; }

.ui-ops-grid { display: grid; gap: var(--ui-space-5); grid-template-columns: repeat(2, minmax(0, 1fr)); margin-block-end: var(--ui-space-5); }
.ui-ops-grid > .ui-card { margin: 0; }
.ui-ops-grid__full { grid-column: 1 / -1; }
.ui-meta-grid { display: grid; gap: var(--ui-space-4); grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
.ui-meta-grid__full { grid-column: 1 / -1; }
.ui-code-wrap { direction: ltr; font-family: var(--ui-font-mono); overflow-wrap: anywhere; text-align: start; unicode-bidi: isolate; }
.ui-action-bar { align-items: center; display: flex; flex-wrap: wrap; gap: var(--ui-space-2); margin-block-end: var(--ui-space-5); }
.ui-action-bar form { margin: 0; }
.ui-stage-list { display: flex; flex-wrap: wrap; gap: var(--ui-space-2); margin-block-end: var(--ui-space-5); }
.ui-health-result { background: var(--ui-surface-subtle); border-radius: var(--ui-radius-sm); color: var(--ui-text-muted); margin-block-start: var(--ui-space-4); overflow-wrap: anywhere; padding: var(--ui-space-3); }
.ui-table-wrap { overflow-x: auto; }
.ui-table-wrap table { min-inline-size: 680px; }
.ui-agent-identity { display: flex; flex-direction: column; gap: var(--ui-space-1); }
.ui-log-list { display: grid; gap: var(--ui-space-3); }
.ui-log-entry { border: 1px solid var(--ui-border); border-radius: var(--ui-radius-md); background: var(--ui-surface); }
.ui-log-entry[open] { box-shadow: var(--ui-shadow-sm); }
.ui-log-entry > summary { align-items: center; cursor: pointer; display: flex; flex-wrap: wrap; gap: var(--ui-space-2); padding: var(--ui-space-3) var(--ui-space-4); }
.ui-log-entry > summary::marker { color: var(--ui-text-muted); }
.ui-log-entry__name { font-weight: 600; }
.ui-log-entry__content { border-block-start: 1px solid var(--ui-border); padding: var(--ui-space-4); }
.ui-log-entry__content pre { margin-block-end: 0; max-block-size: 28rem; }

.topbar-brand { align-items: center; display: inline-flex; gap: var(--ui-space-2); }
.topbar-brand__mark { align-items: center; background: rgba(255,255,255,0.12); border-radius: var(--ui-radius-sm); display: inline-flex; block-size: 1.75rem; inline-size: 1.75rem; justify-content: center; }
.system-status { align-items: center; border: 1px solid rgba(255,255,255,0.15); border-radius: var(--ui-radius-pill); display: inline-flex; gap: var(--ui-space-1); padding: 0.2rem 0.55rem; }
.system-status .dot { margin: 0; }
.system-status--healthy .dot { background: #2fbf71; }
.system-status--degraded .dot { background: #e0a800; }
.system-status--neutral .dot { background: #9aa4af; }
nav.sidebar a[aria-current="page"] { background: #dfe6ee; color: var(--ui-brand); font-weight: 700; }

.ui-skip-link { background: var(--ui-surface); border: 2px solid var(--ui-focus); border-radius: var(--ui-radius-sm); color: var(--ui-text); inset-block-start: var(--ui-space-2); inset-inline-start: var(--ui-space-2); padding: var(--ui-space-2) var(--ui-space-3); position: fixed; transform: translateY(-200%); z-index: 1000; }
.ui-skip-link:focus { transform: translateY(0); }

.ui-sr-only { position: absolute !important; inline-size: 1px !important; block-size: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0, 0, 0, 0) !important; white-space: nowrap !important; border: 0 !important; }

.ui-dashboard-section { margin-block-end: var(--ui-space-5); }
.ui-platform-verdict { align-items: center; background: var(--ui-surface); border: 1px solid var(--ui-border); border-inline-start-width: 0.35rem; border-radius: var(--ui-radius-lg); display: flex; gap: var(--ui-space-5); justify-content: space-between; margin-block-end: var(--ui-space-5); padding: var(--ui-space-5); }
.ui-platform-verdict--success { background: color-mix(in srgb, var(--ui-success-bg) 35%, var(--ui-surface)); border-inline-start-color: var(--ui-success-text); }
.ui-platform-verdict--warning { background: color-mix(in srgb, var(--ui-warning-bg) 35%, var(--ui-surface)); border-inline-start-color: var(--ui-warning-text); }
.ui-platform-verdict--danger { background: color-mix(in srgb, var(--ui-danger-bg) 35%, var(--ui-surface)); border-inline-start-color: var(--ui-danger-text); }
.ui-platform-verdict h2 { font-size: var(--ui-text-lg); margin: var(--ui-space-3) 0 var(--ui-space-1); }
.ui-platform-verdict p { color: var(--ui-text-muted); margin: 0; max-inline-size: 70ch; }
.ui-platform-verdict__actions { flex: none; }
.ui-section-heading { align-items: flex-end; display: flex; justify-content: space-between; margin-block-end: var(--ui-space-4); }
.ui-section-heading h2 { font-size: var(--ui-text-lg); margin: 0; }
.ui-section-heading p { color: var(--ui-text-muted); margin: var(--ui-space-1) 0 0; }
.ui-dashboard-stat-grid { display: grid; gap: var(--ui-space-4); grid-template-columns: repeat(5, minmax(0, 1fr)); }
.ui-recent-release-strip { border-block-start: 1px solid var(--ui-border); margin-block-start: var(--ui-space-4); padding-block-start: var(--ui-space-4); }
.ui-recent-release-strip h3 { font-size: var(--ui-text-sm); margin: 0 0 var(--ui-space-2); }
.ui-recent-release-strip > div { display: flex; flex-wrap: wrap; gap: var(--ui-space-2); }
.ui-release-link { align-items: center; background: var(--ui-surface); border: 1px solid var(--ui-border); border-radius: var(--ui-radius-pill); color: var(--ui-text); display: inline-flex; font-size: var(--ui-text-xs); gap: var(--ui-space-2); padding: var(--ui-space-1) var(--ui-space-3); text-decoration: none; }
.ui-release-link:hover { background: var(--ui-surface-subtle); }
.ui-signal-grid { display: grid; gap: var(--ui-space-3); grid-template-columns: repeat(3, minmax(0, 1fr)); }
.ui-signal { background: var(--ui-surface-subtle); border: 1px solid var(--ui-border); border-radius: var(--ui-radius-md); min-width: 0; padding: var(--ui-space-4); }
.ui-signal__label { color: var(--ui-text-muted); display: block; font-size: var(--ui-text-xs); margin-block-end: var(--ui-space-2); }
.ui-dashboard-metrics { margin-block-end: var(--ui-space-4); }
.ui-dashboard-metrics dd { font-size: var(--ui-text-lg); font-weight: 700; }
.ui-dashboard-metrics dd .muted { font-size: var(--ui-text-xs); font-weight: 400; }
.ui-agent-card-grid { display: grid; gap: var(--ui-space-3); grid-template-columns: repeat(2, minmax(0, 1fr)); }
.ui-agent-card { background: var(--ui-surface-subtle); border: 1px solid var(--ui-border); border-radius: var(--ui-radius-md); min-width: 0; padding: var(--ui-space-3); }
.ui-agent-card__heading { align-items: flex-start; display: flex; gap: var(--ui-space-2); justify-content: space-between; }
.ui-agent-card__heading a { font-weight: 700; min-width: 0; overflow-wrap: anywhere; }
.ui-agent-card dl { display: grid; gap: var(--ui-space-2); margin: var(--ui-space-3) 0 0; }
.ui-agent-card dl div { display: flex; gap: var(--ui-space-2); justify-content: space-between; }
.ui-agent-card dt { color: var(--ui-text-muted); font-size: var(--ui-text-xs); }
.ui-agent-card dd { font-size: var(--ui-text-xs); margin: 0; min-width: 0; overflow-wrap: anywhere; text-align: end; }
.ui-attention-list { display: grid; gap: var(--ui-space-2); }
.ui-attention-item { align-items: center; border: 1px solid var(--ui-border); border-radius: var(--ui-radius-md); color: var(--ui-text); display: grid; gap: var(--ui-space-3); grid-template-columns: auto minmax(0, 1fr) auto; padding: var(--ui-space-3); text-decoration: none; }
.ui-attention-item:hover { background: var(--ui-surface-subtle); }
.ui-attention-item strong, .ui-attention-item small { display: block; overflow-wrap: anywhere; }
.ui-attention-item small { color: var(--ui-text-muted); margin-block-start: var(--ui-space-1); }
.ui-attention-item__action { color: var(--ui-focus); font-size: var(--ui-text-xs); font-weight: 600; }
.ui-dashboard-timeline__event { align-items: baseline; }
.ui-dashboard-timeline .ui-health { margin-inline-start: auto; }
.ui-dashboard-timeline .ui-health__detail { display: none; }

:root[data-theme="dark"] {
    --ui-canvas: #111827;
    --ui-surface: #1f2937;
    --ui-surface-subtle: #273244;
    --ui-text: #f3f4f6;
    --ui-text-muted: #c4cbd4;
    --ui-border: #3f4b5d;
    --ui-neutral-bg: #303b4c;
    --ui-neutral-text: #e5e7eb;
    --ui-neutral-border: #566174;
}

@media (max-width: 640px) {
    .ui-page-header, .ui-card__header { align-items: stretch; flex-direction: column; }
    .ui-page-header__actions, .ui-card__actions { inline-size: 100%; }
    .ui-deployment-verdict { grid-template-columns: 1fr; }
    .ui-deployment-verdict__facts, .ui-meta-grid, .ui-ops-grid { grid-template-columns: 1fr; }
    .ui-ops-grid__full, .ui-meta-grid__full { grid-column: auto; }
    .ui-platform-verdict { align-items: stretch; flex-direction: column; }
    .ui-dashboard-stat-grid, .ui-signal-grid, .ui-agent-card-grid { grid-template-columns: 1fr; }
    .ui-attention-item { grid-template-columns: auto minmax(0, 1fr); }
    .ui-attention-item__action { grid-column: 2; }
    .ui-dashboard-timeline .ui-health { margin-inline-start: 0; }
}

@media (min-width: 641px) and (max-width: 980px) {
    .ui-dashboard-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ui-signal-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
