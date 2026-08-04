# SimpleDeploy — ابزار مستقل Deploy (جایگزین موقت)

## این ابزار چیست؟

یک ابزار مستقل و ساده برای ویندوز که **فعلاً** مسیر عملیاتی سرویس
Deployment فعلی (Hub / Local Agent / Server Agent) را برای Deploy دستی
جایگزین می‌کند: تغییرات پروژه‌های فعال لوکال را شناسایی، commit/push،
build ایزوله، و مستقیماً از طریق SSH/SCP روی VPS فعال می‌کند — **بدون
هیچ وابستگی به Deployment Hub، Local Agent یا Server Agent**.

**سرویس Deployment فعلی حذف یا بازطراحی نشده است.** بعداً به آن برمی‌گردیم.
این ابزار فقط، در صورت لزوم، آن را برای مدت اجرای خودش موقتاً متوقف
می‌کند تا هیچ Job/Watch/Publish/Deploy موازی‌ای پیش نیاید، و در پایان
دقیقاً به وضعیت قبلی بازش می‌گرداند.

## معماری

```
SimpleDeploy.ps1  (orchestrator اصلی)
  │
  ├─ lib/SimpleDeploy.Common.ps1          کمک‌توابع مشترک، اجرای امن هر process خارجی
  ├─ lib/SimpleDeploy.Config.ps1          بارگذاری/اعتبارسنجی config.json
  ├─ lib/SimpleDeploy.ServiceControl.ps1  توقف/بازگردانی Deployment Service + crash recovery
  ├─ lib/SimpleDeploy.Preflight.ps1       بررسی‌های پیش از Deploy + قفل تک‌نمونه‌ای
  ├─ lib/SimpleDeploy.Git.ps1             git status/commit/push + بررسی فایل حساس
  ├─ lib/SimpleDeploy.Build.ps1           build ایزوله (git archive + composer/npm + Artifact)
  ├─ lib/SimpleDeploy.Transfer.ps1        ساخت امن دستور ssh/scp + انتقال
  └─ lib/SimpleDeploy.Orchestration.ps1   جریان کامل هر پروژه

scripts/activate-release.sh   (روی VPS اجرا می‌شود — فعال‌سازی اتمیک، rollback، health check)
```

### جریان یک اجرای کامل

```
شروع
  │
  ▼
بررسی وضعیت بازیابی (crash recovery) از اجرای قبلی — در صورت وجود، پرسیده می‌شود
  │
  ▼
قفل تک‌نمونه‌ای (Mutex) — از اجرای هم‌زمان دو نمونه جلوگیری می‌کند
  │
  ▼
بارگذاری config.json → انتخاب پروژه(ها)
  │
  ▼
توقف موقت Deployment Service (در صورت PauseDeploymentService=true)
  │
  ▼
برای هر پروژه انتخاب‌شده:
  │
  ├─ Preflight (پوشه/git/branch/remote/SSH/قفل/عدم اجرای Agent)
  ├─ نمایش git status --short + بررسی فایل حساس/حجیم
  ├─ (اگر تغییری نیست → Skip)
  ├─ دریافت/پیشنهاد پیام commit + تأیید کاربر
  ├─ git add (فقط فایل‌های تأییدشده) → commit → push (بدون force)
  ├─ (اگر پیکربندی Deploy کامل نیست → فقط push، توقف اینجا)
  ├─ ساخت Workspace ایزوله + git archive همان commit
  ├─ composer install / npm ci+build (ایزوله، بدون لمس node_modules/vendor واقعی)
  ├─ ساخت Artifact + checksum
  ├─ انتقال با scp (کلید SSH، StrictHostKeyChecking=yes)
  └─ اجرای scripts/activate-release.sh روی سرور (swap اتمیک، migrate، health check، rollback خودکار در صورت خطا)
  │
  ▼
بازگردانی Deployment Service به وضعیت قبل از اجرا (در finally — همیشه اجرا می‌شود)
  │
  ▼
گزارش نهایی + Exit Code مناسب
```

## پروژه‌های Audit‌شده

| پروژه | local_path | branch | server_path | وضعیت |
|---|---|---|---|---|
| customer-site | `C:\laragon\www\customer-site` | main | `/var/www/customer-site` | نیازمند تکمیل `server_host`/`server_user`/`ssh_key_path` |
| saas-platform | `C:\laragon\www\saas-platform` | main | `/var/www/saas-platform` | نیازمند تکمیل `server_host`/`server_user`/`ssh_key_path` |
| deployment-system | `C:\laragon\www\deployment-system` | master | `/var/www/deployment-system` | نیازمند تکمیل `server_host`/`server_user`/`ssh_key_path` |
| sandogh-family-fund | `C:\Users\Farshad-IT\Sandogh` | main | `/var/www/sandogh` | `build_mode=disabled` — پشته Node.js/Prisma/PM2 است، نه Laravel |

