# Phase D.1 — لایه Observability وضعیت Deployment (Runtime-Only) — گزارش پیاده‌سازی

این گزارش پیاده‌سازی واقعی (نه صرفاً Audit) لایه سبک observability روی state machine طراحی‌شده در
`docs/PHASE_D_DEPLOYMENT_STATE_MACHINE_AUDIT.md` را مستند می‌کند. مطابق محدودیت‌های صریح این فاز:
**هیچ Database/Migration، هیچ SaaS API، هیچ UI جدید، هیچ تغییر معماری، هیچ Deploy واقعی، هیچ SSH واقعی،
هیچ Production change و هیچ Migration انجام نشد.** همه‌چیز runtime-only (فقط در حافظه یک اجرای PowerShell)
است و با پایان یافتن فرآیند از بین می‌رود.

## 1. فایل‌های تغییر‌یافته

| فایل | نوع تغییر | شرح |
|---|---|---|
| `tools/simple-deployer/lib/SimpleDeploy.DeploymentState.ps1` | **جدید** | کل لایه observability: تولید Run ID، `RunState`، `BatchState`، ثبت رویداد، parser مارکرهای rollback، فرمت‌دهی گزارش زنده. |
| `tools/simple-deployer/lib/SimpleDeploy.Orchestration.ps1` | ویرایش | اتصال (wiring) رویدادها به `Invoke-ApplicationBuild`، `Invoke-ArtifactToTarget`، `Invoke-ApplicationDeployToTargets`، و `Invoke-ProjectDeploy`. |
| `tools/simple-deployer/scripts/activate-release.sh` | ویرایش | ۳ خط `[STATE-MARK]` صرفاً چاپی دور rollback خودکار موجود (بدون تغییر منطق تصمیم‌گیری). |
| `tools/simple-deployer/SimpleDeploy.ps1` | ویرایش | ثبت فایل جدید در زنجیره dot-source مسیر CLI. |
| `tools/simple-deployer/launcher/SimpleDeployLauncher.Engine.ps1` | ویرایش | ثبت فایل جدید در `$script:LauncherLibFiles` (مسیر GUI). |
| `tools/simple-deployer/tests/Test-Orchestration.ps1` | ویرایش | ثبت فایل جدید در dot-source این سوییت تست. |
| `tools/simple-deployer/tests/Test-LauncherGuiFlow.ps1` | ویرایش | ثبت فایل جدید در dot-source این سوییت تست. |
| `tools/simple-deployer/tests/Test-DeploymentState.ps1` | **جدید** | ۶ گروه تست (۳۷ assertion) برای این فاز. |
| `tools/simple-deployer/tests/Run-AllTests.ps1` | ویرایش | افزودن `Test-DeploymentState.ps1` به لیست سوییت‌ها. |

هیچ فایل دیگری (config.json، مدل‌های Registry، UI کنترل‌های جدید) لمس نشد.

## 2. جریان State (State Flow)

```
Invoke-ProjectDeploy / Invoke-ApplicationDeployToTargets   (نقطه ورود — هرکدام RunId/RunState خودشان را می‌سازند)
   │
   ├─ New-DeploymentRunId → New-DeploymentRunState → Add-DeploymentRunEvent(RUN_STARTED)
   │
   ├─ Invoke-ApplicationBuild(-RunState)
   │     PREFLIGHT_STARTED → PREFLIGHT_COMPLETED
   │     GIT_STARTED        → GIT_COMPLETED
   │     BUILD_STARTED      → BUILD_COMPLETED
   │     ARTIFACT_VALIDATION_STARTED → ARTIFACT_VALIDATION_COMPLETED
   │
   ├─ [فقط در Invoke-ApplicationDeployToTargets]
   │     New-DeploymentBatchState(Targets) → یک ورودی مستقل به‌ازای هر Target
   │
   ├─ به‌ازای هر Target → Invoke-ArtifactToTarget(-RunState)
   │     Set-DeploymentTargetState(...,'in_progress')
   │     TRANSFER_STARTED → TRANSFER_COMPLETED
   │     ACTIVATION_STARTED → [یک تماس SSH ترکیبی activate+healthcheck] → ACTIVATION_COMPLETED
   │     (اگر مارکر health_check دیده شود) HEALTHCHECK_STARTED → HEALTHCHECK_COMPLETED
   │     ConvertFrom-ActivationRollbackMarkers(StdOut) → RollbackInfo (فقط observational)
   │     Set-DeploymentTargetState(...,'completed'|'failed')
   │     Format-DeploymentLiveReportBlock  (چاپ زنده وضعیت از طریق Write-Step/Write-Info)
   │
   └─ Add-DeploymentRunEvent(RUN_COMPLETED یا RUN_FAILED)
```

