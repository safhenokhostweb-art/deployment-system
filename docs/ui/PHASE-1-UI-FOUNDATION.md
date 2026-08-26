# Phase 1 UI Foundation

## Scope

This phase adds a semantic presentation foundation to the existing Laravel Blade dashboard. It does not change routes, permissions, APIs, database schema, deployment behavior, Artifact flow, Agent behavior, or pipeline execution.

## Current UI dependency map

- `resources/views/layouts/app.blade.php` owns the authenticated shell, font declarations, legacy dashboard CSS, navigation, notifications, and flash messages.
- `resources/views/components/` contains seven legacy primitives: alert, badge, card, confirm dialog, empty state, LTR isolation, and wizard steps.
- Authenticated pages use server-rendered Blade and handcrafted CSS. They do not load the Vite/Tailwind bundle.
- Tailwind is installed and used by the default welcome page only. It was not introduced into the operational dashboard in this phase.
- `lang/{en,fa}/status.php` supplies existing status labels. `SetDashboardLocale` selects Persian for web requests without changing API locale behavior.

The legacy `badge`, `card`, `alert`, `empty-state`, `confirm-dialog`, `ltr`, and `wizard-steps` components remain in place because existing pages depend on their markup and behavior. The confirmation dialog and LTR primitive are safety-sensitive and were not replaced.

## Token approach

`resources/views/layouts/partials/ui-foundation.blade.php` is included by the existing layout style block, so authenticated pages gain tokens without requiring a frontend build or a second CSS runtime.

Tokens cover:

- Typography and line height
- A compact spacing scale
- Border radii and shadows
- Canvas, surface, text, border, and brand colors
- Success, warning, danger, info, and neutral semantics
- A consistent `:focus-visible` ring
- Logical RTL-safe properties such as `margin-block`, `padding-inline`, and `inset-inline`

Selected legacy base styles consume tokens with fallback values. The fallbacks preserve current rendering if the token partial is unavailable. Dark values are defined behind an explicit future `data-theme="dark"` hook and do not enable a partially styled dark mode today.

## Status registry approach

`App\Support\Ui\StatusRegistry` maps a domain and existing status string to presentation metadata:

- Canonical fallback label
- Translation key
- Semantic level
- Icon identifier
- Actionable flag
- Terminal flag

Initial domains are Deployment, Artifact, Agent, and Health. The registry does not define transitions, mutate models, or reinterpret backend values. Unknown values receive a neutral fallback so a new backend status cannot break rendering.

## New component list

All components are additive and namespaced under `resources/views/components/ui/`:

- `status-badge`: domain-aware status label, icon, semantic level, and metadata
- `page-header`: title, description, eyebrow, and named action slot
- `stat-card`: KPI label, value, context, and semantic emphasis
- `alert-panel`: accessible semantic message panel
- `timeline`: RTL-safe ordered event container
- `empty-state`: title, description, icon, and action slot
- `info-card`: semantic card section with header and actions
- `health-indicator`: health-specific status and supporting detail

Existing components were not removed or redirected. Pages can migrate incrementally in later phases.

## Layout foundation changes

- Added a keyboard skip link and focus target.
- Added a semantic primary-navigation label.
- Added `aria-current` to the active navigation destination without changing routes.
- Structured the brand markup for future identity work.
- Added an accessible notification control label.
- Changed the server summary dot from always green to neutral, healthy, or degraded based on the same existing server counts.
- Preserved every permission condition and navigation destination.

## Future UI phases

1. Apply page headers, status badges, cards, and empty states incrementally to existing pages.
2. Reorganize deployment detail around operational verdict, failure summary, targets, health, provenance, and logs.
3. Add read-only Deployment and Artifact inventory views using existing backend contracts.
4. Build the ten-second operations dashboard and unified Agent fleet view.
5. Add a permission-aware audit/history interface.

Each later phase should keep UI projections separate from workflow rules and include RTL, accessibility, authorization, and query-bound tests.
