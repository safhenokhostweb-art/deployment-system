# Customer-Site Persistent Runtime Contract

## Scope and layout

`customer-site` releases are immutable. Application code, `vendor/`,
`public/build/`, Laravel entrypoints, and the release manifest come only from
the validated Artifact. Runtime data is never an Artifact input.

The supported uploads contract is:

```text
artifact_persistent_root: /var/www/customer-site-shared
artifact_persistent_paths:
  public/uploads => uploads

/var/www/customer-site/public/uploads
  -> /var/www/customer-site-shared/uploads
```

The production release layout is intentionally fixed at the Server Agent
`working_directory` `/var/www/customer-site`; there is no `current/`
indirection. The Agent rejects any customer-site persistence job configured
with another working directory, including `/var/www/customer-site/current`.
Supporting a different release root requires a separately implemented and
reviewed release-layout migration.

The Hub stores this mapping and sends it only in the signed deployment job.
Server Agent 1.3.3 or newer is required. Older agents fail closed at claim time.

## Activation lifecycle

Before Artifact extraction or activation, the fixed root-owned helper for the
literal `customer-site` project key idempotently creates and normalizes:

```text
/var/www/customer-site-shared
/var/www/customer-site-shared/uploads
```

Directories are `deployment-agent:www-data` mode `2775`; files below uploads
are `deployment-agent:www-data` mode `0664`. No application source directory is
made group-writable, and mode `777` is forbidden.

Artifact preparation then removes any staged `public/uploads` file or directory
and creates a symlink to the real shared target. Artifact content at that path
is discarded from staging and is never merged with runtime data. A missing
target, invalid/escaping mapping, helper failure, or symlink failure aborts
before the atomic swap and leaves the active release unchanged.

The existing `ArtifactManager` atomic rename activates code only. Its backups
contain code and symlinks, not uploaded files. Rollback restores the previous
code directory; both releases reference the same shared uploads target.

Legacy preserve behavior for `.env`, `storage`, and `public/storage` is
unchanged. `public/uploads` must not also appear in preserve paths.

## First production migration procedure (operator-run; not automatic)

Do not enable the Hub mapping until the existing upload location has been
identified and this copy has completed. These commands are a procedure only;
this repository change does not execute them.

Set explicit paths after selecting the old release. Never point `OLD_UPLOADS`
at the shared destination.

```bash
OLD_UPLOADS=/var/www/customer-site/previous-release/public/uploads
SHARED_UPLOADS=/var/www/customer-site-shared/uploads

test -d "$OLD_UPLOADS"
sudo install -d -o deployment-agent -g www-data -m 2775 /var/www/customer-site-shared "$SHARED_UPLOADS"

find "$OLD_UPLOADS" -type f -printf '%P\n' | LC_ALL=C sort > /tmp/customer-upload-source-files.txt
find "$SHARED_UPLOADS" -type f -printf '%P\n' | LC_ALL=C sort > /tmp/customer-upload-destination-files.txt
comm -12 /tmp/customer-upload-source-files.txt /tmp/customer-upload-destination-files.txt > /tmp/customer-upload-conflicts.txt
wc -l /tmp/customer-upload-source-files.txt /tmp/customer-upload-destination-files.txt /tmp/customer-upload-conflicts.txt
```

If the conflict count is non-zero, stop and review every listed relative path.
The supported copy refuses to overwrite destination files:

```bash
sudo -u deployment-agent rsync -a --ignore-existing --itemize-changes --dry-run "$OLD_UPLOADS"/ "$SHARED_UPLOADS"/
sudo -u deployment-agent rsync -a --ignore-existing --itemize-changes "$OLD_UPLOADS"/ "$SHARED_UPLOADS"/
sudo /usr/local/sbin/deployment-runtime-permissions customer-site

find "$OLD_UPLOADS" -type f | wc -l
find "$SHARED_UPLOADS" -type f | wc -l
```

This is copy-only: it performs no move, deletion, or source cleanup, preserves
the relative directory structure, reports planned/copied entries, and does not
overwrite conflicts. Keep the old release and its uploads intact until a later,
separately approved retention decision.

## Pre-deployment checklist

1. Complete and reconcile the copy procedure above.
2. Configure the Hub mapping exactly as shown under “Scope and layout”.
3. Confirm the Server Agent heartbeat reports version 1.3.3 or newer.
4. Build and deploy through the normal complete-Artifact gate.
5. Verify `readlink -f /var/www/customer-site/public/uploads`, ownership/modes, a
   known uploaded file, and the customer-facing HTTP health check.
