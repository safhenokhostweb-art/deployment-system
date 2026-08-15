# Phase D — Deployment State Machine + Deployment History (Audit)

> **وضعیت این سند**: فقط Audit و Design. هیچ Migration، Model، UI یا Implementation در این فاز ساخته نشد. هیچ Deploy واقعی، SSH واقعی، یا Commit/Push انجام نشد.

---

## 1) Current Architecture

Pipeline واقعی امروز (تأییدشده از روی کد، نه از حافظه) یک قدم فراتر از فهرست فرضی شما است — یک Stage اضافه («Artifact Validation») از قبل واقعاً وجود دارد:

```
Preflight
    ↓
Git Status
    ↓
Commit
    ↓
Push
    ↓
Build Artifact (git archive + composer/npm ایزوله)
    ↓
Artifact Validation   ← از قبل پیاده‌سازی شده (lib/SimpleDeploy.ArtifactValidation.ps1)
    ↓
Packaging (zip + sha256)
    ↓
SCP Transfer (اتمیک: .part → verify size/checksum → rename)   ← اخیراً سخت‌تر شد
    ↓
SSH Activation (extract → swap → migrate → health check)   ← شامل Rollback خودکار سمت سرور
    ↓
Completed
```

فایل‌های واقعی درگیر (نام دقیق، نه فرضی):

| نام فرضی در درخواست | فایل واقعی |
|---|---|
| `SimpleDeploy.Orchestration.ps1` | همین نام، در `lib/` — Orchestrator اصلی |
| `SimpleDeploy.Transfer.ps1` | همین نام، در `lib/` — SCP/SSH |
| `SimpleDeploy.Activate.ps1` | **وجود ندارد.** فعال‌سازی یک اسکریپت Bash است: `scripts/activate-release.sh`، که از طریق SSH روی سرور اجرا می‌شود، نه یک فایل PowerShell محلی |
| `SimpleDeploy.Status.ps1` | **این نام دقیق در `lib/` وجود ندارد.** معادل آن `launcher/SimpleDeployLauncher.Status.ps1` است — فقط یک Catalog نمایشی، نه یک State Machine واقعی (بخش ۳) |
| Launcher Status Handling | `launcher/SimpleDeployLauncher.Gui.ps1` (به‌روزرسانی وضعیت هر ردیف) + `launcher/SimpleDeployLauncher.Log.ps1` (نوشتن فایل نتیجه نهایی) |

**دو مسیر اجرای کاملاً مستقل امروز وجود دارد**، هر دو از همان `lib/SimpleDeploy.Orchestration.ps1` استفاده می‌کنند اما Tracking‌شان متفاوت است:
- **CLI** (`SimpleDeploy.ps1`) — **هیچ Tracking یا History‌ای ندارد.** حتی `SimpleDeployLauncher.Log.ps1` را هم dot-source نمی‌کند.
- **Launcher (GUI)** — تنها مسیری که چیزی را (ناقص) ثبت می‌کند؛ پایین توضیح داده شده.

---

## 2) Existing Capabilities (چه چیزی از قبل واقعاً کار می‌کند)

این‌ها **قابلیت‌های واقعی و تأییدشده** هستند — نباید در طراحی جدید نادیده گرفته یا دوباره‌سازی شوند:

1. **Performance Timing Layer** (`lib/SimpleDeploy.Timing.ps1`) — هر Stage (شامل Artifact Validation) با Stopwatch واقعی زمان‌بندی می‌شود؛ `Write-DeployStageStartMarker`/`Add-DeployStageTiming` از قبل نشانگرهای START/END را در یک لاگ داخلی (`state/launcher-debug.log`) هم ثبت می‌کنند — این دقیقاً معادل نیمی از یک `DeploymentStageLog` است، فقط **در حافظه و فایل متنی، نه در یک Model رسمی**.
2. **Rollback خودکار سمت سرور از قبل وجود دارد و کار می‌کند** (`scripts/activate-release.sh`) — یافته‌ی مهم بخش ۶.
3. **Checksum verification دوباره** (هم سمت Windows بعد از SCP، هم سمت سرور قبل از extract) — از کار اخیر SCP.
4. **یک رکورد نهایی JSON در Launcher** (`logs/YYYY-MM-DD/<key>-<HHmmss>-<Outcome>.json` از طریق `New-LauncherRunLog`/`Complete-LauncherRunLog`) — شامل `project_key, started_at, ended_at, commit_sha, outcome, error_text` — اما (بخش ۳) **بدون جزئیات Stage**.
5. **Redaction خودکار Secret** در همان لاگ (`Protect-LauncherLogText`) — قبل از هر `Write` واقعاً اعمال می‌شود؛ الگوی امنیتی که باید دقیقاً برای Model جدید هم تکرار شود.
6. **`Invoke-ApplicationDeployToTargets`** (از پیاده‌سازی Fan-out قبلی) از قبل یک نتیجه‌ی in-memory جدا به‌ازای هر Target برمی‌گرداند (`TargetResults` array) — پایه‌ی خوبی برای `DeploymentBatch`، فقط **هیچ‌چیزش persist نمی‌شود**.

