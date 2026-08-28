# Production migration plan: immutable releases + atomic symlink

## Why the current activation is unsafe

All three production targets are physical directories. `ArtifactManager::atomicSwap()` first renames the live directory to a backup and only then renames staging to the live name. Those are two atomic operations with a non-atomic interval between them. More importantly, an in-flight PHP request can bootstrap from the old tree and resolve later includes from the new tree. The deployment-system failure `ReflectionException: Class "view" does not exist` is consistent with that mixed-release window.

Maintenance mode avoids mixed traffic only by deliberately returning customer-visible 503 responses. It is not appropriate for routine backward-compatible releases.

## Target filesystem contract

Each project migrates independently:

```text
/var/www/<project>/
  current -> releases/<release-id>
  previous -> releases/<previous-release-id>
  releases/<release-id>/       # immutable after activation
  shared/.env
  shared/storage/
  shared/public-storage/       # where applicable
```

Nginx serves `/var/www/<project>/current/public`. FastCGI must pin the resolved immutable release for each request:

```nginx
fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
```

Using `$realpath_root` is mandatory; `$document_root` containing `current` can resolve late framework includes through a newly switched symlink.

## Release transaction

1. Acquire a per-project `flock`; reject concurrent deployment.
2. Download and verify the signed artifact into a new immutable release directory.
3. Link only allowlisted shared state; never copy mutable `storage` or uploads into a release.
4. Run completeness checks and isolated Laravel bootstrap against the candidate.
5. Run `config:cache`, `route:cache`, and `view:cache` inside the candidate only. Never run `optimize:clear` against `current`.
6. Verify no destructive/pending migration is bundled. Run only reviewed backward-compatible migrations before activation; destructive migrations are a separate change window.
7. Run a candidate health check using its immutable path and the same PHP binary.
8. Create `current.next` pointing to the candidate and atomically rename it over `current` (`rename(2)` / `mv -Tf`). The old target is never absent or renamed.
9. Health-gate direct Origin first, then both known CDN edges. On failure, atomically point `current` back to the captured prior target.
10. Gracefully recycle queues with `artisan queue:restart`; allow old workers and in-flight requests to finish before retention pruning. Do not hard-restart Supervisor groups during the normal path.
11. Retain at least two prior immutable releases. Pruning is asynchronous and must never follow shared-state symlinks.

Routine releases do not call `artisan down`, reload Nginx, or reload PHP-FPM. Maintenance is reserved for a separately approved, incompatible database transition.

## Staged production migration

1. `customer-site`: create release/shared layout from the current tree, validate permissions and `$realpath_root`, switch during an approved low-traffic window, then run Origin/CDN gates. It has no routine maintenance step and is the lowest-risk pilot.
2. `saas-platform`: repeat after validating Reverb and all queue programs can finish jobs against retained immutable releases.
3. `deployment-system`: migrate last. Remove routine maintenance only after candidate-cache execution and graceful worker recycling are proven. This project serves the control plane, so its rollback gate must also verify both Agents remain connected.

No production symlink conversion should occur until the operator approves the project-specific backup, exact current/previous targets, Nginx candidate diff, database migration classification, and rollback command.

## Required acceptance evidence per project

- 20,000-read concurrency simulation reports zero missing/mixed values.
- Candidate Laravel bootstrap and cache commands pass before activation.
- 30 Origin and 30 requests to each CDN edge contain no 500/502/503/504.
- Nginx/PHP-FPM start and reload timestamps remain unchanged during deployment.
- Queue workers exit gracefully and no job is lost or duplicated.
- Forced candidate-health failure leaves `current` on the old target.
- Atomic rollback restores the previous target without a missing-path interval.
