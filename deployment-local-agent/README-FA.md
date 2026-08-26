# Local Development Publisher Agent — نصب

## پیش‌نیاز

- Windows با PHP و Git در PATH (روی Laragon هر دو معمولاً از قبل نصب هستند).
- یک حساب Admin/Operator در Deployment Hub که به شما اجازه ساخت Registration Token بدهد.

## نصب (یک‌بار)

۱. در پنل به **Local Agents** بروید و **Create Registration Token** را بزنید. مقدار `registration_id` و `registration_token` را کپی کنید — فقط همین یک‌بار نمایش داده می‌شوند.

۲. یک PowerShell با دسترسی **Administrator** باز کنید و در همین پوشه اجرا کنید:

```powershell
.\install.ps1 -HubUrl https://deployment.example.test -RegistrationId <registration_id> -RegistrationToken <registration_token>
```

این دستور: فایل‌های Agent را در `C:\ProgramData\DeploymentLocalAgent` کپی می‌کند، در Hub ثبت‌نام می‌کند، یک Task Scheduler برای اجرای خودکار Agent هنگام ورود به ویندوز می‌سازد، و آن را همین حالا اجرا می‌کند.

۳. به پنل برگردید (**Local Agents → Pending**) و این Agent را **Approve** کنید. مقادیر `agent_token` و `signing_secret` فقط همین یک‌بار نمایش داده می‌شوند — آن‌ها را کپی کنید.

۴. فایل زیر را باز کنید و آن دو مقدار را جایگزین `CHANGE_ME_AGENT_TOKEN` / `CHANGE_ME_SIGNING_SECRET` کنید:

```
C:\ProgramData\DeploymentLocalAgent\agent\config.php
```

۵. برای هر پروژه‌ای که می‌خواهید از این سیستم Publish کنید، در پنل (صفحه همین Local Agent) یک **Project Mapping** اضافه کنید (مسیر محلی مثلاً `C:\laragon\www\saas-platform`). همین یک مرحله کافی است — دیگر نیازی نیست چیزی در `config.php` ویرایش کنید؛ Agent این فهرست را خودش از Hub دانلود و در همان‌جا Cache می‌کند (بخش `projects` داخل `config.php` فقط یک Fallback قدیمی برای زمانی است که هرگز به Hub دسترسی نداشته و هیچ Cache معتبری هم ندارد).

۶. در پنل، وضعیت این Agent باید **Online** شود (پس از حداکثر یک دور Poll). برای اعمال فوری یک نگاشت پروژه‌ی تازه بدون صبر کردن برای دوره همگام‌سازی بعدی (پیش‌فرض هر ۵ دقیقه)، دکمه **«همگام‌سازی فوری تنظیمات»** را در همان صفحه بزنید.

## استفاده روزانه

از این به بعد نیازی به اجرای دستی هیچ چیزی نیست — Agent با ورود به ویندوز خودکار اجرا می‌شود، تغییرات پروژه‌های نگاشت‌شده را زیر نظر دارد، و بر اساس Deployment Mode (Manual/Approval/Auto) هر پروژه رفتار می‌کند.

## بروزرسانی

وقتی نسخه جدیدی از این بسته منتشر شد:

```powershell
.\upgrade.ps1
```

فقط فایل‌های خود Agent را جایگزین می‌کند — `config.php` (و اعتبارنامه‌های داخل آن) دست‌نخورده می‌ماند.

## ارتقای رسمی Linux

نصب Linux که در `/opt/deployment-agent` اجرا می‌شود با اسکریپت رسمی زیر
ارتقا داده می‌شود. این دستور را از Source Workspace امن Deployment System
اجرا کنید، نه از مسیر production:

```bash
sudo /opt/deployment-agent/workspaces/deployment-system/deployment-local-agent/upgrade-linux.sh \
  --expected-version 1.4.7
```

اسکریپت فقط `agent.php`، `heartbeat-watchdog.php` و `lib/*.php` را فعال
می‌کند. پیش از فعال‌سازی syntax تمام فایل‌های PHP را بررسی و یک backup
timestamped می‌سازد؛ `config.php`، credentialها، `storage/` و project cache
هرگز کپی یا بازنویسی نمی‌شوند. در خطا runtime قبلی و وضعیت service بازیابی
می‌شود. پس از bootstrap اولیه، ارتقاهای بعدی از دکمه Upgrade در Hub ارسال
می‌شوند و تا زمانی که heartbeat احراز هویت‌شده نسخه مورد انتظار را گزارش
نکند موفق محسوب نمی‌شوند.

## حذف

```powershell
.\uninstall.ps1              # فقط سرویس را متوقف می‌کند، config.php باقی می‌ماند
.\uninstall.ps1 -RemoveFiles  # حذف کامل فایل‌های نصب
```

در هر دو حالت، برای غیرفعال کردن کامل باید از پنل هم این Agent را **Disable** کنید — حذف فایل‌های محلی اعتبارنامه آن را در Hub باطل نمی‌کند.

## سناریوی تست واقعی (Acceptance Test)

برای اطمینان از صحت کامل نصب، این مراحل را روی سیستم واقعی خودتان انجام دهید:

۱. یک فایل کوچک در یکی از پروژه‌های نگاشت‌شده تغییر دهید (مثلاً یک خط به `README.md` اضافه کنید) و ذخیره کنید.
۲. اگر Deployment Mode آن پروژه روی **Approval** است: در پنل باید یک Notification «تغییر محلی منتظر تایید» ظاهر شود (حداکثر پس از یک دور Poll — به‌صورت پیش‌فرض هر ۱۵ ثانیه).
۳. روی صفحه پروژه، دکمه **View Changes** را بزنید، فایل تغییریافته را ببینید، و **Approve Publish** را بزنید.
۴. در دور Poll بعدی، Agent باید `git add`، `git commit`، و `git push` را واقعاً اجرا کند — نتیجه (Commit Hash) در همان صفحه نمایش داده می‌شود.
۵. GitHub باید Webhook موجود پروژه را صدا بزند و یک Deployment جدید در پنل ایجاد شود (این بخش کاملاً مستقل از Local Agent است و بدون تغییر کار می‌کند).
۶. پس از اتمام Deployment، فایل تغییریافته را روی سرور واقعی (`ssh` یا از طریق پنل) بررسی کنید.
۷. برای اطمینان از پایداری پس از Restart ویندوز: سیستم را Restart کنید، دوباره وارد شوید، و در پنل بررسی کنید که Local Agent ظرف چند دقیقه دوباره **Online** شود — بدون هیچ اجرای دستی.

این ۷ مرحله دقیقاً همان چیزی است که این Phase قرار است تضمین کند: از Save کردن یک فایل در VS Code تا دیده‌شدن آن روی Server، بدون هیچ دستور دستی Git/SSH/Composer/Artisan.
