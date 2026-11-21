# Deployment System

A standalone, zero-inbound-port deployment pipeline that sits between GitHub and production servers, ensuring development tools and control planes never touch production environments directly.

```text
GitHub  --push-->  Deployment Service  <--poll--  Deployment Agent (on each server)
        (webhook)   (service/, Laravel)            (agent/, plain PHP)
```

## Architecture Overview

- **`service/`** — The central Laravel control plane. Receives GitHub webhooks, manages deployment jobs, tracks commit hashes, stores execution logs, displays real-time status, and handles manual approvals and rollbacks. See `service/README.md` and `docs/ARCHITECTURE.md`.
- **`agent/`** — The lightweight, dependency-free PHP agent. Runs on target production servers, polls the service strictly over outbound HTTPS, executes configured pipeline steps locally, and streams logs and status back. See `agent/README.md`.
- **`docs/ARCHITECTURE.md`** — Full architectural specification covering data flow, cryptographic request signing, and security boundaries.

## Why Outbound Polling Over Push

The Deployment Service never initiates an inbound connection to a target server. Instead, the agent living on each server always initiates connections outward:

- **Zero Inbound Ports:** No inbound firewall ports need to be opened on production servers for deployment tooling.
- **No SSH Key Distribution:** Eliminates shared SSH keypairs and removes SSH attack surfaces from the central control plane.
- **Strict Firewall Compatibility:** A production server can block all inbound management traffic, allow only outbound HTTPS, and still support fully automated deployments and rollbacks.

## Getting Started

1. **Set up `service/`:** Configure `DB_CONNECTION` in `.env`, run database migrations, and create an initial administrator via `php artisan deployment:create-admin` (see `service/README.md`).
2. **Register a target server:** In the web dashboard, register a server and copy the one-time generated `server_id`, `agent_token`, and `signing_secret`.
3. **Install `agent/` on the server:** Deploy the lightweight `agent/` directory onto the target host (see `agent/README.md`) and populate `agent/config.php` (gitignored) with your credentials and local project paths.
4. **Configure a project:** Create a project in the dashboard specifying the repository, branch, target server, and shell pipeline commands, then add the generated webhook URL and secret to your GitHub repository settings.
5. **Deploy:** Push to the tracked branch. Depending on the project's `auto_deploy` policy, approve the job in the dashboard or let it queue automatically for the agent's next poll cycle.

## Security & Design Principles

- **Deterministic Runtime:** The deployment execution path relies purely on standard PHP and Laravel with zero runtime AI dependencies.
- **Zero Hardcoded Infrastructure:** No server hostnames, IP addresses, credentials, or filesystem paths exist in source control; all environment configuration is managed dynamically via the database or gitignored `config.php` files.

## Ecosystem Repositories

This repository is part of a modular, self-hosted open-source ecosystem:

- **[saas-platform](https://github.com/safhenokhostweb-art/saas-platform)** — Core multi-tenant orchestration engine, RBAC, and central API.
- **[customer-site](https://github.com/safhenokhostweb-art/customer-site)** — Tenant-facing web application and storefront.
- **[deployment-system](https://github.com/safhenokhostweb-art/deployment-system)** — Standalone pull-based deployment service and server agent *(this repository)*.
- **[agents](https://github.com/safhenokhostweb-art/agents)** — AI-driven operational agents for diagnostics, log analysis, and workflow automation.

## License

Released under the [MIT License](LICENSE).