---

## 3) Missing Components (شکاف‌های واقعی، تأییدشده از روی کد)

1. **هیچ Run ID/UUID رسمی وجود ندارد.** تنها چیز نزدیک به آن `$jobId = "<timestamp>-<sha12>"` است که فقط برای نام‌گذاری پوشه‌ی موقت/فایل remote استفاده می‌شود، هرگز persist یا در لاگ نهایی ذخیره نمی‌شود.
2. **هیچ Resume واقعی وجود ندارد.** کل Pipeline یک فراخوانی همزمان PowerShell است؛ اگر پردازش/کامپیوتر میان‌راه از کار بیفتد، هیچ حالت میانی ذخیره نشده — باید از Preflight دوباره شروع شود.
3. **جزئیات Stage-level در عمل ثبت نمی‌شود.** `Add-LauncherRunLogEvent`/`Set-LauncherRunLogResult` (که دقیقاً برای همین کار طراحی شده‌اند) **در مسیر واقعی Gui.ps1 هرگز فراخوانی نمی‌شوند** — فقط در یک تست مستقیماً صدا زده می‌شوند. یعنی `Events`/`Results` در فایل JSON نهایی همیشه خالی/null هستند؛ فقط outcome نهایی و یک متن خطای آزاد ذخیره می‌شود.
4. **CLI هیچ History‌ای ندارد** — صفر ردپا بعد از یک اجرای `SimpleDeploy.ps1 -All`.
5. **هیچ چیزی به‌ازای هر Target ذخیره نمی‌شود.** نتیجه‌ی `Invoke-ApplicationDeployToTargets` فقط در متغیر PowerShell زنده است؛ به‌محض پایان تابع از بین می‌رود.
6. **Rollback سمت سرور «کور» است از دید Windows.** اسکریپت واقعاً rollback می‌کند (بخش ۶)، ولی این را به‌صورت ساخت‌یافته اعلام نمی‌کند — فقط متن آزاد در stdout. سمت PowerShell فقط `ExitCode≠0` می‌بیند، نمی‌داند «شکست خورد و امن rollback شد» را از «شکست فاجعه‌بار» تشخیص دهد.
7. **Status Catalog فعلی تک‌مقداری و ephemeral است** — یک Label رشته‌ای روی هر ردیف UI، بدون قانون انتقال معتبر (State Machine واقعی نیست)، بدون مفهوم چند-Target.

---

## 4) Proposed Database Design

**تصمیم پیش‌نیاز**: کجا ذخیره شود؟ SQLite محلی (فایل `state/deployments.db`) پیشنهاد می‌شود، نه یک دیتابیس سرور — چون:
- کل ابزار همین الان بدون هیچ DB Engine کار می‌کند (فقط JSON/متن).
- یک وابستگی سنگین جدید (MySQL/PostgreSQL محلی) بر خلاف فلسفه‌ی «ابزار مستقل و سبک» این پروژه است.
- SQLite با PowerShell 5.1 از طریق `System.Data.SQLite` یا حتی فقط فایل‌های JSON ساختاریافته (یک فایل به‌ازای هر Run، مثل امروز، ولی کامل‌تر) قابل استفاده است — **این تصمیم باید در Task بعدی و با تأیید صریح شما گرفته شود**، اینجا فقط دو گزینه مطرح می‌شود، نه انتخاب نهایی.

