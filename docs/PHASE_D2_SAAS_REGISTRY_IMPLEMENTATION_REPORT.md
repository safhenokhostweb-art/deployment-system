# Phase D.2 — SaaS Deployment Registry Integration — گزارش Audit

**وضعیت این سند: فقط Audit + طراحی. هیچ Migration، API، Model، Middleware، Route یا تغییر کدی —
نه در `saas-platform` و نه در `deployment-system` — انجام نشده است.** همهٔ پیشنهادهای این گزارش
منتظر تأیید صریح شما هستند؛ بخش «Implementation Rules» فقط بعد از تأیید این سند اجرا می‌شود.

## 1. Audit Findings

### 1.1 منبع واقعی Target Deploy چیست؟

سه جدول درگیرند و **هیچ‌کدام به‌تنهایی «Target» کامل نیستند**:

- **`sites`** (مدل `Site`): هویت مشتری/دامنه/چرخهٔ حیات (`lifecycle_status`) را دارد، اما **هیچ اطلاعات
  سرور/SSH ندارد**.
- **`customer_remote_servers`** (مدل `CustomerRemoteServer`): اطلاعات اتصال (`host`, `port`, `username`,
  `path`, `domain`, `auth_method`, و credential‌های رمزنگاری‌شده) را دارد، اما **مستقیماً به هیچ Site ای
  متصل نیست** — یک سرور می‌تواند میزبان چند Installation باشد.
- **`customer_installations`** (مدل `CustomerInstallation`): **تنها نقطه‌ای که `site_id` را به
  `remote_server_id` وصل می‌کند** — یعنی منبع واقعی Target دقیقاً همین جدول است: هر رکورد
  `CustomerInstallation` با `status='complete'` یک جفت (Site واقعی، RemoteServer واقعی) را نشان می‌دهد.

### 1.2 رابطهٔ Site → Installation → RemoteServer چگونه است؟

```
Site (sites.id)
   ▲
   │  site_id  (ستون خام، بدون FK constraint، بدون رابطهٔ Eloquent در هیچ جهت)
   │
CustomerInstallation (customer_installations.site_id, remote_server_id)
   │
   │  remote_server_id → belongsTo(CustomerRemoteServer::class)  [رابطهٔ Eloquent واقعی: ✅ موجود]
   ▼
CustomerRemoteServer (customer_remote_servers.id)
```

**یافتهٔ کلیدی و غیربدیهی**: `CustomerInstallation::remoteServer()` به‌صورت `belongsTo` واقعی تعریف
شده، اما **هیچ `Site::installations()` یا `CustomerInstallation::site()` ای در کدبیس وجود ندارد** —
با اینکه `site_id` هم در migration و هم در `$fillable` مدل حاضر است. تأیید شد که این خلأ عمدی/فراموش‌شده
نیست بلکه **الگوی واقعی استفاده‌شدهٔ کد است**: در
`CustomerDeploymentController` (که امروز تنها مصرف‌کنندهٔ این رابطه است)، `$installation->site_id` همیشه
به‌صورت مقدار خام خوانده می‌شود (خطوط ۳۱۶، ۳۴۷، ۳۸۴)، نه از طریق `->site`. یعنی **الگوی established در
این کدبیس، lookup دستی با `site_id` است، نه افزودن رابطهٔ Eloquent تازه.**

نتیجه برای Registry Service: باید همین الگو را ادامه دهد — `Site::whereIn('id', $siteIds)->get()->keyBy('id')`
(batch، نه N+1) — **بدون افزودن رابطهٔ جدید به هیچ مدلی**، دقیقاً مطابق محدودیت شما.

