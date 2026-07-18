# Deployment System — Architecture

## 1. Components

| Component | Location | Runs where | Stack |
|---|---|---|---|
| Deployment Service | `service/` | Anywhere reachable by GitHub and by every managed server over HTTPS (its own host, not on production) | Laravel 13 / PHP 8.3+, SQLite by default (any Laravel-supported DB works) |
| Deployment Agent | `agent/` | On each production/staging server being deployed to | Plain PHP 8.1+, no Composer dependencies, `curl` + `proc_open` |

Both are entirely independent of `saas-platform` (or any app being
deployed). Neither imports from, nor is imported by, an existing
application.

## 2. Data flow

```
1. Developer pushes to GitHub
2. GitHub sends a `push` webhook -> Deployment Service
     - verified via per-project HMAC secret (X-Hub-Signature-256)
3. Service creates a Deployment row:
     status = pending_approval   (default)
     status = queued             (if the project has auto_deploy = true)
4. [optional] A human approves it in the dashboard -> status = queued
5. Agent polls  GET /api/agent/jobs/next  (every N seconds, outbound only)
     - bearer token + HMAC-signed request
     - Service atomically claims the oldest queued job for that server,
       flips it to `running`, and returns a *signed* job payload
6. Agent verifies the job payload's signature, then runs each pipeline
   step locally (git pull, composer install, migrate, cache clear, build),
   substituting {branch}/{commit}/{working_directory}/{php_binary}
7. Agent streams each step's output back:  POST /api/agent/jobs/{id}/log
8. Agent reports final result:  POST /api/agent/jobs/{id}/status
     -> succeeded | failed
9. Dashboard shows live status, full log, and deployment history
```

Rollback (§6) and manual deploys reuse steps 4-9 unchanged — from the
agent's point of view every job looks identical, it just targets whatever
commit the Service put in the job payload.

## 3. Data model

- **Server** — a registration record for one target machine: `public_id`
  (used as `server_id` in the agent's config), a hashed bearer token, an
  encrypted signing secret, `last_seen_at`. No hostname/IP/SSH info is
  stored — the service never connects to it.
- **Project** — one deployable unit: `project_key` (must match a key in
  that server's local agent config), `repository`, `branch`, `environment`,
  which `Server` it deploys to, its `pipeline` (ordered JSON steps), an
  `auto_deploy` flag, and its own GitHub webhook secret.
- **Deployment** — one job: `action` (deploy|rollback), `status`
  (pending_approval → queued → running → succeeded|failed, or cancelled),
  `commit_hash`, `previous_commit_hash`, who triggered/approved it, and
  timestamps for each transition. Self-referencing `rollback_of_deployment_id`
  links a rollback to the deployment it targets.
- **DeploymentLogEntry** — one row per pipeline step: command, captured
  output, exit code, duration.
- **AuditLogEntry** — append-only: every webhook received/rejected, every
  approval/cancellation/rollback, every credential rotation, every agent
  auth rejection, with actor, subject, and metadata.
- **AgentRequestNonce** — one row per (server, nonce) ever accepted; the
  unique constraint is what makes replay detection atomic.

## 4. Security model

Three independent controls, each defeating a different attacker position:

1. **GitHub → Service**: `X-Hub-Signature-256` HMAC, keyed per-project.
   Defeats: someone who knows the webhook URL but not the project's secret.
2. **Agent → Service (request auth)**: every call carries a bearer token
   (identifies the server) *and* an HMAC signature over
   `method + path + timestamp + nonce + body` (proves the request wasn't
   forged or altered), plus a 5-minute timestamp window and a one-time
   nonce enforced by a DB unique constraint. Defeats: token theft alone
   (still need the signing secret), and replay of a captured request.
3. **Service → Agent (job auth)**: the job payload itself (repository,
   commit, branch, and the exact pipeline commands) is separately HMAC-signed
   with the server's signing secret, and the agent verifies that signature
   before running anything. Defeats: any component between "the service
   decided to deploy X" and "the agent executes X" being able to substitute
   a different command — including a bug elsewhere in the service that
   might otherwise let an attacker read the job queue but not forge a
   signature.

Two distinct secrets per server (`agent_token`, `signing_secret`) are
issued so leaking one channel doesn't compromise the other. Both are
shown exactly once at creation/rotation time; only a hash (token) or an
encrypted-at-rest value (signing secret, needed in plaintext for HMAC) is
persisted.

No public SSH is required anywhere in this design: the agent runs
commands locally via `proc_open` because it already lives on the target
server.

## 5. Approval / auto-deploy

Each Project independently chooses:
- `auto_deploy = false` (default): every push creates a `pending_approval`
  deployment; a human clicks Approve in the dashboard to queue it.
- `auto_deploy = true`: pushes are queued immediately.

Either way, every deployment is visible in the dashboard with its
approver (or `auto_deploy` as the recorded approver) and full audit trail.

## 6. Rollback

Rollback does not use a special "undo" pipeline. `RollbackService` simply
creates a new Deployment whose `commit_hash` is the target's commit and
whose `rollback_of_deployment_id` points at that prior successful
deployment. The agent runs its normal pipeline against that older commit —
`git reset --hard <commit>` plus the same migrate/cache/build steps. This
keeps the agent simple (one code path for every job type) and keeps
rollback history exactly as visible as any other deployment.

`rollbackToPrevious()` is a convenience that finds the last successful
deployment before the current one; `rollbackTo($deployment)` targets any
specific past successful deployment shown in the project's history.

## 7. Multi-project support

Server, Project, and Deployment are separate tables specifically so one
Service instance can manage many repositories, each with its own branch,
target server, environment, pipeline, and webhook secret. A single server
can host multiple projects (e.g. a shared box running two apps); a single
repository can be tracked by multiple projects (e.g. staging and
production Projects both watching `main`, deploying to different servers
with different pipelines).

## 8. What's intentionally NOT here

- No SSH keys, hostnames, or IPs anywhere in the codebase or database —
  the agent's `config.php` (local to each server, gitignored) is the only
  place that knows its own filesystem paths.
- No hardcoded pipeline — every project's steps are editable JSON in the
  dashboard; the "Laravel defaults" shown when creating a project are a
  starting template, not a fixed behavior.
- No dependency on Claude/Codex or any AI tooling in the deploy path.
- No calls back into `saas-platform` or any other existing application.