### DeploymentRun

```
id                  (PK, auto)
deployment_id       (UUID, یکتا، تولید در شروع هر Run — همان چیزی که امروز نیست)
application_key     (مطابق ApplicationProfile.ApplicationKey از Task 4A)
target_key          (nullable برای Run تک‌هدف قدیمی؛ برای Batch به DeploymentBatchTarget اشاره می‌کند)
commit_sha
artifact_hash       (از قبل موجود: Artifact.ChecksumSha256)
status              (بخش ۵)
started_at
completed_at
duration_ms
triggered_by        ('cli' | 'launcher-gui' | آینده: 'saas-registry-batch')
failure_reason      (متن آزاد، Redact شده — دقیقاً با همان Protect-LauncherLogText موجود)
```

فیلدهای شما کافی هستند؛ فقط `triggered_by` باید یک enum محدود باشد (نه رشته‌ی آزاد) تا بعداً قابل فیلتر باشد.

### DeploymentStageLog

```
id                  (PK)
deployment_run_id   (FK → DeploymentRun.id)
stage_name          (باید دقیقاً هم‌نام Stageهای واقعی Timing Layer باشد: Preflight, Git status, Commit, Push,
                      Build artifact, Composer install, npm build, Artifact validation, Artifact packaging,
                      SCP transfer, SSH activation, Health check — نه یک لیست تازه‌اختراع)
status              (بخش ۵)
started_at
completed_at
duration_ms         (از همان Stopwatch که Timing Layer از قبل محاسبه می‌کند — فقط باید persist شود)
output_summary      (Redact شده)
error_summary        (Redact شده)
```

نکته‌ی مهم طراحی: چون `Add-DeployStageTiming`/`Write-DeployStageStartMarker` **از قبل** در تمام Stageهای بالا صدا زده می‌شوند، پیاده‌سازی واقعی `DeploymentStageLog` می‌تواند با افزودن یک پارامتر اختیاری (`-DeploymentRunId`) به همین دو تابع موجود انجام شود — نه بازنویسی Orchestration.ps1.

---

## 5) State Machine Diagram

فهرست پیشنهادی شما تقریباً کامل است؛ سه اصلاح لازم دارد چون با Stageهای **واقعی** هم‌تراز نبود:

```
created
   ↓
preflight_running → failed_preflight
   ↓ (passed)
git_running → failed_git
   ↓
artifact_building → failed_build
   ↓
artifact_validating → failed_validation        ← اضافه شد (Stage واقعی، در فهرست شما نبود)
   ↓
artifact_ready
   ↓
transferring → failed_transfer
   ↓
transfer_completed
   ↓
activating → failed_activation
   ↓                    ↳ rolled_back            ← اضافه شد (بخش ۶ — این حالت واقعاً رخ می‌دهد)
health_checking → failed_healthcheck
   ↓                    ↳ rolled_back            ← اضافه شد (همینطور)
completed
```

**اصلاح مهم**: `failed_activation` و `failed_healthcheck` نباید حالت‌های نهایی (terminal) در نظر گرفته شوند — چون در عمل، اسکریپت سرور خودش تا حد زیادی rollback را انجام داده. حالت نهایی واقعی یکی از این دو است:
- `rolled_back` — سرور با موفقیت به نسخه‌ی قبلی برگشت (بیشتر موارد واقعی).
- `failed_activation`/`failed_healthcheck` **بدون** rollback موفق — فقط وقتی خودِ عملیات rollback هم شکست بخورد (نادر، ولی باید جدا دیده شود چون خطرناک‌ترین حالت است: سرور در وضعیت نامعلوم رها شده).

جدول کامل State ↔ Stage واقعی:

| State | Stage واقعی متناظر |
|---|---|
| `created` | قبل از هر چیز |
| `preflight_running` / `failed_preflight` | Preflight |
| `git_running` / `failed_git` | Git status → Commit → Push |
| `artifact_building` / `failed_build` | Build artifact (+ Composer/npm) |
| `artifact_validating` / `failed_validation` | Artifact validation |
| `artifact_ready` | Artifact packaging کامل شد |
| `transferring` / `failed_transfer` | SCP transfer |
| `transfer_completed` | بعد از verify حجم+checksum، قبل از فعال‌سازی |
| `activating` / `failed_activation` / `rolled_back` | SSH activation (تا لحظه‌ی قبل از health check) |
| `health_checking` / `failed_healthcheck` / `rolled_back` | Health check |
| `completed` | موفقیت کامل |