⚠️ **ریسک داده‌ای واقعی (نه محدودیت طراحی API، بلکه یک خلأ در مدل دادهٔ موجود)**: یک Site می‌تواند
بیش از یک ردیف `CustomerInstallation` با `status='complete'` داشته باشد (مثلاً یک نصب اولیه، سپس یک
`MODE_MOVE` به سرور جدید که ردیف تازه‌ای می‌سازد). **هیچ فیلدی مشخص نمی‌کند کدام ردیف «Installation
فعلی/زندهٔ» آن Site است** — نه پرچمی روی ردیف قدیمی، نه اشاره‌گری روی `Site`. این یک سؤال محصولی است
که باید قبل از پیاده‌سازی واقعی پاسخ داده شود (بخش ۸، ریسک #۱).

### 1.3 چه اطلاعاتی بدون Security Risk قابل Export است؟

| فیلد | منبع | ریسک |
|---|---|---|
| `site.id`, `customer_id` | `sites` | ✅ بدون ریسک — شناسهٔ داخلی |
| `domain` | `sites.domain` (پیشنهاد؛ نه `customer_remote_servers.domain` یا `customer_installations.customer_domain` — بخش ۵) | ✅ بدون ریسک — عمومی |
| `lifecycle_status` | `sites.lifecycle_status` | ✅ بدون ریسک |
| `host`, `port`, `username`, `path` | `customer_remote_servers.{host,port,username,path}` | ✅ بدون ریسک ذاتی (بدون credential، فقط آدرس اتصال) — اما فقط برای caller احراز‌هویت‌شدهٔ Deployment Service |

### 1.4 چه اطلاعاتی نباید هرگز خارج شود؟

تأیید شد که این فیلدها **همین الان هم** با `protected $hidden` روی `CustomerRemoteServer` محافظت
می‌شوند (اما `$hidden` فقط سریالایز پیش‌فرض مدل را می‌پوشاند — یک Resource/Query دستی می‌تواند این محدودیت
را دور بزند، پس Registry Service باید **صراحتاً allowlist-based** باشد، نه صرفاً به `$hidden` تکیه کند):

- `customer_remote_servers.private_key_encrypted`, `password_encrypted`, `api_token_encrypted`
- `installation_credentials.encrypted_value` (مدل `InstallationCredential` — کاملاً جدا از Remote Server، حتی رمزگذاری‌شده‌تر)
- `customer_installations.request_payload` (طبق کامنت خود مدل: «Encrypted CustomerInstallRequest» — ممکن است داده‌های حساس سفارش را در خود داشته باشد)
- هرچیز مرتبط با `ModuleLicense`/`ModuleEntitlement`/`PlatformLicense` (سیستم License، طبق محدودیت صریح شما، کاملاً جدا می‌ماند)
- `customers.mobile`, `notes` و سایر PII مشتری

### 1.5 آیا Application Registry فعلی وجود دارد؟

**خیر.** جستجوی کامل کدبیس نشان داد هیچ مفهوم `application_key`/`product_key` در `saas-platform`
وجود ندارد. تنها مدل مشابه، `Product` است که یک **کاتالوگ قیمت‌گذاری/SKU** است (`sku`, `price_irr`,
`type` ∈ `license_template|module_addon|support_plan|motion_asset`) — کاملاً بی‌ربط به «کدام نرم‌افزار
روی این Target دیپلوی می‌شود». امروز، تلویحاً، **هر Site دقیقاً یک محصول است: customer-site** — دقیقاً
همان فرضی که SimpleDeploy از قبل با هاردکد `application_key = "customer-site"` و یک TODO صریح برایش
گذاشته است.

### 1.6 آیا Multi Product Architecture آماده است؟

**خیر، و این محدودیت از `saas-platform` می‌آید، نه از SimpleDeploy.** چون هیچ `application_key`ای
در `Site`/`CustomerInstallation` ذخیره نمی‌شود، Registry API امروز **فقط می‌تواند یک Application ثابت
(`customer-site`) را بازگرداند** — دقیقاً مطابق پیشنهاد خود شما در Payload نمونه. افزودن Multi-Product
واقعی نیازمند یک فیلد جدید (مثلاً `sites.product_key` یا معادل آن) در سمت `saas-platform` است که
خارج از حدود این فاز است.

### یافتهٔ اضافی مهم: نوع Driver محدودکنندهٔ سازگاری واقعی است

`CustomerRemoteServer.type` یک enum معماری (`DriverType`) دارد: `ssh`, `cpanel`, `directadmin`, `sftp`,
`docker`, `fake`. امروز `isImplemented()` فقط `SSH`, `CPANEL`, `FAKE` را `true` می‌داند (کامنت خود کد:
«Phase 14-C — cPanel connects over SSH transport... directadmin/sftp/docker remain architecture stubs»).

**این برای Registry اهمیت مستقیم دارد**: `activate-release.sh` (اسکریپت فعال‌سازی SimpleDeploy) برای
یک محیط bash با دسترسی نسبتاً کامل (rename اتمیک، `chmod`, احتمالاً `artisan queue:restart`) طراحی و
تست شده — **هرگز روی cPanel Shared Hosting تست نشده**. صرفاً چون `saas-platform` نصب اولیه را روی
cPanel هم پیاده‌سازی کرده، به این معنا نیست که مسیر Deploy مکرر SimpleDeploy هم روی آن کار می‌کند. پیشنهاد
بخش ۵: فیلتر Registry در این فاز فقط `type='ssh'` را برگرداند (نه هر `isImplemented()=true`ای).

## 2. Architecture Decision

```
saas-platform (DB)
   │
   │  Registry Service (Read-Only، Query ساده — بدون رابطهٔ Eloquent جدید)
   ▼
GET /api/v1/deployment/targets   (Bearer Auth، مشابه AuthenticateStudioApi)
   │
   │  HTTPS، یک بار در هر Sync — نه در هر Deploy
   ▼
SimpleDeploy Launcher
   │
   │  Registry Client  →  state/tenant-registry.json  (Cache محلی، atomic write)
   ▼
Target Resolver  (تابع جدید، نه تغییر در Orchestration.ps1)
   │
   │  اگر Registry برای این Application موجود بود → Targetهای Registry
   │  در غیر این صورت → دقیقاً همان ConvertTo-DeploymentTarget فعلی (بدون تغییر)
   ▼
Invoke-ApplicationDeployToTargets( -Targets <resolved> )   ← پارامتر -Targets از قبل، امروز، اختیاری و موجود است
   │
   ▼
Customer Sites (واقعی)
```

**تصمیم کلیدی معماری**: `Invoke-ApplicationDeployToTargets` (لایهٔ Deploy Engine، از فاز D.1) از قبل
پارامتر `-Targets` اختیاری دارد و وقتی خالی باشد به دقیقاً یک Target legacy برمی‌گردد (کامنت خود کد:
«e.g. a future Registry sync»). **این یعنی Deploy Engine برای این فاز به هیچ تغییری نیاز ندارد** — فقط
باید تعیین شود *کدام لیست Target* به آن پاس داده شود؛ این تصمیم در لایهٔ Launcher/CLI (بیرون از
Orchestration.ps1) گرفته می‌شود، دقیقاً همان‌جایی که امروز `ConvertTo-DeploymentTarget` صدا زده می‌شود.

## 3. API Contract (پیشنهادی — برای تأیید)

```
GET /api/v1/deployment/targets
Authorization: Bearer <DEPLOYMENT_API_KEY>
```

```json
{
  "registry_version": 1,
  "generated_at": "2026-08-10T12:00:00Z",
  "applications": [
    {
      "application_key": "customer-site",
      "targets": [
        {
          "target_key": "site-4231",
          "site_id": 4231,
          "customer_id": 812,
          "domain": "example-customer.ir",
          "lifecycle_status": "active",
          "deployment_channel": "stable",
          "server_host": "203.0.113.10",
          "server_port": 22,
          "server_user": "deploy",
          "server_path": "/home/deploy/example-customer.ir",
          "health_check_url": null,
          "remote_php_binary": null,
          "enabled": true
        }
      ]
    }
  ]
}
```

تفاوت‌های عمدی نسبت به payload پیشنهادی شما (با دلیل):

- `target_key`: پیشنهاد `"site-{site_id}"` — پایدار، یکتا، بدون نیاز به شمارندهٔ جدید.
- `remote_php_binary` اضافه شد (اختیاری، `null`) — چون `Invoke-ArtifactToTarget` از قبل این فیلد را
  از Target می‌خواند؛ نبودش یعنی همیشه فرض پیش‌فرض `php` روی سرور، که ممکن است روی هاست‌های چندنسخه‌ای
  اشتباه باشد (بخش ۸، ریسک #۴).
- **هیچ فیلد SSH Key/credential‌ای در contract نیست** — عمداً، طبق Security Rules. (بخش ۸، ریسک #۶
  دربارهٔ اینکه این یعنی چه.)

## 4. Security Model

- **Middleware جدید پیشنهادی**: `App\Http\Middleware\AuthenticateDeploymentApi` — کپی دقیق الگوی
  `AuthenticateStudioApi` (خط‌به‌خط تأییدشده در Audit): `Bearer` token از `$request->bearerToken()`،
  مقایسه با `hash_equals()` در برابر `config('services.deployment.api_key', '')` که از
  `env('DEPLOYMENT_API_KEY')` می‌آید. **بدون Sanctum/OAuth** — دقیقاً مثل Studio، چون این هم یک
  ارتباط machine-to-machine تک‌کاربره است، نه چندکاربرهٔ session-based؛ Audit فنی هیچ دلیلی برای
  پیچیدگی بیشتر پیدا نکرد.
- **ثبت میان‌افزار**: یک ورودی تازه در `$routeMiddleware` آرایهٔ `app/Http/Kernel.php` (مثل
  `'studio.api' => ...`) → `'deployment.api' => \App\Http\Middleware\AuthenticateDeploymentApi::class`.
- **مسیر پیشنهادی**: `Route::prefix('v1/deployment')->middleware(['deployment.api', 'throttle:30,1'])->name('api.deployment.')`
  — دقیقاً همان الگوی گروه `v1/studio` در `routes/api.php`.
- ⚠️ **این کلید حساس‌تر از `STUDIO_API_KEY` است**: کلید Studio فقط دسترسی به import پک تم می‌دهد؛
  این کلید توپولوژی کامل سرورهای همهٔ مشتریان را می‌دهد (نه credential، اما شناسایی دقیق مسیر حمله).
  باید `secret` جداگانه، rotation جداگانه، و throttle محافظه‌کارانه‌تر داشته باشد.
- **Response همیشه Read-Only** — کنترلر پیشنهادی فقط `GET`، بدون هیچ اکشن نوشتنی؛ خود همین «فقط GET
  در این کنترلر» تضمین می‌کند که حتی در صورت نشت توکن، امکان تغییر داده وجود ندارد (defense-in-depth
  ساده، نه اتکا فقط به نیت خوب middleware).

## 5. Data Mapping

| Registry field | منبع دقیق در saas-platform | یادداشت |
|---|---|---|
| `application_key` | هاردکد `"customer-site"` | تا وقتی `saas-platform` مفهوم Multi-Product ندارد (بخش ۱.۶) |
| `target_key` | `"site-" . site.id` | تولیدشده، نه ستون واقعی |
| `site_id` | `sites.id` | |
| `customer_id` | `sites.customer_id` | |
| `domain` | `sites.domain` | **نه** `customer_remote_servers.domain` (دامنهٔ اولیهٔ سرور، ممکن است با Site فرق کند) و **نه** `customer_installations.customer_domain` (ورودی زمان درخواست نصب، ممکن است قدیمی باشد) — `sites.domain` تنها فیلدی است که مدیریت جاری روی آن انجام می‌شود |
| `lifecycle_status` | `sites.lifecycle_status` | فیلتر می‌شود (بخش زیر) |
| `deployment_channel` | هاردکد `"stable"` | هیچ معادلی در `saas-platform` نیست (بخش ۸، ریسک #۵) |
| `server_host` | `customer_remote_servers.host` | از طریق `customer_installations.remote_server_id` |
| `server_port` | `customer_remote_servers.port` | |
| `server_user` | `customer_remote_servers.username` | |
| `server_path` | `customer_remote_servers.path` | (اضافه‌شده در migration `..._add_driver_fields_...`) |
| `health_check_url` | همیشه `null` | هیچ فیلد معادلی وجود ندارد (بخش ۸، ریسک #۳) |
| `remote_php_binary` | همیشه `null` | هیچ فیلد معادلی وجود ندارد (بخش ۸، ریسک #۴) |
| `enabled` | همیشه `true` | چون فیلترشده‌ها اصلاً برگردانده نمی‌شوند |

**قواعد فیلتر (Query سطح Registry Service، نه یک Scope جدید روی مدل)**:

```
CustomerInstallation.status = 'complete'
  AND Site.lifecycle_status IN ('active', 'grace_period')
  AND CustomerRemoteServer.type = 'ssh'          ← اضافه‌شده توسط این Audit (بخش ۱، یافتهٔ Driver)
  AND (یک ردیف به‌ازای هر site_id — جدیدترین completed_at — تا زمانی که ریسک #۱ حل شود)
```

سپس نگاشت به شکل دقیق `ConvertTo-DeploymentTarget` سمت SimpleDeploy:

| Registry field | `ConvertTo-DeploymentTarget` field معادل |
|---|---|
| `target_key` | `TargetKey` |
| `domain` | `Domain` |
| `lifecycle_status` | `LifecycleStatus` |
| `deployment_channel` | `DeploymentChannel` |
| `server_host` | `ServerHost` |
| `server_port` | `ServerPort` |
| `server_user` | `ServerUser` |
| `server_path` | `ServerPath` |
| `health_check_url` | `HealthCheckUrl` |
| `remote_php_binary` | `RemotePhpBinary` |
| `enabled` | `Enabled` |
| *(از config محلی، نه Registry)* | `SshKeyPath` — همیشه محلی می‌ماند (بخش ۸، ریسک #۶) |
| ثابت | `Source = 'saas-registry'` (به‌جای `'legacy-flat-config'`) — دقیقاً همان تمایزی که کامنت خود `ConvertTo-DeploymentTarget` از قبل پیش‌بینی کرده بود |

## 6. Launcher Integration (طراحی — بدون ساخت UI)

### فایل جدید پیشنهادی (فقط طراحی، هنوز ساخته نشده): `lib/SimpleDeploy.RegistryClient.ps1`

- `Invoke-DeploymentRegistrySync -RegistryUrl -ApiKey -TimeoutSeconds -DestinationPath` — یک تماس
  `Invoke-RestMethod` با هدر `Authorization: Bearer`، سپس نوشتن atomic (فایل موقت `.tmp` → rename،
  دقیقاً همان الگوی `.part`→rename که در `Invoke-ScpUpload` استفاده شده) به
  `state/tenant-registry.json`. خطای شبکه/HTTP هرگز throw نمی‌کند بیرون از کنترل تماس‌گیرنده — نتیجه را
  به‌صورت `@{Success;Reason}` برمی‌گرداند، دقیقاً مطابق الگوی نتیجهٔ سایر توابع این کدبیس.
- **هیچ HTTP client زیرساختی امروز در SimpleDeploy وجود ندارد** (تأیید شد: `Invoke-RestMethod`/
  `Invoke-WebRequest` در هیچ فایل `lib/`/`launcher/` استفاده نشده) — این اولین وابستگی شبکه‌ای واقعی
  این ابزار خواهد بود؛ باید صریحاً به کاربر گفته شود (نه یک تغییر «بی‌اثر»).
- **مکان Cache**: `state/` از قبل به‌عنوان دایرکتوری runtime/gitignored برای `launcher-debug.log`
  استفاده می‌شود — `tenant-registry.json` دقیقاً همان‌جا قرار می‌گیرد، بدون قرارداد مکانی تازه.
- **محتوای Cache**: پاسخ خام Registry API + یک فیلد اضافه‌شدهٔ سمت کلاینت `last_sync` (زمان واقعی
  دریافت روی ماشین Windows — نه `generated_at` سرور، چون آن دو معنای متفاوتی دارند: یکی «سرور کی این
  را تولید کرد»، دیگری «من کی آخرین بار گرفتم»).
- **Trigger پیشنهادی برای این فاز**: **فقط Sync دستی** (یک دستور/دکمهٔ آینده) — نه خودکار در Start
  Launcher. Sync خودکار در startup یعنی هر اجرای Launcher (حتی برای یک Deploy کاملاً local/legacy)
  یک تماس شبکه‌ای اجباری می‌شود؛ این با «Backward Compatibility نباید خراب شود» در تناقض است. Sync
  در Startup می‌تواند بعداً به‌صورت یک فلگ صریح `registry.sync_on_start` در `config.json` اضافه شود
  (پیش‌فرض `false`).
- **Target Resolver** (تابع جدید پیشنهادی، مثلاً `Get-EffectiveDeploymentTargets`): اگر
  `state/tenant-registry.json` موجود بود و برای `ApplicationKey` جاری Target داشت → همان‌ها را
  (با `Source='saas-registry'`) برمی‌گرداند؛ **در غیر این صورت دقیقاً `ConvertTo-DeploymentTarget`
  فعلی** — یعنی نبود فایل Registry (چون کاربر هرگز Sync نزده، یا اصلاً `registry_url` را در
  `config.json` تنظیم نکرده) => رفتار ۱۰۰٪ همان امروز.
- **Invalid Registry handling**: JSON خراب/ناقص/`registry_version` ناشناخته → لاگ هشدار + بازگشت
  خودکار به legacy target (هرگز کرش، هرگز مانع Deploy تک‌پروژه).

### چرا Deploy Engine (Orchestration.ps1) دست‌نخورده می‌ماند

چون `-Targets` روی `Invoke-ApplicationDeployToTargets` از قبل «آرایه‌ای از اشیاء با شکل
`ConvertTo-DeploymentTarget`» را می‌پذیرد، Target Resolver فقط باید همان شکل را تولید کند — تابعی در
یک لایهٔ *بالاتر* از Deploy Engine (CLI/Launcher)، نه تغییری *داخل* آن.

## 7. Target Selection — Deploy Scope (طراحی مفهومی، بدون UI)

| Scope | معنا | وابستگی |
|---|---|---|
| **All Targets** | همهٔ Targetهای فعال Registry برای این Application | فقط Registry |
| **Selected Targets** | زیرمجموعه‌ای دستی از `target_key`ها | فقط Registry + انتخاب محلی (نیازی به تغییر ساختار API نیست) |
| **Customer Target** | همهٔ Siteهای یک `customer_id` | فقط Registry (فیلتر روی فیلد موجود `customer_id`) |
| **Environment** (`test`/`beta`/`stable`) | فیلتر روی `deployment_channel` | ⛔ **مسدود توسط saas-platform**: چون `deployment_channel` امروز همیشه هاردکد `"stable"` است (بخش ۱.۶/۵)، این Scope تا وقتی `saas-platform` مفهوم Channel/Environment واقعی نداشته باشد، عملاً بی‌اثر است — نه یک محدودیت طراحی SimpleDeploy |

هر ۴ حالت به‌صورت یک فیلتر ساده روی همان لیست Target حل‌شده (خروجی Target Resolver) قابل پیاده‌سازی‌اند
— نیازی به تغییر API Contract یا Deploy Engine ندارند.

## 8. Testing Plan (طراحی — قبل از پیاده‌سازی)

### SaaS (`saas-platform`، Feature Tests روی `AuthenticateDeploymentApi`/Registry Controller)

- توکن معتبر → ۲۰۰ + فقط Targetهای واجد شرایط
- توکن غلط/غایب → ۴۰۱، بدون هیچ داده‌ای در بدنهٔ پاسخ
- یک Site با `status='complete'` ولی `lifecycle_status='suspended'` → حذف می‌شود
- یک Site با `lifecycle_status='active'` ولی `CustomerInstallation.status='pending'` → حذف می‌شود
- یک Site با `lifecycle_status='grace_period'` → **باقی می‌ماند** (طبق قاعدهٔ صریح شما)
- یک `CustomerRemoteServer.type='cpanel'` → حذف می‌شود (قاعدهٔ اضافه‌شدهٔ این Audit)
- بازرسی متنی کامل بدنهٔ پاسخ JSON: هیچ‌کدام از رشته‌های `private_key`, `password_encrypted`,
  `api_token_encrypted`, `encrypted_value`, `request_payload` نباید به‌صورت substring در پاسخ ظاهر شوند
  (تست منفی صریح، نه فقط تست مثبت شکل داده)
- چند Site متعلق به یک Customer → چند Target با `customer_id` یکسان (multi-target واقعی)
- یک Site با دو ردیف `CustomerInstallation` تاریخچه‌ای (`complete` قدیمی + `complete` جدید بعد از Move)
  → فقط جدیدترین برگردد (تا ریسک #۱ حل نشده، این تست باید رفتار *فعلی طراحی‌شده* — نه لزوماً «درست
  محصولی» — را قفل کند)

### Launcher (`SimpleDeployLauncher.*`)

- Sync موفق → `state/tenant-registry.json` نوشته می‌شود، atomic (فایل نیمه‌نوشته هرگز دیده نمی‌شود
  توسط یک خوانندهٔ هم‌زمان)
- Sync با شبکهٔ غیرقابل‌دسترس → خطای قابل‌فهم، فایل Cache قبلی (اگر بود) دست‌نخورده می‌ماند
- بارگذاری یک Cache معتبر از قبل → Target Resolver آن را برمی‌گرداند
- بارگذاری یک Cache خراب (JSON نامعتبر/`registry_version` ناشناس) → بازگشت امن به legacy، بدون کرش
- **نبود کامل فایل Cache** (کاربری که هرگز از این فیچر استفاده نکرده) → رفتار Byte-for-byte مثل امروز

### Deploy (End-to-End، با همان فلسفهٔ no-mocking فازهای قبلی — Targetهای Fake اما واقعاً فراخوانی‌شده)

- یک Application با یک Target حل‌شده از Registry → Deploy مثل امروز کار می‌کند
- یک Application با چند Target از Registry → همه امتحان می‌شوند (دقیقاً تست‌های Phase D.1 موجود، فقط
  با منبع Target = Registry به‌جای دستی)
- شکست یک Target مانع بقیه نمی‌شود (قبلاً در Phase D.1 برای Targetهای دستی تست شده؛ باید برای
  Targetهای منبع‌Registry هم تکرار شود — چون منبع Target نباید رفتار Deploy Engine را تغییر دهد، این
  تست همان تضمین را از زاویهٔ دیگر می‌بندد)

## 9. Remaining Risks (باید قبل یا حین Implementation تصمیم‌گیری شوند)

1. **بدون «Installation فعلی» مشخص برای یک Site با تاریخچهٔ چندگانه** (بخش ۱.۲) — نیازمند تصمیم
   محصولی در `saas-platform` (فیلد جدید یا قرارداد Query)، نه یک مشکل قابل‌حل در سمت SimpleDeploy.
2. **ابهام منبع `domain`** — `sites.domain` پیشنهاد شد؛ نیازمند تأیید صریح شما قبل از Implementation.
3. **`health_check_url` هیچ معادلی ندارد** — هر Target منبع‌Registry، Health Check را نادیده می‌گیرد
   (نه خراب می‌شود، فقط غیرفعال — چون `Invoke-ArtifactToTarget` از قبل `health_check_url` خالی را
   «رد شد» تفسیر می‌کند).
4. **`remote_php_binary` هیچ معادلی ندارد** — همیشه پیش‌فرض `php` روی سرور مقصد، که ممکن است روی
   هاست‌های چند-نسخه‌ای غلط باشد.
5. **`deployment_channel`/Environment در `saas-platform` وجود ندارد** — Deploy Scope «Environment»
   (بخش ۷) تا افزودن این مفهوم در سمت محصول، عملاً بی‌اثر می‌ماند.
6. **SSH Key هرگز از Registry منتقل نمی‌شود (عمدی و درست طبق Security Rules)** — یعنی ماشین Windows
   اجراکنندهٔ Launcher باید از قبل، از مسیری خارج از این معماری، به کلید SSH معتبر برای *هر* سرور
   مشتری که قرار است به آن Deploy شود دسترسی داشته باشد. Registry مسئلهٔ «کجا Deploy کنم» را حل می‌کند،
   نه «چطور احراز هویت کنم» — این باید صریحاً به شما گفته شود چون در نمونه API خود شما هم به‌درستی
   فیلد کلید نیامده بود، اما نتیجهٔ عملی‌اش (نیاز به یک فرایند توزیع کلید جداگانه) در تیکت اصلی ذکر
   نشده بود.
7. **فیلتر `type='ssh'`** (نه `isImplemented()`) — پیشنهاد این Audit، نیازمند تأیید شما (بخش ۱، یافتهٔ Driver).
8. **مقیاس**: برای ~۱۰۰ سایت، یک پاسخ JSON تک‌صفحه‌ای کافی است؛ Pagination برای این فاز طراحی نشد
   (اگر تعداد Site به هزاران برسد، باید بازبینی شود).
9. **حساسیت امنیتی بالاتر توکن این API نسبت به Studio API** (بخش ۴) — باید در فرایند نگهداری کلید
   (rotation، محل ذخیره‌سازی روی ماشین Windows) به‌صورت جداگانه در نظر گرفته شود.

---

## جمع‌بندی برای تأیید

این Audit پیشنهاد می‌کند Implementation طبق ترتیب زیر (که خود شما هم در «Implementation Rules» خواسته
بودید) پیش برود، **فقط بعد از تأیید صریح این سند و پاسخ به ریسک‌های #۱ و #۲ بالا**:

1. API فقط‌خواندنی در `saas-platform` (`AuthenticateDeploymentApi` + `DeploymentRegistryService` +
   Resource با allowlist صریح + Controller + Route + تست‌های بخش ۹)
2. `lib/SimpleDeploy.RegistryClient.ps1` در `deployment-system` (Sync + Cache atomic)
3. Target Resolver (تابع جدید، بدون تغییر `SimpleDeploy.Orchestration.ps1`)
4. اتصال Resolver به مسیر CLI/Launcher موجود (پاس‌دادن `-Targets` حل‌شده به `Invoke-ApplicationDeployToTargets`)

هیچ‌کدام از این ۴ مرحله بدون تأیید جداگانهٔ شما آغاز نمی‌شود.
