# Deployment Service

The Laravel half of the [Deployment System](../README.md) — receives GitHub
webhooks, manages deployment jobs, and serves the dashboard. See
[`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) for the full design.

## Requirements

PHP 8.3+, Composer. SQLite is the default `DB_CONNECTION` (zero setup); any
Laravel-supported database works if you change it in `.env` before
migrating.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan deployment:create-admin   # prompts for name/email/password
php artisan serve
```

Sign in at `/login` with the admin account you just created.

## Using the dashboard

1. **Servers → Register a server.** Give it a name. The `server_id`,
   `agent_token`, and `signing_secret` shown on the next page are shown
   **once** — copy them into that server's `agent/config.php`
   (see [`../agent/README.md`](../agent/README.md)). "Rotate credentials"
   on the same page issues new ones if they're ever lost or leaked.
2. **Projects → New project.** Set the repository (`owner/name`), branch,
   environment, target server, whether pushes auto-deploy or need approval,
   and the pipeline (JSON array of `{name, command}` steps — a Laravel
   default template is pre-filled, edit freely). Saving shows the GitHub
   webhook URL and secret to paste into that repo's webhook settings
   (Settings → Webhooks → Add webhook, content type
   `application/json`, events: just the `push` event).
3. **Push to the tracked branch.** A deployment appears under the project,
   `pending_approval` unless `auto_deploy` is on. Approve it (or wait for
   the agent) and watch status/logs update as the agent polls, runs each
   step, and reports back.
4. **Roll back** from any past `succeeded` deployment's page — it queues a
   new deployment targeting that commit through the normal pipeline.

## Running tests

```bash
php artisan test
```

Covers webhook signature verification, agent request signing/replay
protection, cross-server authorization, approval/rollback state
transitions — see `tests/Feature`.

## Notes

- `github_webhook_secret` (per project) and `signing_secret` (per server)
  are stored via Laravel's `encrypted` cast — losing `APP_KEY` makes them
  unrecoverable, so back it up like any other production secret.
- No server hostname, IP, or filesystem path is ever stored here — that
  information lives only in each server's local `agent/config.php`.