نکتهٔ کلیدی: چون تماس SSH فعال‌سازی+health-check یک تماس ترکیبی واحد است (خروجی آن فقط بعد از پایان کامل
در دسترس است)، رویدادهای `ACTIVATION_COMPLETED`/`HEALTHCHECK_STARTED`/`HEALTHCHECK_COMPLETED` **پس از
بازگشت تماس، به‌صورت گذشته‌نگر (retrospective)** از روی مارکرهای `[TIMING-MARK]`/`[STATE-MARK]` موجود در
stdout ثبت می‌شوند — دقیقاً همان رویکردی که `Get-HealthCheckDurationFromActivationOutput` از قبل برای
تفکیک زمان‌بندی health-check استفاده می‌کرد.

## 3. لیست رویدادها (۱۷ مورد، ثابت via `ValidateSet`)

```
RUN_STARTED
PREFLIGHT_STARTED, PREFLIGHT_COMPLETED
GIT_STARTED, GIT_COMPLETED
BUILD_STARTED, BUILD_COMPLETED
ARTIFACT_VALIDATION_STARTED, ARTIFACT_VALIDATION_COMPLETED
TRANSFER_STARTED, TRANSFER_COMPLETED
ACTIVATION_STARTED, ACTIVATION_COMPLETED
HEALTHCHECK_STARTED, HEALTHCHECK_COMPLETED
RUN_COMPLETED, RUN_FAILED
```

هر رویداد شامل `EventName`، `Target` (اختیاری)، `Detail` (اختیاری) و `TimestampUtc` است. تلاش برای ثبت
یک نام رویداد خارج از این لیست توسط `ValidateSet` رد می‌شود (تست‌شده).

### مارکرهای `[STATE-MARK]` در `activate-release.sh` (دقیقاً ۳ نام)

```
ACTIVATION_ROLLBACK_STARTED    ← rollback به‌خاطر خطای مرحله فعال‌سازی (migrate/cache/queue/...)
HEALTHCHECK_ROLLBACK_STARTED   ← rollback به‌خاطر شکست health check
ROLLBACK_COMPLETED             ← بعد از پایان perform_rollback، همیشه صادر می‌شود
```

پیاده‌سازی: یک متغیر پرچم `health_check_started` (مقداردهی `0`، و `1` درست پیش از شروع بلوک health-check —
دقیقاً هم‌ردیف با `[TIMING-MARK] health_check_start_ms=...` موجود) تعیین می‌کند کدام یک از دو برچسب اول در
`post_swap_failure()` چاپ شود. **این تنها تغییری است که به `post_swap_failure`/`perform_rollback` اضافه شد
و صرفاً چاپی است** — نه ترتیب اجرا، نه شرط‌های `trap`/`fail()`/`exit`، و نه رفتار خود rollback تغییر کرده‌اند؛
`perform_rollback()` بدون تغییر باقی مانده است. (شفاف‌سازی صادقانه: افزودن متغیر پرچم و شرط `if` انتخاب برچسب،
فنی‌ترین معنا، «فقط چاپ» نیست چون یک `if` اضافه شده — اما این `if` هیچ تأثیری بر مسیر اجرا یا پیامد rollback
ندارد؛ فقط تعیین می‌کند کدام رشتهٔ متنی چاپ شود.)

