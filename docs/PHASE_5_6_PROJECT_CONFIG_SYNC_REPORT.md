# Phase 5.6 — Local Agent Project-Config Synchronization

Supersedes the manually-maintained `projects` array in
`deployment-local-agent/config/config.php` with a synchronized,
cached, TTL/version-gated configuration downloaded from the Hub. This
document describes the resulting lifecycle for operators and future
maintainers; it does not repeat implementation detail already covered by
comments in the source files it references.

## 1. Where project configuration actually lives

| Field | Source of truth |
|---|---|
| `project_key` | `Project.project_key` (Hub, one row per Project) |
| `local_path` | `LocalProjectMapping.local_path` (Hub, one row per Local Agent + Project pair) |
| `remote_name` | `LocalProjectMapping.remote_name` (Hub, default `origin`) |
| `branch` | `LocalProjectMapping.git_branch`, falling back to `Project.branch` — see `LocalProjectMapping::effectiveBranch()` |

The Hub is the only place these are edited. `config.php`'s `projects`
array is a legacy, compatibility-only fallback (§4) — it is never the
normal path.

## 2. Synchronization lifecycle

1. **Startup.** The agent's first poll cycle always synchronizes,
   unconditionally, before doing anything else project-related.
2. **Steady state.** Every subsequent cycle sends a heartbeat first (as it
   always did), then asks `ProjectConfigSyncScheduler::shouldSync()`
   whether *this* cycle should also spend a real
   `GET /api/local-agent/projects` round trip. It answers yes only when:
   - the configured TTL (`project_config_ttl_seconds`, default 300s) has
     elapsed since the last sync attempt, or
   - the Hub's heartbeat response carries a `project_config_version`
     that differs from the version this agent last synchronized to
     (`LocalAgent::projectConfigVersion()` — a SHA-256 fingerprint of the
     agent's active project mappings, computed from data that already
     exists, no new column/table), or
   - a `reload_config` Control Job was claimed on a previous cycle (see
     §5).
3. Every other cycle skips the network call entirely and keeps using
   whatever `$projects` the last real sync (or fallback, §4) produced.
   Heartbeats, publish-job polling, change detection, and control-job
   polling are unaffected — they still happen every cycle exactly as
   before.

This means an Admin editing a mapping on the Hub is picked up in one of
two ways: within one heartbeat cycle (near-instant, via the
`project_config_version` check) if the agent is online, or immediately on
demand via a Force Config Reload (§5) — the TTL is only a safety-net
upper bound on how stale the config can silently get, not the primary
detection path.

## 3. Cache lifecycle

- **Location:** `dirname(log_dir) . '/cache/projects.json'` by default,
  overridable via `cache_path` in `config.php`.
- **Format:** `{"version":1,"synced_at":"<ISO8601>","projects":{"<project_key>":{"local_path":...,"remote_name":...,"branch":...}}}`.
- **Written** only on a successful sync, via write-to-temp-then-`rename()`
  — never a partial/corrupt file, even on a crash mid-write.
- **Read** only when a sync attempt this cycle fails (Hub unreachable, bad
  status, malformed body, or a project entry that fails structural
  validation) — see §4.
- **Never contains** `agent_token`, `signing_secret`, or any other
  authentication secret — only project metadata. Enforced by
  `ProjectConfigCache::assertValid()`'s narrow schema and covered by a
  dedicated test.
- **Invalid cache** (corrupt JSON, missing `projects` key, or a
  structurally invalid entry) is a distinct, loudly-logged condition
  (`InvalidProjectCacheException`), never silently treated the same as
  "no cache file yet."

## 4. Fallback order and its logging

Priority, evaluated only when a real sync attempt is made and fails:

1. **Synchronized configuration** — the live Hub response, when the sync
   attempt succeeds. Logged: `Configuration synchronized: N project(s)
   from Hub.` If the Hub responds successfully with **zero** mappings,
   that is logged as its own distinct warning — `Hub reachable and
   synchronized successfully, but this agent has zero assigned
   projects...` — never conflated with a communication failure.