هیچ‌کدام از این مقادیر حدس زده نشده‌اند — `local_path`/`branch`/`remote` با
`git remote -v` و `git branch --show-current` روی چک‌اوت واقعی هر پروژه
تأیید شده، و `server_path` از مستندات DEPLOY.md/DEPLOY-fa.md/DEPLOYMENT.md
**خودِ همان پروژه‌ها** استخراج شده است.

**`server_host`، `server_user` و `ssh_key_path` برای هیچ پروژه‌ای در دسترس
نبودند** (این مقادیر جایی در این مکالمه داده نشده‌اند) — بدون این‌ها
اتصال SSH/SCP اصلاً ممکن نیست، پس این ابزار تا زمانی که یک اپراتور انسانی
این مقادیر را در `config.json` تکمیل نکند، Deploy واقعی هیچ پروژه‌ای را
انجام نمی‌دهد (فقط git status/commit/push کار می‌کند).

### چرا Sandogh فرق دارد

مستندات خودِ Sandogh (`DEPLOYMENT.md`) صراحتاً یک پشته **Node.js/Next.js +
Prisma + PM2** را توصیف می‌کند (`npm ci --omit=dev`، `prisma:migrate:deploy`،
PM2 روی پورت‌های 3000/3001) — کاملاً متفاوت از پایپ‌لاین Laravel/Composer/
artisan که `build_mode=artifact` این ابزار پیاده‌سازی می‌کند. ساخت یک
پایپ‌لاین صحیح Node/Prisma/PM2 بخشی از این وظیفه نبود، پس به‌جای حدس زدن،
`build_mode` آن روی `disabled` گذاشته شده — یعنی فقط git commit/push برای
این پروژه کار می‌کند، تا زمانی که یک پایپ‌لاین مناسب آن به‌صورت آگاهانه
اضافه شود.

## نصب / اجرای واقعی

1. `config.example.json` را کپی کرده و به `config.json` تغییر نام دهید
   (این فایل در `.gitignore` است — هرگز commit نمی‌شود).
2. مقادیر `CHANGE_ME_*` را برای هر پروژه‌ای که می‌خواهید Deploy کنید تکمیل
   کنید: `server_host`، `server_user`، `ssh_key_path`، و در صورت نیاز
   `health_check_url`.
3. مطمئن شوید کلید SSH خصوصی مسیر داده‌شده در `ssh_key_path` واقعاً وجود
   دارد و بدون رمز عبور (یا با ssh-agent) قابل استفاده است.
4. مطمئن شوید fingerprint سرور مقصد از قبل به‌صورت دستی تأیید و به
   `known_hosts` اضافه شده (این ابزار **هرگز** خودش هاست جدید را
   trust-on-first-use نمی‌کند):
   ```powershell
   ssh-keyscan -H <server_host> | Out-File -Append -Encoding utf8 "$env:USERPROFILE\.ssh\known_hosts"
   # سپس fingerprint نمایش‌داده‌شده را با یک کانال مستقل (مثلاً از خودِ اپراتور سرور) تطبیق دهید.
   ```
5. اجرا:
   ```powershell
   .\SimpleDeploy.ps1
   ```
   یا با دوبار کلیک روی `SimpleDeploy.bat`.

### مثال‌ها

```powershell
.\SimpleDeploy.ps1
.\SimpleDeploy.ps1 -Project customer-site
.\SimpleDeploy.ps1 -All
.\SimpleDeploy.ps1 -Project customer-site -CommitMessage "fix: ..." -NonInteractive
.\SimpleDeploy.ps1 -All -DryRun
```

## Dry Run

```powershell
.\SimpleDeploy.ps1 -All -DryRun
```

در این حالت: تمام مراحل (Preflight، وضعیت Git، فایل‌هایی که قرار است
commit شوند، build_mode/activation_mode، و مقصد SSH/SCP) نمایش داده
می‌شوند، اما **هیچ commit، push، upload، اتصال SSH، یا تغییر Scheduled
Task واقعی انجام نمی‌شود** — حتی توقف Deployment Service هم فقط
شبیه‌سازی و نمایش داده می‌شود، هرگز واقعاً اجرا نمی‌شود.

## ساخت Shortcut / فایل اجرایی

منبع اصلی و قابل Audit این ابزار عمداً **PowerShell** است (نه یک باینری
کامپایل‌شده) — هر خط از منطق واقعی قابل خواندن و بازبینی است. برای اجرای
دوکلیکی، از `SimpleDeploy.bat` استفاده کنید؛ برای ساخت یک Shortcut روی
Desktop:

1. روی `SimpleDeploy.bat` راست‌کلیک کنید → Create shortcut.
2. Shortcut ساخته‌شده را به Desktop منتقل کنید.

ساخت یک فایل `.exe` مستقل (مثلاً با ابزارهایی مثل `ps2exe`) عمداً در این
تحویل قرار نگرفت — چنین ابزارهایی معمولاً منبع را داخل یک باینری
کامپایل‌شده پنهان می‌کنند که Audit مستقیم آن سخت‌تر می‌شود، و برخی از این
ابزارها به یک ماژول ثالث نیاز دارند که روی این سیستم از پیش نصب نیست. اگر
بعداً یک EXE واقعی لازم شد، `ps2exe` (منبع باز، به‌صورت source-visible
wrap می‌کند، نه کامپایل واقعی) گزینه پیشنهادی است.