## 4. تست‌ها

`tests/Test-DeploymentState.ps1` — **۳۷ assertion، ۰ Fail**:

1. **تولید Run ID**: تطابق دقیق با الگوی `DEP-YYYYMMDD-HHMMSS-XXXX`؛ ۲۰۰ تولید پیاپی بدون برخورد؛ صحت جاسازی تاریخ/زمان دقیق.
2. **ترتیب رویدادها**: `Add-DeploymentRunEvent` رویدادها را به همان ترتیب فراخوانی ثبت می‌کند، `CurrentStage` همیشه آخرین رویداد است، `Detail`/`TimestampUtc` حفظ می‌شوند، نام رویداد نامعتبر رد می‌شود.
3. **Parser مارکرهای Rollback**: حالت بدون مارکر، حالت rollback فاز فعال‌سازی، حالت rollback فاز health-check، حالت StdOut خالی — هرکدام روی متن واقعی خروجی `log()` اسکریپت بش تست شده‌اند.
4. **ایزوله‌بودن وضعیت چند-Target**: تنظیم وضعیت یک Target هرگز روی Targetهای دیگر در همان Batch اثر نمی‌گذارد (حتی وقتی Targetی خارج از لیست اولیه اضافه می‌شود).
5. **انتشار شکست (End-to-End واقعی)**: یک اجرای واقعی `Invoke-ApplicationDeployToTargets` با یک ریپوی Git واقعی + یک remote bare محلی واقعی (push واقعی موفق) و دو Target غیرقابل‌دسترس عمدی (`127.0.0.1` روی پورت‌های بسته — connection refused سریع، نه DNS) ثابت می‌کند: هر دو Target امتحان می‌شوند (شکست یکی مانع دیگری نمی‌شود)، `RUN_FAILED` آخرین رویداد است، `BatchState` هر دو را «failed» با `Detail` مستقل ثبت می‌کند، و workspace موقت build پس از پایان کامل پاک می‌شود.
6. **رگرسیون کامل**: کل مجموعهٔ تست‌های موجود پروژه.

نتیجهٔ اجرای `tests/Run-AllTests.ps1` پس از افزودن این فاز:

```
TOTAL: 482 passed, 0 failed (across 14 suites).
```

(شامل ۳۷ تست جدید این فاز + همهٔ ۴۴۵ تست قبلی، بدون هیچ Regression.)

### باگ واقعی کشف و رفع‌شده حین نوشتن تست‌ها

هنگام نوشتن Test 5، یک باگ واقعی در کد جدید کشف شد: خط
`$anyFailed = @($targetResults) | Where-Object {...} | Select-Object -First 1`
در PowerShell 5.1 با `Set-StrictMode -Version Latest`، وقتی `$targetResults` یک
`List[object]` حاوی Hashtable باشد، استثنای واقعی `System.ArgumentException: Argument types do not match`
پرتاب می‌کند (تکرارپذیر و تأییدشده مستقل از این تست). رفع: حذف `@()` اضافه دور `$targetResults` قبل از
pipe (یعنی `$targetResults | Where-Object ... | Select-Object -First 1` — چون `List[object]` از قبل
enumerable است، نیازی به این wrap نبود). این تنها تغییر رفتاری غیر-observability در این فاز است و صرفاً
یک رفع باگ روی کدی است که در همین فاز اضافه شد (نه کد پیش‌از‌این‌فاز).

## 5. Backward Compatibility

- همهٔ پارامترهای جدید (`-RunState`) با default مقدار `$null` اضافه شدند و دقیقاً از همان الگوی
  `-TimingReport = $null` موجود پیروی می‌کنند — هر تماس‌گیرنده‌ای که این پارامتر را ندهد، رفتارش
  کاملاً بدون تغییر است.