2. **Cached configuration** — the last good local cache, when the sync
   attempt itself fails but a valid cache exists. Logged as `Cache loaded
   from ...` followed by `Hub unavailable — using cached project
   configuration.`
3. **Legacy configuration** — the manually-authored `projects` array in
   `config.php`, only when neither of the above is available. Logged at
   **ERROR** level (not WARN) with an unmissable message:
   `USING LEGACY CONFIGURATION — SYNCHRONIZATION IS NOT ACTIVE ... This is
   a TEMPORARY compatibility fallback, not normal operation ...`. This is
   deliberate: an operator must never mistake this state for
   synchronization merely running a bit stale.
4. **Nothing available** — logged as an explicit error
   (`No project configuration available: ...`); the agent continues
   running (heartbeats, control jobs) with zero projects rather than
   crashing.

## 5. The administrator workflow

**Mapping a project to an agent** (`Local Agents -> <agent> -> Project
mappings`):

- **Single project:** the existing one-row form (project dropdown +
  local_path/branch/remote).
- **Several projects at once:** the "Quick-add remaining projects"
  section lists every Project not yet mapped to this agent, one row each,
  with `local_path` pre-filled as `C:\laragon\www\<project_key>` (the
  convention this environment already uses) and `remote_name` pre-filled
  as `origin` — both editable before submitting. Check the rows to add,
  submit once. This reuses the exact same `LocalProjectMapping::create()`
  path as the single-row form; it does not silently create anything —
  every row is visible and editable before the one explicit submit.
- **Editing** local_path/branch/remote on an existing mapping is done
  inline in the mappings table, no delete-and-recreate needed.

**Forcing an immediate reload** (`Local Agents -> <agent> -> Force Config
Reload` button): queues a `reload_config` Control Job — the same
queue/poll/report channel already used for `restart`/`fetch_logs`, since
there is deliberately no other inbound path to a developer's machine.
The agent picks it up on its next control-job poll (same cadence as
today, ~`poll_interval_seconds`) and forces a sync on the following
cycle, regardless of TTL.

## 6. Recovery when mappings are missing

If a developer's agent reports "zero assigned projects" (see the §4
warning) or a PublishJob fails with "Unknown project: ... not present in
the synchronized, cached, or legacy project configuration":

1. Go to `Local Agents -> <agent>` in the Hub.
2. Use the single-row form or the "Quick-add remaining projects" section
   to create a `LocalProjectMapping` for the missing project.
3. Either wait for the next heartbeat (the agent notices the
   `project_config_version` change automatically) or click **Force Config
   Reload** to apply it immediately.
4. Confirm the mapping appears by checking the agent's log for
   `Configuration synchronized: N project(s) from Hub.`

If the Hub itself is unreachable, nothing needs to be "recovered" —
the agent is already using its last good cache (or, in the worst case,
`config.php`'s legacy array) and will resynchronize automatically the
moment the Hub is reachable again on a subsequent cycle.

## 7. Why full automatic mapping creation was not implemented

`local_path` is inherently local-machine-only knowledge — a Windows
developer's `C:\laragon\www\...`, a Linux developer's `~/code/...`, or
anything else. The Hub is never told this at registration time (see
`agent.php`'s own docblock: "the Hub only ever refers to a project by its
project_key — it never knows or stores this machine's paths"), by
deliberate design, mirroring the same "strictly opt-in" boundary the
production Server Agent's own Local Discovery feature already respects.
Auto-creating a `LocalProjectMapping` at registration time would require
either violating that boundary (the agent reporting its own folder layout
back to the Hub unprompted) or guessing a path convention that is wrong
for many developers/OSes — either one risks `PublishJob` execution
running against the wrong folder, which is a correctness/safety
regression, not a convenience. The bulk quick-add workflow in §5 was
chosen instead: it removes the *repetitive form-filling*, not the
*human review*, of a step that only a human can safely complete.
