# One-Click Agent Installer

## 1. Executive Summary

This patch adds a single downloadable script, `install-agent.sh`, that
turns Agent installation into exactly two commands on the target
server:

```
chmod +x install-agent.sh
sudo ./install-agent.sh
```

Everything the operator previously had to do by hand — create
`config.php`, copy the token/secret, create the install directory, find
a PHP binary, create a system user, set permissions, write a systemd
unit, run `daemon-reload`/`enable`/`start`, and wait for the first
heartbeat — is now done by the script itself. No Deployment Engine,
Agent Protocol, Authentication, or GitHub Integration code was touched;
this is entirely new UX on top of the exact same Server credential
model (`agent_token`/`signing_secret`) and the exact same Agent source
(`agent/agent.php` + `agent/lib/*.php`) that already existed. All
pre-existing tests pass unmodified; 12 new tests were added.

**Update after real Ubuntu 24.04 testing (round 1):** the first version
of this patch failed on a real server at the "Verify Source Integrity"
step for `agent.php`. §6.1 below documents the exact bug (a heredoc
reconstruction off-by-one-newline, not a real corruption/tampering
issue) and the fix, which is included in the version described here.

**Update after real Ubuntu 24.04 testing (round 2):** after the round-1
fix, the install got further but the verification poll got HTTP 500
from the Hub. §14 documents the full investigation — including the
honest limit of what could be confirmed from this environment (this
Hub instance's own log has no record of the incident request at all)
— and the concrete robustness fix that was made regardless (a safe
JSON error body for any genuinely uncaught exception on an agent
route, `bootstrap/app.php`).

**Round 3 (found by code review, not a live install):** the php-curl
check could false-negative under this script's own `set -o pipefail`
due to a classic `grep -q` + `SIGPIPE` race. §15 documents the fix
(capture-then-check, applied consistently everywhere the script pipes
into `grep -q`) and a regression test that reproduces the exact race
with real bash.

## 2. What The User Actually Runs

Exactly this, and nothing else — no prompts, no manual file edits:

```
chmod +x install-agent.sh
sudo ./install-agent.sh
```

## 3. Files Changed

**New:**
- `service/tests/Feature/Dashboard/OneClickAgentInstallerTest.php`
- `service/tests/Feature/Api/OneClickInstallerPollIntegrationTest.php`
  (Round 2 — see §14)
- `docs/ONE_CLICK_AGENT_INSTALLER_REPORT.md` (this file)

**Modified:**
- `service/app/Http/Controllers/Dashboard/ServerController.php` — two
  new private methods, `buildOneClickInstallerContent()` and
  `collectProjectKeysFor()`; `show()` now also passes
  `oneClickInstallerContent` to the view (built only alongside the
  existing `agentConfigContent`, i.e. only on the one-time page load
  right after registration or credential rotation). Round 2 (§6.1) fixed
  a heredoc-reconstruction bug in the same method; Round 3 (§15) fixed
  a `pipefail`/`SIGPIPE` false-negative in its php-curl/systemd checks.
- `service/resources/views/servers/show.blade.php` — a new card (inside
  the existing one-time credentials block) with the download button,
  the three-step Persian instructions, and the client-side Blob-download
  script — same pattern already used for `config.php` and
  `deployment-agent.service` downloads.
- `service/lang/{en,fa}/ui.php` — new `servers.one_click_*` keys.
- `service/bootstrap/app.php` — Round 2 (§14): one new `render()`
  callback, scoped to `api/agent/*`, that converts a genuinely uncaught
  exception into a fixed, safe JSON body instead of an APP_DEBUG
  -dependent one. Every existing status code/body for an intentional
  rejection (401/403/404/409/422, `HttpExceptionInterface` or
  `ValidationException`) is explicitly excluded and unchanged.

**Not touched at all:** routes, migrations, `agent/agent.php`,
`agent/lib/*`, the Agent Protocol (`routes/api.php`,
`VerifyAgentRequestSignature`, `Client.php`, `Signer.php`),
Authentication, GitHub Integration. The script's own copy of the Agent
source is read from the existing `agent/` directory at generation time,
never duplicated into a second maintained copy.

## 4. Why No New Route Was Needed

The brief's Task 3 asks for a button that "generates a dedicated file
for that Server." The existing Server Setup Wizard (Phase 4-L) already
solved exactly this shape of problem for `config.php` and
`deployment-agent.service`: build the file's content server-side in the
controller, embed it in a hidden `<pre>`, and let a small client-side
script turn it into a downloaded file via `Blob`/`URL.createObjectURL`.
The one-click installer reuses that identical pattern instead of adding
a new download endpoint — one less URL that could ever be requested
outside the page's own one-time render.

## 5. Why The Installer Is Only Available Once

`agent_token` is stored only as a one-way hash
(`AgentCredentialService::hashToken()`); the Hub cannot recover its
plaintext after the one-time session flash from `store()`/
`rotateToken()` is gone — this is the existing, unchanged security
model (`signing_secret` is stored `encrypted`/reversible on purpose,
since both sides must compute the same HMAC, but `agent_token` never
is). The installer embeds both, so it inherits the exact same
one-time-only availability `agentConfigContent` already has — no new
"installer token" concept was invented, and nothing about how
credentials are stored or issued changed. If the file is lost before
running it, "Rotate credentials" (already existing) issues a fresh pair
and, with it, a fresh installer.

This directly satisfies Task 9's "one-time or expiring" requirement
using an existing mechanism rather than a new one.

## 6. Full Install Flow

The script is generated by reading the Hub's own `agent/agent.php` and
every `agent/lib/*.php` file at generation time (so it can never drift
from what this Hub build actually ships), computing a SHA-256 checksum
of each, and embedding all of it — plus a per-server `config.php` and a
concrete systemd unit — into one self-contained bash script.

```
Phase A — pure checks, nothing created yet, plain exit on failure:
  1. must run as root (sudo)
  2. must be Linux
  3. must have systemd (systemctl)
  4. find a PHP CLI (php8.4 → php8.1 → php), verify php-curl is loaded
  5. verify the Hub URL is HTTPS (localhost/127.0.0.1 over HTTP → warning only)

Phase B — state gets created, so a trap/rollback is armed first:
  6. create system user "deployment-agent" (skipped if it already exists)
  7. write agent.php + lib/*.php via quoted heredocs, verify each
     against its embedded SHA-256 checksum
  8. write config.php (real service_base_url, server_id, agent_token,
     signing_secret, and this server's real project_keys — see §7),
     chmod 600
  9. create a placeholder working_directory per project under
     /opt/deployment-agent/projects/{project_key}
 10. chown the whole install directory to the new system user
 11. run `agent.php --once` synchronously as that user and inspect its
     own output for an ERROR/WARN log line (see §8) — this is the real
     connectivity proof, before anything is left running permanently
 12. write /etc/systemd/system/deployment-agent.service with concrete
     values (no CHANGE_ME placeholders — the script already knows the
     real PHP path, user, and install directory)
 13. daemon-reload, enable, restart
 14. confirm `systemctl is-active` reports active
 15. print the four-line Persian success message
 16. delete itself (it held a copy of the credentials)
```

### 6.1 Real-Ubuntu-Testing Bug Fix: Checksum Mismatch on `agent.php`

**Report from real testing (Ubuntu 24.04):** every prerequisite check
passed (PHP 8.3 found, php-curl enabled, systemd present, the
`deployment-agent` system user was created successfully), then the
installer stopped at step 7 above with:

```
بررسی صحت فایل agent.php ناموفق بود — فایل نصب‌کننده ممکن است در کپی خراب شده باشد.
```

Rollback ran correctly and left the system clean.

**Root cause — confirmed, not a guess.** It was a bug in how each
embedded file's heredoc block was assembled in
`buildOneClickInstallerContent()`, not a checksum-algorithm mismatch,
not CRLF/LF, and not the browser/download path:

```php
// Before the fix:
$fileBlocks .= "cat > \"\$INSTALL_DIR/{$relativePath}\" <<'{$delimiter}'\n{$contents}\n{$delimiter}\n";
//                                                                        ^^ always added, unconditionally
```

Every real source file already ends with its own trailing `\n` (verified:
`agent/agent.php` ends `...}\n}\n`). The template above appended
*another* `\n` right after `{$contents}` before the closing delimiter
line — which bash's heredoc parser reads as one extra **blank line**
inside the captured body. Since a quoted heredoc (`<<'EOF'`) preserves
its body completely literally, `cat > file <<'EOF' ... EOF` wrote that
extra trailing newline straight into the file on disk. The written file
was one byte longer than the file the checksum (computed from the
original, un-modified `$contents`) actually described — so
`verify_checksum()` correctly detected a real mismatch. It was working
exactly as designed; the *content it was checking* was wrong.

This reproduces on **any** platform, independent of line endings or the
browser download step — confirmed by writing a regex-based test (see
§12) that parses the exact heredoc body out of the generated script and
hashes it the same way bash would, with no network/browser/OS
involved. It failed before the fix and passes after.

**The six things asked to be checked, and what was found:**

1. *SHA-256 computed identically at build and verify time?* — Yes, this
   was always true; the checksum VALUE was never wrong.
2. *CRLF/LF difference altering the checksum?* — Not the cause here
   (every file in this repo is currently plain LF — confirmed with
   `grep -c $'\r'`, zero matches), but fixed defensively anyway (see
   below), since a Windows checkout of this repo could in principle
   have CRLF.
3. *Trim/escaping/extra newline during embed?* — **Yes — this was the
   actual bug**, an unconditional extra `\n` before the closing
   delimiter.
4. *Does heredoc/decode reproduce the original exactly?* — No, off by
   one trailing newline; fixed.
5. *Verified on real Ubuntu 24.04 with matching checksums?* — Not
   re-run on the same physical box from here (no such access from this
   environment), but the exact heredoc body bash would write is now
   asserted, in a test, to hash to precisely the checksum embedded next
   to it — the same comparison `verify_checksum()` performs on a real
   server, reproduced deterministically.
6. *Print Expected/Actual SHA-256 on failure?* — Added to
   `verify_checksum()` (no secret is ever passed to that function, so
   nothing sensitive is exposed):
   ```bash
   say "Expected SHA256 ($label): $expected"
   say "Actual SHA256   ($label): $actual"
   ```

**The fix.** Before computing each file's checksum, its content is
normalized to a single well-defined trailing newline (and CRLF/CR
collapsed to LF, defensively), and the template no longer adds a second
`\n` of its own:

```php
$contents = str_replace(["\r\n", "\r"], "\n", $contents);
$contents = rtrim($contents, "\n") . "\n";
$checksum = hash('sha256', $contents);
// ...
$fileBlocks .= "cat > \"\$INSTALL_DIR/{$relativePath}\" <<'{$delimiter}'\n{$contents}{$delimiter}\n";
```

The checksum is now always computed from **exactly** the bytes the
heredoc will reproduce — this is true regardless of whatever trailing
whitespace or line-ending convention happens to exist on whichever
machine currently has the Hub's `agent/` source checked out, which is
what makes this fix robust rather than a one-off patch for today's
specific file contents.

## 7. Project Mappings

Task 3 asks for "valid project mappings for this Server" without the
operator typing anything. The Hub knows this server's real
`project_key`s (via the same legacy-`server_id` + new-domain
`Environment`/`DeploymentTarget` union `Server::soleProject()` already
uses, generalized to return every match instead of just a sole one —
see `collectProjectKeysFor()`). It has never known, and by this
project's own long-standing design still does not know, any project's
real filesystem path on that specific server (Project has no
`working_directory` column — that's deliberately local-server-only
information, the same principle `DeploymentTarget` already follows).
So the installer pre-fills each known `project_key` with a placeholder
`working_directory` under `/opt/deployment-agent/projects/{key}` and
creates that empty directory. This makes the Agent immediately correct
and pollable for every project with zero manual config editing; putting
the actual project code in that directory (or repointing
`working_directory` to wherever it already lives) is a separate,
later, deployment-readiness step — explicitly out of this patch's
scope ("فقط نصب و راه‌اندازی Agent").

## 8. How "Successful Connection" Is Confirmed Without Any Protocol Change

The Hub is strictly poll-based and can never reach out to a server, so
there is no existing (or new) endpoint the installer could ask "did you
hear from me yet." Instead, the installer runs the just-installed
`agent.php --once` **synchronously**, as the same unprivileged user the
systemd service will later run as, and captures its combined
output. `sendHeartbeat()` and the job-poll call already log a `WARN`/
`ERROR` line on any failure and log nothing on success (see
`agent/agent.php`'s `sendHeartbeat()` and `pollAndExecute()`) — so the
absence of any `] ERROR ` / `] WARN ` line in that one synchronous run
is a direct, honest read of the real outcome, not a guess. Only after
this passes does the script enable and start the permanent systemd
service. This required zero new Hub endpoints and zero Agent Protocol
changes — it works because the Agent's own existing logging already
distinguishes success from failure.

The existing "Check connection now" button/link (Phase 4-L) is left
as-is for Task 11 — it reflects what the Hub can actually see
(`Server::isOnline()`, last poll time), which is the only "systemd
status" a poll-only Hub can honestly report from its own side. Live
local `systemctl status` is checked by the installer itself, on the
server, at install time (step 14 above) — that is the correct place for
that check, not the Hub.

## 9. Rollback Model

Rollback is intentionally **not** "always wipe everything on any
error" — that would be actively harmful on a re-run against an already
-working install. The script detects at startup whether a
`deployment-agent.service` unit already exists
(`FRESH_INSTALL=0`/`1`). If a failure happens:

- **Fresh install (`FRESH_INSTALL=1`)** and something was already
  created this run (`STARTED_NEW_INSTALL=1`): stop/disable the
  half-configured service, remove the systemd unit, remove
  `/opt/deployment-agent` entirely, and remove the system user if this
  run created it — the system is left exactly as if the installer had
  never been run.
- **Re-run against an existing install (`FRESH_INSTALL=0`)**: the
  previous, working installation is left untouched. Only this attempt's
  failure is reported; nothing that was already running gets stopped or
  deleted because of a new, unrelated error.

Every failure path goes through one `fail()` function: it logs the
reason, runs the rollback decision above, always prints the log path
(`/tmp/deployment-agent-install.log`) and the literal retry command
(`sudo ./install-agent.sh`), and never prints a secret value.

## 10. Idempotency

Re-running the same (or a freshly downloaded) installer against a
server that's already set up does not break it: the system user check
is `id ... || useradd`, directories use `mkdir -p`, every embedded file
and `config.php` are simply overwritten with the same (or updated)
content, `systemctl enable` is a no-op if already enabled, and the
service is `restart`ed (not merely `start`ed) so any changed config
actually takes effect.

## 11. Security Model

- **HTTPS enforced** for any non-localhost Hub URL — the script refuses
  to proceed (`exit 1`, nothing created yet) if `service_base_url`
  isn't `https://`, except a `http://localhost`/`127.0.0.1` URL, which
  only gets a warning (so this same installer keeps working for local
  development against this very Hub).
- **Checksummed self-contained payload.** Each embedded Agent source
  file carries a SHA-256 computed by the Hub at generation time; the
  script recomputes it after writing the file and refuses to continue
  on a mismatch. Since the whole payload is embedded (no separate
  network download step), this protects against a corrupted copy of
  `install-agent.sh` itself, not a MITM'd second request.
- **No secret in argv or repeated output.** The token/secret are
  written once into `config.php` via a quoted heredoc (`<<'EOF'` —
  bash performs zero interpolation or history expansion on its
  content) and never appear in any `echo`/log line the script prints;
  a dedicated test (`test_plain_token_appears_exactly_once...`) asserts
  each secret string appears exactly once in the whole generated
  script.
- **`config.php` is `chmod 600`**, owned by a new unprivileged,
  no-login system user (`deployment-agent`, `--shell /usr/sbin/nologin`)
  — not root, not an existing account.
- **Self-deleting.** On success the script removes itself
  (`shred -u`, falling back to `rm -f` if `shred` isn't installed) —
  it held a copy of the same credentials now living in `config.php`, so
  leaving it on disk afterward would be a second, unnecessary copy of a
  secret.
- **No architecture/protocol change.** The Agent's HMAC signing
  (`Signer.php`), the Hub's `agent.signed` middleware, and the
  Bearer-token + `X-Deployment-*` header scheme are all completely
  unmodified — the installer only automates *placing* the same
  credentials that manual setup always used.

## 12. Testing

**What PHPUnit verifies (12 new tests, all passing):**
- the installer is only rendered on the one-time page load right after
  `servers.store`/`servers.rotate-token`, and gone on a later visit
  (mirrors `agentConfigContent`'s existing, unchanged one-time gate);
- it is hidden from a non-admin even if a session flash is artificially
  present (defense-in-depth beyond the fact that only Admin can trigger
  `store()`/`rotateToken()` in the first place);
- the generated script contains this server's own `public_id` and
  never another server's `public_id` or `signing_secret`;
- it contains this server's real `project_key`s and the matching
  `/opt/deployment-agent/projects/{key}` directories;
- the embedded `agent.php` checksum matches a SHA-256 computed
  independently, directly from the real file on disk, in the test;
- **for every one of the 10 embedded source files, the exact heredoc
  body bash would write is parsed back out of the generated script and
  hashed — asserted equal to the checksum embedded right next to it**
  (`test_heredoc_body_for_each_embedded_file_matches_its_own_checksum_exactly`)
  — this is the regression test for the real bug found on Ubuntu 24.04
  (§6.1); it fails against the pre-fix code and passes against the fix;
- the systemd unit has no `CHANGE_ME` placeholders and correctly
  references `$PHP_BIN`/`$INSTALL_DIR`;
- the HTTPS check and the self-delete (`shred -u`) line are present;
- each embedded secret string appears in the script **exactly once**;
- `bash -n` (a real syntax check, executed via Git Bash, not simulated)
  reports the generated script as syntactically valid.
- Full suite: **333/333 passing** (321 pre-existing, unmodified, + 12
  new).

**What is explicitly NOT verified by this test suite, and why:** actual
execution on a Linux host — `useradd`, `systemctl`, the rollback path
actually undoing a real half-install, a real heartbeat reaching a real
Hub over HTTPS, and idempotent re-execution against a live systemd
service. This development environment is Windows with no real
Linux/systemd target available, so none of that can be honestly claimed
as machine-verified here. An earlier attempt to bridge this by actually
shelling out to a real `bash` and writing extracted files under
Windows' `AppData\Local\Temp` was dropped — it worked, but ran slowly
and unreliably enough (real-time antivirus scanning on that path is the
suspected cause) to make it a bad permanent test; the heredoc-body
regex approach above verifies the identical byte-level guarantee
deterministically and in milliseconds instead. What *was* done on a
real machine: this exact bug was found and reported by testing on a
real Ubuntu 24.04 server (§6.1), and the fix responds directly to that
report. Before relying on this again in production, one more real run
against that same (or a disposable) Ubuntu box remains the honest final
check — everything else here is as close to that as this development
environment allows.

## 13. Remaining Out of Scope (By Design)

- Placing real project code at `/opt/deployment-agent/projects/{key}`
  (or repointing `working_directory`) is still a separate, manual step
  — this patch installs the Agent, not a project's deployment
  readiness.
- No change to what happens after the Agent is online — approval,
  deployments, operations, and the Readiness page all work exactly as
  they did before this patch.

## 14. Round 2: HTTP 500 on Poll — Investigation and Fix

**Report from real testing (Ubuntu 24.04), after §6.1's fix:** every
step up through starting the Agent succeeded (PHP 8.3, php-curl,
checksums, `deployment-agent` user, `config.php`, Agent started), but
the verification poll got HTTP 500 from both `/api/agent/jobs/next`
and `/api/agent/operation-jobs/next`; rollback ran and left the system
clean.

### 14.1 What I could and could not verify

I do not have shell/log access to whatever Hub instance the real
Ubuntu box actually reached. I checked the one Hub instance this
development environment does have direct access to —
`service/storage/logs/laravel.log` on this machine — for anything
around `2026-07-23T15:02:48Z`. **There is nothing there**: the log's
last write is timestamped roughly five hours earlier and is PHPUnit
test-runner output, not a real HTTP request. This is hard evidence that
the specific request which 500'd was not handled by this exact running
instance/checkout. I cannot fetch the real exception, file, or line
from a server this environment has no access to, and I'm not going to
present a guess as if it were a confirmed trace.

**Given that, the single most likely root cause is a version/deployment
mismatch**: if the real Hub the Ubuntu box points at is a separate
deployment of this codebase that was set up before some of this
project's later phases (or before §6.1's fix earlier today), it may be
missing a migration, a route, or a class the current `agent.php` now
calls. **First thing to check on the real Hub itself** (not here):
```
git log -1                          # is it running the latest commit?
php artisan migrate:status          # any migration NOT "Ran"?
tail -f storage/logs/laravel.log    # then trigger the poll again and watch it live
```

### 14.2 What I verified instead, exhaustively, in this environment

I read every line of code between "signed request arrives" and
"`{"job": null}` returned" for both endpoints
(`VerifyAgentRequestSignature`, `AgentJobController::next()`/
`claimNextJob()`, `AgentOperationController::next()`) and could not
find a defect for the one-click installer's exact credential shape
(Server-level `agent_token`/`signing_secret`, no separate `Agent` row —
the installer never runs the Agent registration/approval flow). I then
wrote `OneClickInstallerPollIntegrationTest` to prove it, not just
argue it:

- registers a real `Server` the same way `servers.store` does, uses the
  exact plaintext credentials `buildOneClickInstallerContent()` would
  have embedded, and polls both endpoints with a properly HMAC-signed
  request (`Tests\Support\SignsAgentRequests`, the same algorithm
  `agent/lib/Signer.php` uses) — asserts `200`, `{"job": null}`, for
  both;
- repeats that with a real, active `Project` attached and zero
  deployments — the exact shape of the real "ParsVDS" server (one real
  project, no deployments) — same result.

Both pass. In this environment, this exact scenario does not 500. I
also double-checked the one thing that could plausibly differ between
this dev machine and any real deployment without being a "code bug" —
migrations — and confirmed the real, persistent `database.sqlite` this
whole project's "real environment" work has used throughout is fully
migrated (`migrate:status` shows every migration as `Ran`).

### 14.3 What I fixed anyway (Task 7)

Whatever the real root cause turns out to be on the actual Hub, an
uncaught exception there was returning a response shaped by
`APP_DEBUG`, not by design — Laravel's `shouldRenderJsonWhen()`
(already configured for all `api/*` routes) means it wasn't raw HTML,
but with `APP_DEBUG=true` it would include the real exception message,
file, and line in the JSON body. `bootstrap/app.php` now has one more
`render()` callback, scoped to `api/agent/*`, that replaces **any**
exception other than the two types Laravel already renders safely
and correctly (`HttpExceptionInterface` — 401/403/404/409/422 from an
`abort()`/`abort_if()` call, and `ValidationException` — 422 from
`$request->validate()`) with a fixed body:

```json
{"error": "agent_request_failed", "message": "The Deployment Service could not process this request. See the Hub application log for details."}
```

always at status 500. The real exception is still fully logged via
Laravel's normal `report()` path (unchanged) — only what's sent back
over the wire changes. `test_an_uncaught_exception_on_an_agent_route_
renders_safe_json_not_debug_output` proves this by forcing a real
`RuntimeException` (containing a secret-shaped string) through
`AgentOperationController::next()` and asserting the response is
exactly the fixed body above, with the injected string absent.

**A real regression this introduced, caught by the full suite, and
fixed:** the first version excluded only `HttpExceptionInterface`.
`ValidationException` (thrown by `$request->validate()`, e.g.
`heartbeat()`'s `agent_version` rule) does **not** implement that
interface, so it was also being caught and turned into a misleading 500
instead of the correct 422 — `PersianLocalizationTest::
test_api_agent_error_responses_stay_english_json_unaffected_by_
dashboard_locale` failed immediately, exactly as it should have.
Fixed by excluding `ValidationException` explicitly too, with a
dedicated regression test
(`test_a_validation_failure_on_an_agent_route_still_returns_422_not_500`).

### 14.4 Answers to the questions asked

- **Root cause of the HTTP 500:** not confirmed from here — this Hub
  instance's own log has no record of the incident request at all (see
  §14.1). The leading hypothesis is a stale/out-of-sync deployment on
  the real Hub; §14.1 gives the exact commands to check that on the
  real server.
- **File and line fixed:** `service/bootstrap/app.php` — added a safe
  -JSON exception render for `api/agent/*` (§14.3). This is a
  robustness improvement independent of the unconfirmed root cause, not
  a claimed fix for a reproduced bug.
- **Was the generated credential/config correct?** Yes — proven, not
  assumed: `OneClickInstallerPollIntegrationTest` polls both endpoints
  with the exact credentials the installer would have embedded (for a
  server shaped like the real one, project included) and gets `200`/
  `{"job": null}`, not `500`.
- **Expected successful poll response:** `HTTP 200`, body
  `{"job": null}` from both `/api/agent/jobs/next` and
  `/api/agent/operation-jobs/next` when nothing is queued for that
  server — this is what a healthy install should show on its first
  poll, and what the new integration test asserts.
- **To retest on the real server:** first run the three commands in
  §14.1 on the real Hub itself (latest code? migrations current? what
  does its own log say at the moment of the request?) — that will very
  likely name the actual root cause directly. Then re-download a fresh
  `install-agent.sh` from the panel (credentials rotate each time) and
  run the same two commands as before:
  ```
  chmod +x install-agent.sh
  sudo ./install-agent.sh
  ```
  If it still 500s after confirming the Hub is on the latest code and
  fully migrated, the safe JSON body from §14.3 will now show up in
  `storage/logs/laravel.log`'s corresponding `report()`ed exception
  with a real file/line/trace — please paste that back; that would be
  the actual root cause this session could not access.

### 14.5 Full Test Suite

**338/338 passing** (333 from Round 1 + 5 new:
`OneClickInstallerPollIntegrationTest`'s five tests).

## 15. Round 3: php-curl Detection False Negative Under `pipefail`

**Reported bug (found by inspection, not a live install this time):**
```bash
"$PHP_BIN" -m 2>/dev/null | grep -qi '^curl$'
```
run under this script's `set -euo pipefail` can incorrectly report
"curl not found" even when it's installed. `grep -q` exits the instant
it finds a match and closes its end of the pipe; if `php -m` is still
writing later module names at that exact moment, the kernel can deliver
it `SIGPIPE` (exit 141) on its next write. Under `pipefail`, that makes
the *pipeline's* reported status non-zero even though `grep` itself
matched successfully — so `if ! pipeline` took the wrong branch and the
installer would wrongly abort with "php-curl not installed," on a
server where it plainly is.

**Root cause confirmed with real bash, not just theory.** A fake `php`
that prints `curl` and then (after a short delay, to give `grep -q` a
real window to close early) a few more module names reliably
reproduces the false negative with the original pattern, and does not
with the fix — proven directly, not assumed:
```
$ bash old_harness.sh   # original: "$PHP_BIN" -m | grep -qi '^curl$'
NOT_FOUND        (exit 1 — wrong)
$ bash new_harness.sh   # fixed: capture first, then check
FOUND             (exit 0 — correct)
```

**The fix** — capture the slow, external, unpredictable-timing
command's output into a variable first (no reader at all while it
runs, so nothing can race it), then check the *already-captured*
string:
```bash
PHP_MODULES="$("$PHP_BIN" -m 2>/dev/null)"
if ! printf '%s\n' "$PHP_MODULES" | grep -qi '^curl$'; then
```
The same class of bug existed in two more places in the script and was
fixed the same way:
- `systemctl list-unit-files | grep -q "^${SERVICE_NAME}\.service"`
  (the fresh-install detection) → captured into `$EXISTING_UNIT_FILES`
  first.
- `echo "$VERIFY_OUTPUT" | grep -qE '\] (ERROR|WARN) '` (the
  verification-poll check) → `$VERIFY_OUTPUT` was already a captured
  variable, just switched `echo` to `printf '%s\n'` for consistency.

**Why `printf '%s\n' "$VAR" | grep -q` is safe where the original
wasn't:** the race is fundamentally about *time*, not size — a real
external process (`php`, `systemctl`) takes measurably longer to
start/flush than a shell builtin, giving `grep -q` a real window to
read, match, and close before the producer finishes. `printf` writing
an already-in-memory string that's realistically only a couple KB
(a module list, a unit-file list) completes fast enough, with no
competing computation, that there's no meaningful window left for
`grep` to win the race. (An early draft of the regression test tried
to force the same race by making the fake output several megabytes
instead of using a delay — that also broke the *fixed* pattern's own
`printf | grep` step, which is exactly why size isn't the right lever
here; a short `sleep` between a fake process's first line and the rest
reproduces the real bug deterministically without that side effect.)

**Regression test:** `test_php_curl_detection_does_not_false_negative_
under_pipefail` (`OneClickAgentInstallerTest.php`) extracts the real,
current detection block directly out of a freshly generated script (no
re-typed copy to drift from the source), runs it — and, for
comparison, the literal original buggy pattern — against the same fake
`php` under `set -euo pipefail` via real `bash` (`exec()`, not
simulated), and asserts: the old pattern reproduces the false negative
(proving the harness is meaningful) and the current one does not.

**Full test suite: 339/339 passing** (338 from Round 2 + this one new
test).

## 16. Round 4: Production HUB_URL Safety, PHP Order, Version Metadata

**Reported:** a real deployed Hub's installer embedded
`HUB_URL="http://127.0.0.1:8000"` instead of the operator's real
`https://panel.safhenokhost.ir`, plus a request to guarantee the panel
never generates a broken installer for production this way again, to
reorder PHP candidate detection, to embed a version marker, and to
explain why an old installer kept being downloaded.

### 16.1 Root cause of the wrong HUB_URL — confirmed, not guessed

`HUB_URL` was never hardcoded. It has always been built from Laravel's
own `url('/')` helper, which reflects **this Hub's own `APP_URL`
config** live, at generation time — verified directly:
`service/.env` on this machine has `APP_URL=http://localhost`, and
every generated sample script embeds exactly that. There is no second
code path, no cached value, no static file — `buildOneClickInstallerContent()`
is the only place `install-agent.sh` is ever produced (confirmed via a
repo-wide search for every other mention of `install-agent.sh` /
`oneClickInstaller`).

**This means the real deployed Hub's own `.env` has `APP_URL=http://
127.0.0.1:8000`** — almost certainly a leftover local/dev default that
was never changed to the real public URL before that Hub went live.
That is a configuration problem on the real server, not a code bug
here — but the code should never let a misconfigured production Hub
produce a broken installer silently, which is what Task 1 asks for and
what's now enforced (§16.2).

### 16.2 Fix — refuse to generate for a non-production-safe Hub URL

New `ServerController::installerHubUrlIsSafe(string $hubUrl): bool`,
checked in `show()` **before** calling `buildOneClickInstallerContent()`:

```php
private function installerHubUrlIsSafe(string $hubUrl): bool
{
    if (in_array(config('app.env'), ['local', 'development', 'testing'], true)) {
        return true;
    }

    $isHttps = str_starts_with($hubUrl, 'https://');
    $isLoopbackHost = (bool) preg_match(
        '#^https?://(localhost|127(?:\.\d{1,3}){3}|0\.0\.0\.0|\[::1\])(:\d+)?/?$#i',
        $hubUrl
    );

    return $isHttps && !$isLoopbackHost;
}
```

- **Local/dev/testing environment** (`config('app.env')`): unchanged
  behavior — a loopback URL is expected there (it's this Hub's own
  development machine), so generation proceeds and the *existing*
  bash-level `case "$HUB_URL" in ...` warning (unchanged) is what
  covers it, exactly matching Task 1's "the HTTP-local warning stays
  only for Development."
- **Any other environment**: generation is refused unless the URL is
  both HTTPS and not a loopback host. Instead of the download card,
  `servers/show.blade.php` now shows a clear, translated alert
  (`messages.errors.installer_hub_url_not_safe`) telling the operator
  exactly what's wrong and what to fix (`APP_URL` in that Hub's own
  `.env`) — the failure now surfaces on the panel, immediately, instead
  of silently shipping a script that can never reach its Hub.

`config('app.env')` was used instead of `app()->environment()`
deliberately — the latter is cached on the container at boot and
doesn't reflect a test overriding config at runtime, which would have
made this untestable without a full app reboot per case.

### 16.3 PHP Detection Order

```
- for candidate in php8.4 php8.3 php8.2 php8.1 php; do
+ for candidate in php php8.3 php8.4 php8.2 php8.1; do
```
Applied exactly as requested. The pipefail-safe capture-then-check
pattern around it (Round 3) is untouched and still verified by both the
existing dedicated regression test and a new one
(`test_installer_tries_php_candidates_in_the_correct_order`) that also
pins the new order itself.

### 16.4 Why An Old Installer Kept Being Downloaded — Audit

Checked every one of the five things asked:

1. **`buildOneClickInstallerContent()`** — computes the entire script
   as a plain PHP string on every call, from the server's live data and
   this Hub's live config. No memoization, no static property, nothing
   persisted between requests.
2. **Generate/Download path** — `ServerController::show()` calls it
   fresh every request (only gated by the one-time session flash, never
   skipped/reused across requests); the download itself is a
   client-side `Blob` from that exact response body — no separate URL
   that could serve something else.
3. **Cache** — no `Cache::` call anywhere in `ServerController.php` or
   near the installer. Ruled out directly, not assumed.
4. **Session/versioning** — the session only ever holds the one-time
   plaintext `generated_token`/`generated_secret` (present ⇒ build;
   absent ⇒ don't) — it holds no cached *content*, so there is nothing
   in session that could go stale.
5. **Anywhere else `install-agent.sh` is built** — a full-repo search
   found exactly one producer: `buildOneClickInstallerContent()`. No
   static file under `public/` or `storage/` matches
   `*install-agent*` either.

**Conclusion: there is no caching bug in this code.** Combined with
§14.1's earlier finding (this dev Hub's own log has zero record of the
real Ubuntu box's incident requests — the real Hub is a *separate*
deployment of this codebase), the far more likely explanation is
simply that **the real Hub is running an older deployed copy of this
code** that predates these fixes, and/or (if it *is* current source)
`opcache.validate_timestamps=0` is serving a stale compiled copy of
`ServerController.php` until PHP-FPM/the web server process is
restarted or `opcache_reset()` is called. Neither of those is
something this session can confirm or fix remotely — but §16.5's
version metadata turns "is this actually the latest build?" from a
guess into a one-line answer on the real server itself.

### 16.5 INSTALLER_VERSION Metadata

`ServerController::INSTALLER_VERSION` (currently
`'devops-hub-v1-ready-4'`) is embedded twice in every generated script
— a header comment (`# Installer version: ...`) and a shell variable
(`INSTALLER_VERSION="..."`) printed to the install log as the very
first `say()` line. Bump this constant whenever
`buildOneClickInstallerContent()`'s actual output changes in a way
worth telling apart. `test_installer_version_metadata_matches_the_
current_source_constant` reads the constant via reflection and asserts
the generated script contains it in both places — proving "the
installer you downloaded" and "what this exact codebase would build
right now" can never silently diverge without the test catching it.

### 16.6 Tests

Five new tests in `OneClickAgentInstallerTest.php` (19 total in that
file now), covering exactly what was asked:
- `test_installer_hub_url_matches_the_hubs_own_configured_root_url` —
  HUB_URL tracks a forced root URL (`https://panel.safhenokhost.ir`)
  exactly.
- `test_installer_is_not_generated_when_hub_url_is_loopback_in_production`
  — a loopback URL under `app.env=production` blocks generation and
  shows the translated reason instead.
- `test_installer_is_generated_when_hub_url_is_https_in_production` —
  a real HTTPS URL under `app.env=production` still generates normally
  (the guard doesn't over-fire).
- `test_installer_still_generates_with_loopback_url_in_local_environment`
  — today's local-dev behavior is unchanged.
- `test_installer_tries_php_candidates_in_the_correct_order` — pins the
  new order and re-confirms the pipefail-safe pattern around it.
- `test_installer_version_metadata_matches_the_current_source_constant`
  — §16.5.

**Full suite: `php artisan test` — 345/345 passing** (339 from Round 3
+ 6 new).

### 16.7 Final File

A sample `install-agent.sh` was generated against this fix (placeholder
credentials — see §16.8) and confirmed to contain every fix from all
four rounds: correct checksums (§6.1), the safe JSON error path (§14.3,
Hub-side, not in the script itself), the pipefail-safe checks (§15),
the new PHP order and HTTPS `HUB_URL` (§16.2–16.3), and the
`INSTALLER_VERSION` header (§16.5). Syntax-checked with real `bash -n`.

### 16.8 Important — What This Session Could Not Do

This script was generated with **placeholder** credentials
(`agt_SAMPLE_TOKEN_NOT_REAL` / `sec_SAMPLE_SECRET_NOT_REAL`) against a
`Server` object that only exists in memory for this generation call —
not a real row, and not the operator's real `parsvds`/production
server. It is for reviewing the fix, not for running against a real
server. The **real** `install-agent.sh`, with real one-time
credentials, can only ever come from clicking "دانلود نصب‌کننده
یک‌مرحله‌ای" on the real panel itself (after that Hub is redeployed
with this fix and its `.env`'s `APP_URL` is corrected) — that is a
structural property of how credentials are issued in this system
(§5), not something this session can generate on the operator's
behalf.
