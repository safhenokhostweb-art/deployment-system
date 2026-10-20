# Deployment System – Agent Workflow

## مقدمه
این فایل، دستورالعمل اصلی **Agent** برای پروژه‌ی `deployment-system` است. هدف آن تعریف یک گردش کار ثابت و قابل ردیابی است که روی هر واحد کاری (feature، bugfix، refactor و ...) قابل استفاده باشد و به صورت خودکار تغییرات را اجرایی، تست، commit، push، ساخت Artifact، تحویل، و گزارش کند.

> **نکته مهم:** این فایل در همان مخزن موجود است تا بصورت خودکار توسط Claude Code بارگذاری و در دسترس **موقعیت**‌های مختلف به‌کار گرفته شود.

## پیش‌نیازها
1. **دسترسی** به **Hub** (Laravel service) و داشتن حساب کاربری Admin در داشبورد.
2. **پیکربندی Agent** در `local-agent/config.php`:
   * `hub_url` – آدرس Hub (مثال: `http://deployment-system.test/service/public`).
   * `local_agent_id`, `agent_token`, `signing_secret` – از داشبورد دریافت کنید.
3. **Agent** در یک سرور کارفرما (یا کامپیوتری) نصب شده و در حال اجرا است (`php agent.php` یا سرویس Systemd/Task Scheduler).
4. **Project mapping** در Hub تنظیم شده باشد (`/api/local-agent/projects`)، در حال حاضر همان پروژه‌ی `deployment-system` با `branch = master` و `environment = production` ثبت است.
5. **Git** روی مخزن محلی (در پوشه‌ی `C:\laragon\www\deployment-system`) به‌روز است و هیچ تغییر بدون commit وجود ندارد.

## گردش کار استاندارد (۸ مرحله)
1. **Implement** – تغییرات در شاخه‌ی جداگانه (`git checkout -b feature/...`).
2. **Validate** – اجرای تست‌های مربوطه:
   * `php tests/run.php` (local‑agent).
   * `deployment/tests/run.php` (deployment‑preparer).
   * `php artisan test` (service – چند سطر تست در صورت در دسترس).
   تمام تست‌ها باید **سبز** باشند؛ در صورت خطا، به طور واضح گزارش دهید و رفع کنید.
3. **Commit** – فایلی‌ها را با `git add <files>` مخصوصاً فقط تغییرات مرتبط stage کنید و `git commit -m "<پیام کوتاه>"` رَسید کنید. 
   **توجه:** تغییرات جدید از پروژه‌ی دیگر یا `untracked` فایلی‌ها را اضافه نکنید.
4. **Push** – `git push origin <branch>`؛ پس از push، می‌توانید به صورت دستی یک merge به `master` انجام دهید یا استفاده از فیچر `auto_deploy` در داشبورد.
5. **Build Artifact** – پس از push، **GitHub webhook** به Hub می‌فرستد. Hub یک **publish job** ایجاد می‌کند که توسط Agent (در گام بعدی) دریافت می‌شود. Agent به صورت خودکار یک workspace ساخته، کامپوزر/نرم‌افزارهای مورد نیاز را نصب، `npm ci`/`build` و در نهایت آرشیو zip (`artifact.zip`) را می‌سازد.
6. **Deliver Artifact** – Agent با دو فاز:
   * `POST /api/local-agent/artifacts` – متادیتا.
   * `POST /api/local-agent/artifacts/{id}/content` – بایت‌های ZIP.
   Hub پس از اتمام، `artifact.status = available` و `semantic_status = verified` می‌نشاند.
7. **Confirm Receipt** – بررسی وجود `Release.ready` و `Artifact.available` در داشبورد یا با API (`GET /api/agent/artifacts/{id}/download`).
   اگر وضعیت مناسب نباشد، خطا را گزارش کرده و مشکل را برطرف کنید.