- `Invoke-ProjectDeploy` (نقطهٔ ورود Legacy با قرارداد صریح «EXACT same return shape») **shape خروجی‌اش
  تغییر نکرد** — `RunState` فقط داخلی مصرف می‌شود و به‌صورت console output (از طریق `Format-
  DeploymentLiveReportBlock`) دیده می‌شود، نه از طریق hashtable برگشتی.
- `Invoke-ApplicationDeployToTargets` (که چنین قرارداد صریحی ندارد) دو کلید جدید و افزایشی
  `RunState`/`BatchState` به hashtable برگشتی خود اضافه کرد — این تابع در حال حاضر هیچ Caller دیگری
  به‌جز تست‌ها ندارد (هنوز به GUI/CLI وصل نشده)، پس ریسک شکستن مصرف‌کنندهٔ موجود صفر است.
- `Invoke-ArtifactToTarget` یک کلید افزایشی `RollbackInfo` به hashtable برگشتی خود (هم مسیر موفق و هم Failed) اضافه کرد.

## 6. شکاف‌های باقی‌مانده (Remaining Gaps)

این فاز عمداً محدود بود؛ موارد زیر همچنان **باز** هستند و نیاز به فازهای بعدی (طبق طراحی Phase D خود سند Audit) دارند:

1. **بدون Persistence**: با پایان فرآیند PowerShell، تمام `RunState`/`BatchState` از بین می‌رود — هیچ
   تاریخچهٔ Deploy‌ای بین اجراها باقی نمی‌ماند (دقیقاً طبق محدودیت این فاز).
2. **بدون UI جدید**: گزارش زنده فقط از طریق کانال متنی موجود (`Write-Step`/`Write-Info` →
   همان جعبهٔ «گزارش زنده» GUI) نمایش داده می‌شود؛ هیچ نمای گرافیکی اختصاصی (progress bar، جدول
   وضعیت هر Target) اضافه نشد.
3. **بدون Resume**: اگر فرآیند در میانهٔ یک Deploy قطع شود، هیچ RunId/RunState‌ی برای ازسرگیری وجود ندارد.
4. **`Invoke-ApplicationDeployToTargets` هنوز به هیچ Caller واقعی (CLI/GUI) وصل نیست** — این محدودیت
   از قبل از این فاز وجود داشت و در این فاز تغییر نکرد؛ مسیر واقعی Deploy امروز هنوز از
   `Invoke-ProjectDeploy` (تک-Target) عبور می‌کند.
5. **دقت زمان‌بندی رویدادهای Health-check گذشته‌نگر است**: چون این رویدادها بعد از بازگشت یک تماس SSH
   ترکیبی ثبت می‌شوند، `TimestampUtc` آن‌ها زمان *پردازش خروجی روی Windows* است، نه زمان واقعی وقوع روی
   سرور — همان محدودیتی که `[TIMING-MARK]` از قبل داشت.
6. **تعمیم به `Invoke-ProjectDeploy` فراتر از خواستهٔ کلمه‌به‌کلمهٔ Task 2 بود**: کاربر ۳ نقطهٔ اتصال را
   نام برده بود (`Invoke-ApplicationBuild` / `Invoke-ArtifactToTarget` / `Invoke-ApplicationDeployToTargets`).
   من علاوه‌بر این‌ها، `Invoke-ProjectDeploy` را هم سیم‌کشی کردم چون این تابع، نقطهٔ ورود واقعی CLI/GUI
   امروز است و بدون آن، هیچ Deploy واقعی هرگز Run ID یا گزارش زنده دریافت نمی‌کرد. این یک تصمیم من بود، نه
   تأییدشده صریح توسط کاربر — در صورت نیاز به‌سادگی قابل rollback است (حذف بلوک اضافه‌شده در ابتدای آن تابع).