## توقف اضطراری

- **در حین اجرا**: `Ctrl+C` در پنجره PowerShell. بلوک `finally` در
  `SimpleDeploy.ps1` همچنان تلاش می‌کند Deployment Service را به وضعیت
  قبلی بازگرداند (مگر اینکه خودِ پردازش PowerShell به‌طور کامل kill شده
  باشد که در آن صورت به «بازیابی Scheduled Taskها» زیر مراجعه کنید).
- **متوقف کردن یک اجرای دیگر که قفل را نگه داشته**: قفل یک Mutex سیستمی
  (`Global\SimpleDeployerSingleInstance`) است که با بسته‌شدن پردازش
  PowerShell به‌صورت خودکار آزاد می‌شود — نیازی به دستور دستی نیست؛ کافی
  است پردازش PowerShell مربوطه را ببندید.

## بازیابی Scheduled Taskهای Deployment

اگر ابزار در میانه توقف Deployment Service بسته شود (crash)، در اجرای
بعدی به‌صورت خودکار این وضعیت شناسایی و از شما پرسیده می‌شود:

> «Deployment Service در اجرای قبلی متوقف شده بود. آیا دوباره راه‌اندازی
> شود؟»

برای بازیابی دستی بدون اجرای یک Deploy جدید:

```powershell
.\SimpleDeploy.ps1 -ResumeDeploymentService
```

یا برای بررسی/بازگردانی دستی مستقیم Scheduled Taskها:

```powershell
schtasks /Query /TN "DeploymentLocalAgent"
schtasks /Run /TN "DeploymentLocalAgent"
schtasks /Run /TN "DeploymentLocalAgentWatchdog"
```

## گزینه‌های کنترل Deployment Service

| پارامتر | پیش‌فرض | توضیح |
|---|---|---|
| `-PauseDeploymentService` | `$true` | قبل از Deploy متوقف و در پایان بازگردانده می‌شود |
| `-SkipDeploymentService` | خاموش | Deployment Service اصلاً لمس نمی‌شود |
| `-ResumeDeploymentService` | خاموش | فقط وضعیت بازیابی قبلی را بررسی/بازگردانی می‌کند و خارج می‌شود (بدون Deploy) |

## ریسک‌های باقی‌مانده

- **تست‌های SSH/SCP/activation واقعی روی VPS انجام نشده‌اند** — هیچ
  `server_host` واقعی در دسترس نبود؛ تست‌ها روی fixtureهای محلی (بدون
  اتصال شبکه واقعی) اجرا شده‌اند. اولین اجرای واقعی روی هر پروژه باید با
  دقت (و ترجیحاً با `-DryRun` قبل از آن) رصد شود.
- **قسمت سمت سرور Linux برای توقف `deployment-agent.service`** پیاده‌سازی
  شده اما (به همان دلیل نبود دسترسی به VPS واقعی) روی سرور واقعی اجرا/تست
  نشده — نام سرویس از خودِ template نصب این پروژه
  (`agent/install/deployment-agent.service.example`) گرفته شده، نه حدس.
- **`build_mode=git-only`/`files-only`** پیاده‌سازی شده‌اند اما با جزئیات
  کمتری نسبت به `artifact` مشخص شده بودند — پیاده‌سازی فعلی هر دو را از
  طریق همان مسیر یکسان Artifact+activate-release.sh عبور می‌دهد (فقط
  محتوای Artifact فرق می‌کند)، نه یک مسیر جداگانه.
- **Sandogh کاملاً غیرفعال برای Deploy واقعی باقی مانده** — عمداً، تا یک
  پایپ‌لاین Node/Prisma/PM2 جداگانه به‌صورت آگاهانه طراحی شود.
- **health check ساده است** (فقط یک درخواست HTTP موفق) — بررسی عمیق‌تر
  (مثلاً محتوای پاسخ) جزو این نسخه نیست.
- این ابزار **جایگزین دائمی** سرویس Deployment نیست — طبق درخواست صریح،
  فقط یک مسیر موقت و ساده است.

## محدودیت‌های صریح رعایت‌شده

- سرویس Deployment فعلی حذف/تغییر نشد.
- هیچ Job در Hub ساخته نمی‌شود؛ Agentها برای Deploy استفاده نمی‌شوند.
- `config.php` واقعی هیچ Agent‌ای تغییر نکرد.
- Working Tree پروژه‌ها توسط build لمس نمی‌شود (git archive + workspace
  ایزوله، دقیقاً مثل Local Agent موجود این پروژه).
- هیچ secret وارد Git یا Artifact نمی‌شود.
- `StrictHostKeyChecking=no` در هیچ مسیر کدی وجود ندارد.
- force push، `git reset`/`git clean` روی تغییرات کاربر، در هیچ کجای این
  ابزار وجود ندارد.
