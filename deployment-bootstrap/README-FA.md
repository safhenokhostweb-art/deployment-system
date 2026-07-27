# One-Click Deployment Bootstrap

نصب کامل و خودکار Deployment System روی یک سرور جدید، بدون نیاز به ثبت دستی Server/Agent/Project/Environment/Deployment Target از داشبورد.

## ۱. آپلود فایل ZIP روی سرور

فایل `deployment-bootstrap.zip` را روی سرور Ubuntu آپلود کنید (مثلاً با `scp`):

```bash
scp deployment-bootstrap.zip root@SERVER_IP:/root/
```

## ۲. Extract کردن

```bash
unzip deployment-bootstrap.zip -d /root/deployment-bootstrap
cd /root/deployment-bootstrap
```

## ۳. ویرایش تنظیمات (فقط در صورت نیاز)

اگر مقادیر پیش‌فرض `config.example.env` (آدرس پنل، مسیر Repository، نام Server/Agent/Project و…) با محیط شما یکی است، این مرحله لازم نیست — `install.sh` خودش یک نسخه `config.env` می‌سازد. در غیر این صورت:

```bash
cp config.example.env config.env
nano config.env
```

## ۴. اجرای install.sh

```bash
chmod +x install.sh
sudo ./install.sh
```

این یک دستور، به‌صورت خودکار: Server/Agent/Project/Environment/Deployment Target را در پنل ثبت می‌کند، Agent را نصب و به‌عنوان سرویس systemd فعال می‌کند، Scheduler و Queue Worker را راه‌اندازی می‌کند، و در پایان `verify.sh` را خودش اجرا می‌کند.

## ۵. بررسی نتیجه

خروجی پایانی `install.sh` را بخوانید — شامل وضعیت هر رکورد (ایجاد شد / از قبل موجود بود)، آدرس Webhook و در صورت ایجاد تازه، Webhook Secret است. هر زمان خواستید دوباره وضعیت را بررسی کنید:

```bash
sudo ./verify.sh
```

## ۶. ثبت دستی Webhook در GitHub (فقط اگر GITHUB_TOKEN را خالی گذاشته‌اید)

اگر در `config.env` مقدار `GITHUB_TOKEN` را وارد نکرده‌اید، Webhook خودکار ساخته نمی‌شود — با آدرس و Secret‌ای که `install.sh` یک‌بار نمایش داد، این مراحل را در GitHub انجام دهید:

1. به `Settings → Webhooks → Add webhook` در Repository بروید.
2. **Payload URL**: همان آدرسی که `install.sh` نمایش داد.
3. **Content type**: `application/json`
4. **Secret**: همان مقداری که `install.sh` نمایش داد.
5. **Which events**: فقط `Just the push event`.

## ۷. تست Push

یک تغییر کوچک روی برنچ `master` (یا هر برنچی که در `DEPLOY_BRANCH` تنظیم کرده‌اید) push کنید و در پنل بررسی کنید که یک Deployment جدید برای این Project شروع شده باشد.

---

برای غیرفعال یا حذف کردن نصب، به `uninstall.sh` مراجعه کنید — اجرای آن بدون هیچ آرگومانی (`sudo ./uninstall.sh`) فقط سرویس Agent را غیرفعال می‌کند، بدون هیچ حذف مخربی.