---

## 6) Rollback Strategy

### یافته‌ی کلیدی این Audit

**Rollback خودکار از قبل، سمت سرور، در `activate-release.sh` پیاده‌سازی و تست شده است** — این باید در طراحی هر چیز جدید به‌عنوان یک واقعیت موجود در نظر گرفته شود، نه چیزی که باید از صفر ساخته شود:

- **سناریوی «SCP موفق ولی Activation شکست خورد»**: چون checksum هم سمت Windows (کار اخیر) و هم دوباره در ابتدای خودِ `activate-release.sh` (خط ۸۵-۹۰) بررسی می‌شود، و Swap واقعی (`perform_swap`) فقط **بعد از** این بررسی‌ها اتفاق می‌افتد — اگر Activation قبل از Swap شکست بخورد (checksum/ZIP-Slip/extract)، دایرکتوری Live اصلاً لمس نشده — نیازی به rollback نیست. اگر **بعد از** Swap شکست بخورد (migrate/cache/queue)، یک `trap post_swap_failure ERR` واقعی و خودکار فعال می‌شود که Swap را معکوس می‌کند.
- **سناریوی «Activation موفق ولی Health Check شکست خورد»**: یک باگ واقعی در همین مکانیزم قبلاً پیدا و رفع شده بود (کامنت خط ۲۹۱-۳۰۱ خودِ فایل: تابع `fail()` از `exit` استفاده می‌کند که trap ERR را فعال نمی‌کند؛ رفع شد با صدا زدن مستقیم `post_swap_failure`). یعنی **Health Check شکست‌خورده هم امروز واقعاً rollback خودکار می‌شود**، نه رها شدن در حالت شکسته.

### شکاف واقعی (نه چیزی که باید از صفر ساخته شود)

سمت Windows/PowerShell **از این rollback خودکار خبر ندارد** — فقط `ExitCode≠0` و یک بلوک متن آزاد stdout می‌بیند. برای این‌که State Machine بتواند تفاوت `failed_activation` (رها شده در حالت شکسته) و `rolled_back` (امن برگشته) را نشان دهد، لازم است:

**پیشنهاد کم‌ریسک** (هم‌راستا با الگوی `[TIMING-MARK]` که از قبل اثبات‌شده است): افزودن یک خط چاپی مشابه در انتهای `perform_rollback()`:
```bash
log "[STATE-MARK] rollback_performed=1"
```
و در انتهای مسیر موفقیت (بعد از `trap - ERR`):
```bash
log "[STATE-MARK] rollback_performed=0"
```
سمت PowerShell (`Get-HealthCheckDurationFromActivationOutput`ای مشابه) این را از `$activateResult.StdOut` می‌خواند — **دقیقاً همان الگوی خودِ Timing Layer فعلی**، بدون تغییر معماری، بدون افزودن هیچ ارتباط جدید.

### پیشنهاد نهایی: Automatic (سمت سرور) + Manual Recovery (سمت اپراتور)، نه Automatic Rollback جدید

Rollback خودکار همین الان درست کار می‌کند و نباید دوباره‌سازی شود. آنچه لازم است فقط **گزارش‌دهی ساخت‌یافته‌ی همان چیزی که از قبل اتفاق می‌افتد** است، به‌علاوه یک مسیر Manual Recovery برای حالت نادر «rollback خودش هم شکست خورد» (که باید فوراً و به‌وضوح به اپراتور اعلام شود، نه در لاگ گم شود).

---

## 7) Multi Target Strategy

### آیا `DeploymentRun` (Parent) + Target Runs لازم است؟

**بله.** معماری فعلی (`Invoke-ApplicationDeployToTargets`) از قبل دقیقاً همین تفکیک را در حافظه دارد: یک `BuildResult` (مشترک) + آرایه‌ی `TargetResults` (مستقل به‌ازای هر Target). این باید مستقیماً به دو جدول نگاشت شود، نه یک طراحی جدید:

```
DeploymentBatch
   id
   batch_id (UUID)
   application_key
   commit_sha
   artifact_hash        ← یکی، مشترک بین همه Target — چون Build فقط یک‌بار انجام می‌شود
   status                (created | building | fanning_out | completed | partially_failed)
   started_at / completed_at
   total_targets / succeeded_count / failed_count

DeploymentRun   (یک ردیف به‌ازای هر Target)
   ...همان فیلدهای بخش ۴...
   batch_id (FK, nullable — تک‌هدف قدیمی همچنان بدون Batch کار می‌کند)
```

این دقیقاً مثال خودتان است (`customer-001/002/003`) — با این تفاوت که `artifact_hash` باید روی خودِ `DeploymentBatch` باشد (نه تکرار در هر Target Run)، چون Build واقعاً فقط یک‌بار اتفاق می‌افتد؛ تکرارش در هر ردیف Target داده‌ی افزونه (و بالقوه ناسازگار) تولید می‌کند.

**نکته‌ی طراحی مهم**: چون Fan-out فعلی **متوالی (Sequential)** است (تصمیم قبلی شما)، `DeploymentBatch.status` باید بتواند `partially_failed` را نشان دهد بدون این‌که کل Batch را «Failed» بنامد — دقیقاً همان سیاست «شکست یک Target مانع بقیه نمی‌شود» که از قبل در کد پیاده‌سازی شده.

---

## 8) GUI (فقط بررسی، بدون Implementation)

بله، Launcher باید این‌ها را نشان دهد — همه‌شان از داده‌ای که در بخش ۴/۷ طراحی شد به‌صورت مستقیم قابل استخراج‌اند، بدون منبع داده‌ی جدید:

- **Current Stage** — از `DeploymentStageLog` آخرین ردیف بدون `completed_at`.
- **Current Target** — از `DeploymentRun` های یک `DeploymentBatch` که `status` آن‌ها هنوز terminal نیست.
- **Elapsed Time** — از `started_at` همان Run/Batch (Timing Layer همین الان این عدد را دارد، فقط باید نمایش زنده‌ی آن به Batch وصل شود).
- **Completed Targets / Failed Targets** — شمارش مستقیم روی `DeploymentRun.status` درون یک `DeploymentBatch`.

هیچ‌کدام نیازمند مکانیزم جدید نیست — همه‌شان View‌هایی روی همان دو جدول پیشنهادی بخش ۴/۷ هستند.

---

## 9) SaaS Registry آینده — آیا معماری فعلی آماده است؟

**بله، با یک شرط.** زنجیره‌ی `Registry API → Launcher Cache → DeploymentBatch → Target Deployment Runs` دقیقاً با طراحی بخش ۷ هم‌راستا است — یک `DeploymentBatch` می‌تواند از یک Registry Sync (به‌جای انتخاب دستی Target) تغذیه شود بدون تغییر در ساختار جدول.

**شرط**: طبق Audit قبلی (Task 5)، خودِ `application_key` امروز در saas-platform وجود ندارد (فقط برای `customer-site` ثابت فرض شده). یعنی `DeploymentBatch.application_key` برای ۱۰۰ سایت مشتری همیشه یک مقدار ثابت خواهد بود تا وقتی چند-محصولی واقعی در saas-platform ساخته شود — این یک وابستگی شناخته‌شده است، نه یک مانع برای طراحی همین Phase.

---

## 10) Security

- **Secret در History**: با همان الگوی موجود (`Protect-LauncherLogText`) روی هر فیلد متنی (`output_summary`, `error_summary`, `failure_reason`) اعمال شود — این تابع از قبل هست و تست شده (PEM key blocks + KEY=VALUE credential-looking lines)؛ فقط باید محل فراخوانی آن از «فقط لاگ متنی» به «قبل از هر INSERT در DeploymentStageLog» گسترش یابد.
- **خروجی SSH**: `$activateResult.StdOut`/`StdErr` همین الان قبل از رسیدن به UI از فیلتر Redaction نمی‌گذرد در همه مسیرها — این باید صریحاً برای `output_summary`/`error_summary` اعمال شود، حتی اگر امروز فقط در پنل زنده (نه در یک DB) دیده می‌شود.
- **Error message**: پیام‌های فعلی (`"فعال‌سازی روی سرور با خطا مواجه شد (exit ...): $StdErr"`) خامِ StdErr واقعی را داخل خودشان دارند — یعنی هر Secret ناخواسته در stderr سرور (که با معماری فعلی بعید ولی ممکن است) می‌تواند بدون فیلتر به `failure_reason` برسد. همان Redaction باید **قبل از** ساخت این پیام هم اعمال شود، نه فقط در لایه‌ی لاگ‌نویسی جدا.

