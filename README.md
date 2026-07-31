# Deployment System

A standalone deployment pipeline that sits between GitHub and production
servers, so development tools (including this one) never touch production
directly.

```
GitHub  --push-->  Deployment Service  <--poll--  Deployment Agent (on each server)
        (webhook)   (service/, Laravel)            (agent/, plain PHP)
```

- **`service/`** — the Laravel app. Receives GitHub webhooks, manages
  deployment jobs, tracks commit hashes, stores logs, shows status, handles
  approvals and rollbacks. See `service/README.md` (dashboard usage) and
  `docs/ARCHITECTURE.md`.
- **`agent/`** — the lightweight PHP agent. Runs on a target server, polls
  the service over outbound HTTPS only, executes the configured pipeline
  locally, and reports logs/status back. See `agent/README.md`.
- **`docs/ARCHITECTURE.md`** — full design write-up: data flow, security
  model, why the split works this way.

## Why the agent polls instead of the service pushing

The service never connects to a server. The agent, which lives on that
server, always initiates the connection outward. That means:

- No inbound port has to be opened on production for deployment tooling.
- No SSH keypair distribution or SSH surface exposed to the Deployment
  Service.
- A firewall on the production box can allow only outbound HTTPS and still
  fully support deployments.

## Getting started

1. Stand up `service/` (see its README) — set `DB_CONNECTION`, run
   migrations, create an admin user with `php artisan deployment:create-admin`.
2. In the dashboard, register a server. Copy the `server_id`, `agent_token`,
   and `signing_secret` shown once.
3. Install `agent/` on that server (see `agent/README.md`), paste those
   values plus the local working directory for each project into
   `agent/config.php`.
4. In the dashboard, create a project (repository, branch, server, pipeline
   commands). Copy the generated webhook URL + secret into the GitHub repo's
   webhook settings.
5. Push to the tracked branch. Depending on the project's `auto_deploy`
   flag, either approve the deployment in the dashboard or let it queue
   automatically. The agent picks it up on its next poll.

## Restrictions honored

- Nothing here modifies any existing application; this is a new, separate
  project directory with its own git history.
- No dependency on Claude/Codex or any AI tooling is wired into the
  deployment path — it's plain PHP/Laravel executing configured shell
  commands.
- No server hostnames, IPs, credentials, or filesystem paths are hardcoded
  anywhere in the code. Servers, projects, pipelines, and credentials are
  all data — created through the dashboard/database (service side) or a
  local `config.php` you fill in yourself (agent side, gitignored).
- Nothing was committed or pushed by the agent building this.

Local Agent Test 07/27/2026 23:02:36
