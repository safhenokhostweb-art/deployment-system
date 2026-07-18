# Production process management

Two long-running/recurring processes the Deployment Service needs in
production, beyond the web server itself. Neither requires a new
dependency — both are built into Laravel.

## Queue worker

Processes `ProcessGitHubWebhookPush` (and any future queued job). Without
a worker running, jobs sit in the `jobs` table forever — webhooks will
still return 202 but no Deployment row will ever be created, so **the
worker is not optional in production**.

Install `queue-worker.supervisor.conf.example` (fill in the `CHANGE_ME`
paths and user) to `/etc/supervisor/conf.d/deployment-service-worker.conf`,
then:

```bash
supervisorctl reread
supervisorctl update
supervisorctl start deployment-service-worker:*
```

Failed jobs (after 3 tries) land in the `failed_jobs` table and get an
`AuditLogEntry` (`webhook.deployment_dispatch_failed`) visible in the
dashboard's audit log. Inspect/retry manually with:

```bash
php artisan queue:failed
php artisan queue:retry <uuid>|all
```

## Scheduler

Drives `agent-nonces:prune` (hourly), `deployments:detect-stalled` (every
5 minutes), and `deployment-system:prune-logs` (daily 03:00) — all defined
in `service/routes/console.php`. Install the single cron line from
`scheduler.crontab.example` with `crontab -e`. Laravel's own scheduler
handles the actual timing; cron just needs to invoke `schedule:run` every
minute.

Verify either is wired up correctly with:

```bash
php artisan schedule:list
php artisan queue:monitor database:default
```