8. **Report** – بر اساس قالب در بخش 10.۱ مقاله‌ی گزارش را فراهم کنید:
   * مشخصات: نام پروژه، شناسه واحد کاری، شرح تغییر.
   * اعتبارسنجی: لیست تست‌ها و نتیجه.
   * Git: Branch، Commit SHA، Remote، نتیجه Push.
   * Artifact: شناسه، checksum، status.
   * Deployment‑system: وضعیت ارسال، شناسه Artifact در مقصد، نتیجه تأیید، شواهد.
   * وضعیت نهایی: `READY_FOR_REVIEW`, `BLOCKED`, `FAILED`.

## جزئیات فنی و API
| Endpoint | روش | توضیح | هدرهای مهم |
|---------|-----|-------|--------------|
| `POST /api/local-agent/artifacts` | `application/json` | متادیتا: `project_key`, `commit_hash`, `branch`, `checksum_sha256`, `size_bytes`, `required_paths`, `publish_job_id` | `Authorization: Bearer <agent_token>`, `X-Deployment-Server-Id`, `X-Deployment-Timestamp`, `X-Deployment-Nonce`, `X-Deployment-Signature` |
| `POST /api/local-agent/artifacts/{id}/content` | `application/octet-stream` | **Zip** فایل. | همان هدرها با `Content-Type: application/octet-stream` |
| `GET /api/agent/artifacts/{id}/download` | `Accept: application/octet-stream` | دانلود Artifact جهت نصب بر سرور. | `Authorization: Bearer <server_agent_token>`, `X-Deployment-Server-Id` |
| `GET /api/agent/jobs/next` | `GET` | دریافت یک **publish job** از سرور. | همان هدرهای **agent** |

> **دستور زیر** برای تست دستی در محیط توسعه:
> ```bash
> curl -X POST -H "Authorization: Bearer $(cat local-agent/config.php | grep agent_token | cut -d'=' -f2 | tr -d " '")" \
>  -H "X-Deployment-Server-Id: $(cat local-agent/config.php | grep local_agent_id | cut -d'=' -f2 | tr -d " '")" \
>  -H "Content-Type: application/json" \
>  -d '{"project_key":"deployment-system","commit_hash":"$(git rev-parse HEAD)","branch":"master","checksum_sha256":"<sha>"}' \
>  http://deployment-system.test/service/public/api/local-agent/artifacts
> ```

## مستندات مرتبط
- [Architecture Overview](docs/ARCHITECTURE.md)
- [Artifact Deployment Standard](docs/ARTIFACT_DEPLOYMENT.md)
- [Provider‑Neutral Release Contract](docs/PROVIDER_NEUTRAL_RELEASE_CONTRACT.md)
- [Release Preparation Tool](deployment/readme.md) – برای ساخت Release دستی.

## نکات امنیتی
- **پایگاه داده** محدود به `database/database.sqlite` در حالت read‑only. تمام توکن‌ها در جدول `agents` و `local_agents` ذخیره می‌شوند؛ بدون رمز عبور یا کلید خصوصی در فایل.
- **HMAC** برای تمام درخواست‌ها با `signing_secret` محاسبه می‌شود؛ باید سِیفِت راست و بدون تکرار در زمان مختلف باشد.
- **تغییرات در مستندات** (مانند اضافه کردن `sentinel.file_verification_enabled` به config) **مقدار** `true` را در فایل `deployment/config.php` یا `config/deployment.php` اضافه کنید، سپس یک commit ساده کنید؛ اگر قصد دارید آن را فعال کنید، commit را با علامت `feat(sentinel)` یا مشابه برچسب بزنید.

> **ذکر**: برای بارگذاری Artifact به سطح production نیاز دارید تا `Server Agent` روی آن سرور ثبت شده و `deployment-system` در داشبورد تنظیمات `auto_deploy = true` باشد.

## پیوست‌ها
- `README.md` – خلاصه‌ی نصب و راه‌اندازی.
- `deployment/tests/run.php` – تست ابزار `release-prepare.php`.

**به یاد داشته باشید:** این مستند فقط برای توضیح گردش کار طراحی شده و تغییرات کدی در repo خودتان به‌عنوان بخشی از عملیات تست یا پیاده‌سازی، باید در مراحل بعدی (پس از ایجاد مجوز) صورت بگیرد.