---

## 11) Testing Plan (فقط طراحی)

| تست | روش پیشنهادی (بدون VPS/Deploy واقعی) |
|---|---|
| State transition | Unit — فراخوانی مستقیم توابع انتقال State با fixture داده، بدون هیچ عملیات واقعی |
| Failed transfer state | استفاده مجدد از fixture موجود Test-Transfer.ps1 (Target غیرقابل‌دسترس واقعی، محلی) |
| Failed activation state | Fixture محلی Bash (Test-ActivateRelease.ps1 از قبل این الگو را دارد) |
| Health check failure | همان الگو + `--health-check-url` اشاره به یک پورت بسته محلی |
| Multi target partial failure | دقیقاً همان الگوی تست‌های `Invoke-ApplicationDeployToTargets` موجود (یک Target موفق/شبیه‌سازی‌شده، یک Target واقعاً غیرقابل‌دسترس) |
| Resume after crash | نیازمند طراحی صریح «چه چیزی persist می‌شود قبل از هر Stage» — این تنها موردی است که **زیرساخت امروز آماده‌اش نیست** (بخش ۳، مورد ۲) و باید در فاز پیاده‌سازی جداگانه طراحی شود |

---

## 12) Implementation Order (پیشنهادی)

1. افزودن `deployment_id` (UUID) به همان مسیر موجود (`New-LauncherRunLog`) — بدون جدول جدید، فقط یک فیلد.
2. واقعاً وصل کردن `Add-LauncherRunLogEvent`/`Set-LauncherRunLogResult` (که از قبل وجود دارند و تست شده‌اند ولی استفاده نمی‌شوند) به هر Stage واقعی.
3. افزودن `[STATE-MARK] rollback_performed=` به `activate-release.sh` (بخش ۶) — کم‌ریسک‌ترین تغییر سمت سرور.
4. تصمیم SQLite در برابر JSON ساختاریافته (نیازمند تأیید شما).
5. `DeploymentRun`/`DeploymentStageLog` واقعی (فقط بعد از ۴).
6. `DeploymentBatch` برای Multi-Target (روی زیرساخت `Invoke-ApplicationDeployToTargets` موجود).
7. GUI Read-only Views (بخش ۸).
8. Resume-after-crash (پیچیده‌ترین مورد — آخر).

---

## 13) Risk Analysis

- **بزرگ‌ترین ریسک**: اگر Persist کردن State باعث کندی/شکنندگی مسیر اصلی Deploy شود (مثلاً یک DB Write که شکست بخورد نباید کل Deploy را متوقف کند) — هر نوشتن State باید best-effort و غیرمسدودکننده باشد، دقیقاً مثل `Write-LauncherDebugLog` فعلی که خودش را در `try/catch` خالی می‌پیچد.
- **ریسک دوم**: افزودن SQLite یک وابستگی/فایل باینری جدید به یک ابزار همین الان بدون DB است — نیازمند تصمیم صریح شما، نه انتخاب یک‌طرفه.
- **ریسک سوم**: تغییر `activate-release.sh` (حتی فقط افزودن یک خط چاپی) روی مسیر Production فعال — باید دقیقاً مثل تغییرات TIMING-MARK قبلی، فقط print-only و کاملاً additive باشد، هرگز منطق rollback موجود را لمس نکند.
- **ریسک کم**: بخش‌های ۱، ۲، ۷ (GUI Views) چون فقط روی داده‌ی موجود/طراحی‌شده کار می‌کنند، نه تغییر منطق Deploy.

---

*هیچ فایل کد، Migration، یا Model در این Audit تغییر یا ساخته نشد. آماده‌ی بررسی و تأیید شما برای شروع Task بعدی (طبق ترتیب بخش ۱۲).*
