# Deployment Agent

Lightweight, dependency-free PHP agent that runs on a target production
server and executes deployments on the service's behalf. Requires only the
PHP CLI with `curl`, `json`, and `pcntl`-free process control (`proc_open`) —
no Composer install needed on production.

## How it works

1. Polls `GET {service_base_url}/api/agent/jobs/next` on an interval.
2. Every request is bearer-authenticated (`agent_token`) and HMAC-signed
   (`signing_secret`) with a timestamp + nonce, so requests cannot be
   replayed or forged even if intercepted.
3. When a job is returned, the agent independently re-verifies the job
   payload's HMAC signature before executing anything — a network position
   that has the bearer token alone still cannot inject deployment commands.
4. Runs each configured pipeline step locally via `proc_open` (no SSH,
   because the agent already runs on the box) and streams step output and
   exit codes back to the service as it goes.
5. Reports a final `succeeded` / `failed` status for the job.

The service never opens a connection to the agent. Only outbound HTTPS from
the server is required, so production does not need a public SSH surface or
any inbound port opened for deployment tooling.

## Setup

1. Copy `config.example.php` to `config.php` and fill in the values issued
   by the Deployment Service dashboard when you register this server
   (`server_id`, `agent_token`, `signing_secret`), plus the local
   `working_directory` for each project this server hosts.
2. `chmod 600 config.php` — it holds credentials.
3. Run continuously via systemd (`install/deployment-agent.service.example`)
   or, on hosts without a service manager, via cron
   (`install/crontab.example` with `--once`).
4. Confirm it's polling: check `storage/logs/agent-YYYY-MM-DD.log`.

## What this agent will never do

- Accept inbound connections.
- Execute a job whose payload signature doesn't verify.
- Execute a job for a `project_key` not present in its local `config.php`.
- Read or write outside the working directory configured for the job's
  project.
