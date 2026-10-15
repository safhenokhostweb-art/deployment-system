**DESIGN_REFINED_FOR_FURTHER_REVIEW — IMPLEMENTATION_READY=NO**

D2 قرارداد هویت، authentication، session binding و authority را روشن‌تر می‌کند. C1 و C2 در سطح قرارداد **تا حدی رفع ابهام شده‌اند**؛ اثبات عملیاتی آن‌ها، C3 و H1–H4 همچنان باز است. transport پیشنهادی در این مرحله **Loopback TCP با mutual TLS، به‌صورت provisional** است.

هفت کلاس production فقط‌خواندنی بررسی شدند و با نسخهٔ D1 یکسان‌اند. هیچ فایل، validator یا API تغییر نکرد. PHP، تست، شبکه، host probe یا کد native اجرا نشد. مشخصات زیر طراحی پیشنهادی‌اند؛ وجود یا سازگاری implementation رمزنگاری و Windows در محیط فعلی تأیید نشده است.

**۱. دامنهٔ تهدید**

| دسته                   | دامنه                                                                                                                                                                |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| داخل دامنه             | کاربر محلی بدون privilege، PHP compromised، worker malicious، endpoint جعلی، credential سرقت‌شده یا stale، replay، policy جعلی، crash و restart                      |
| توان مهاجم داخل دامنه  | ارسال بایت دلخواه، نگهداری connection، تغییر فایل‌های متعلق به خودش، استفاده از credential قابل‌دسترسی برای principal خودش، تأخیر یا قطع پاسخ و تلاش برای exhaustion |
| خارج دامنهٔ فعلی       | administrator، kernel یا hypervisor compromised؛ تغییر trust root توسط administrator مجاز؛ rollback کامل disk/VM توسط مهاجم privileged                               |
| نیازمند OS enforcement | جداسازی principals، حفاظت کلیدها و store، process/handle access، containment و جلوگیری از تغییر binary                                                               |
| نیازمند آزمون آینده    | mutual authentication، channel binding، credential rotation، crash consistency، bounded I/O و containment واقعی                                                      |

PHP compromised می‌تواند از credential خودش در scope مجاز استفاده کند. authentication نباید این واقعیت را پنهان کند؛ authorization محدود و containment باید خسارت را محدود کنند. هیچ ادعای مقاومت در برابر administrator یا kernel compromise مطرح نمی‌شود.

**۲. مدل principals در Windows**

نام‌های زیر نقش منطقی‌اند؛ account یا SID واقعی در این مرحله ایجاد یا انتخاب نشده است.

| Principal                     | دسترسی لازم                                                                          | storage و secrets                                                      | دسترسی ممنوع                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `PHP_CLIENT`                  | اتصال به endpoint Controller؛ خواندن metadata عمومی credential خودش                  | کلید client فقط برای همین principal؛ بدون دسترسی به کلید Controller    | نوشتن policy/authority، journal، worker supervision و تأیید cleanup                   |
| `CONTROLLER_SERVICE`          | خواندن policy/credential state معتبر؛ مالکیت session، journal، process و Job handles | کلید server و journal محافظت‌شده؛ high-water marks متعلق به Controller | کلید امضای Authority، تغییر خودسرانهٔ grant و دسترسی عملیاتی خارج observation         |
| `OBSERVATION_WORKER`          | یک channel محدود و منابع لازم برای observation تعیین‌شده                             | بدون secret؛ فقط handleهای allowlisted                                 | policy store، credential store، journal، process Controller، network و child creation |
| `POLICY_CREDENTIAL_AUTHORITY` | issuance، امضا، revocation و publication                                             | مالک signing key و رکوردهای authority                                  | استفاده از ورودی PHP به‌عنوان اثبات هویت یا cleanup                                   |

Controller مالک worker process و handleهای supervision است. worker فقط process محدودشده است و مالک admission یا reservation نیست.

Windows service account به‌تنهایی isolation کافی ایجاد نمی‌کند. ACL فایل، registry، IPC و process object، token privileges، inheritance و امکان دسترسی principals دیگر باید مستقل بررسی شوند. دسترسی secret توسط process دیگری با همان principal نیز باید در threat model deployment لحاظ شود.

**۳. مرزهای اعتماد**

| عبور از مرز            | دادهٔ مجاز                                  | مرجع اعتماد                                          |
| ---------------------- | ------------------------------------------- | ---------------------------------------------------- |
| PHP → Controller       | درخواست canonical و شناسه‌های resource      | channel احرازشده؛ JSON identity اختیار ایجاد نمی‌کند |
| Authority → Controller | policy، credential state و epochهای امضاشده | protected trust root و rollback checks               |
| Controller → Worker    | یک operation و resource binding محدود       | state داخلی Controller                               |
| Worker → Controller    | نتیجهٔ observation                          | ورودی غیرقابل‌اعتماد تا اعتبارسنجی و بررسی lifecycle |
| OS → Controller        | process/job/handle/time evidence            | adapter native که بعداً باید تأیید شود               |
| Controller → PHP       | پاسخ correlated و diagnostics محدود         | همان channel احرازشده؛ پاسخ، operational token نیست  |

فیلدهای `trusted`، `verified`، `cleanupCertain` یا `vmStartAuthorized` در پیام client پذیرفته نمی‌شوند.

**۴. قرارداد mutual authentication مستقل از transport**

سه مرحله از یکدیگر جدا هستند:

- `TRANSPORT_AUTHENTICATION`: اثبات possession کلید معتبر و هویت دو peer.
- `APPLICATION_AUTHORIZATION`: تطبیق credential معتبر با application، epochها، grant و policy جاری.
- `SESSION_BINDING`: اتصال session صادرشده به همان connection، هویت‌ها و Controller instance.

adapter transport باید context غیرقابل‌تغییر و داخلی تولید کند، شامل:

```
controller_id
controller_instance_id
application_id
credential_id
credential_epoch
authorization_epoch
transport_binding
```

این context از پارامترهای PHP یا JSON ساخته نمی‌شود. credential key identity از handshake رمزنگاری‌شده به رکورد Authority نگاشت می‌شود؛ `authorization_epoch` از state معتبر Authority/policy می‌آید.

شرایط لازم:

1. client، server identity موردانتظار را با protected trust configuration کنترل کند.
2. server، client credential و possession کلید را کنترل کند.
3. هر دو سمت version/profile موردانتظار را enforce کنند؛ fallback خودکار ممنوع باشد.
4. Controller expiry، revocation، epochها و policy را قبل از session issuance دوباره بررسی کند.
5. session به connection فعلی متصل شود؛ reconnect به session قبلی attach نشود.
6. rotation یا revocation، sessionهای credential قبلی را invalidate کند.
7. Controller instance در هر restart عوض شود؛ sessionهای instance قبلی پذیرفته نشوند.

برای profile پیشنهادی TLS، mutual TLS بدون early data و، در profile اولیه، بدون session resumption پیشنهاد می‌شود. تنظیمات دقیق certificate validation، algorithms و channel binding باید در D3 تأیید شوند؛ صرف وجود TLS کافی نیست.

Nonceهای client و Controller، هرکدام ۳۲ بایت تصادفی رمزنگاری‌شده و با نمایش ۶۴ کاراکتر hex lowercase هستند. `controller_instance_id` نیز identifier تازهٔ ۳۲‌بایتی است.

transcript binding پیشنهادی:

```
handshake_digest =
SHA256(
    ASCII("sentinel-model-b-handshake-1\n")
    || canonical_json({
         "challenge": AUTH_CHALLENGE,
         "hello": HELLO,
         "transport_binding": transport_binding
       })
)
```

`transport_binding` مقدار ۳۲‌بایتی وابسته به channel رمزنگاری‌شده است؛ تولید آن باید به primitive استاندارد و profile مصوب transport متکی باشد، نه nonce یا connection ID عمومی.

`AUTH_CONFIRM` فقط تأیید transcript روی channel قبلاً احرازشده است؛ hash عمومی به‌تنهایی authentication ایجاد نمی‌کند. دسترسی PHP و Controller به channel binding سازگار هنوز **CRYPTOGRAPHIC_FEASIBILITY_UNVERIFIED** است.

**۵. مقایسه و تصمیم transport**

| معیار                   | Named Pipe                                   | Loopback TCP + mTLS                                                            |
| ----------------------- | -------------------------------------------- | ------------------------------------------------------------------------------ |
| سازگاری PHP             | adapter قابل‌اثبات هنوز مشخص نیست            | قابلیت TLS در client باید بررسی شود؛ سازگاری profile و binding هنوز تأیید نشده |
| mutual authentication   | pipe/DACL کافی نیست؛ لایهٔ رمزنگاری لازم است | client/server certificate authentication                                       |
| endpoint spoofing       | نام pipe کافی نیست                           | localhost و port کافی نیست؛ server identity باید کنترل شود                     |
| provisioning و rotation | DACL و هویت رمزنگاری هر دو لازم‌اند          | trust store، certificate و key lifecycle لازم‌اند                              |
| access control          | DACL امکان محدودسازی principal را می‌دهد     | حفاظت credential و محدودسازی listener؛ نبود DACL معادل pipe باید لحاظ شود      |
| bounded I/O             | نیازمند adapter native bounded               | handshake/read/write/idle deadlines لازم‌اند                                   |
| پیچیدگی                 | integration و semantics Windows بیشتر        | PKI و TLS configuration بیشتر                                                  |
| testability             | تست principals و spoofed pipe لازم است       | تست impostor endpoint، stale certificate و slow connection لازم است            |

**ترجیح D2: Loopback TCP با mTLS، provisional.** این تغییر نسبت به candidate D1 به‌دلیل مسیر روشن‌تر برای client interoperability است؛ یک استنباط طراحی است، نه نتیجهٔ compatibility test. listener باید فقط روی loopback مصوب bind شود و هر اتصال همچنان احراز هویت شود.

```
TRANSPORT_DECISION=PROVISIONAL
```

**۶. قراردادهای wire پیشنهادی**

قواعد مشترک Protocol V1 حفظ می‌شوند:

- frame: طول چهار‌بایتی unsigned big-endian، سپس payload.
- payload: از ۱ تا 16384 بایت؛ حداکثر عمق container برابر ۶.
- JSON canonical با key order ASCII؛ duplicate key، coercion، trailing bytes و unknown field رد شوند.
- اعداد integer غیرمنفی و حداکثر `2147483647`؛ زمان wall-clock همان string دقیقاً ۱۳ رقم است.
- identifier: الگوی موجود `[a-z][a-z0-9_-]{0,63}`.
- digest، nonce و شناسهٔ opaque: دقیقاً ۶۴ کاراکتر hex lowercase.
- هیچ raw path، secret، stack trace یا command output وارد wire نشود.

در جدول زیر **تمام فیلدهای فهرست‌شده required هستند و هر فیلد اضافی forbidden است**. همهٔ پیام‌ها `protocol_version:int=1` و `message_type:string` مطابق نام پیام دارند.

| پیام                   | فیلدهای افزوده بر دو فیلد مشترک                                                                                                                                                                                                                                                     |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `HELLO`، موجود         | `client_nonce:H`                                                                                                                                                                                                                                                                    |
| `AUTH_CHALLENGE`       | `controller_id:I`, `controller_instance_id:H`, `controller_nonce:H`, `application_id:I`, `credential_id:I`, `credential_epoch:P`, `authorization_epoch:P`, `auth_profile:string="sentinel-model-b-auth-1"`                                                                          |
| `AUTH_CONFIRM`         | `handshake_digest:H`                                                                                                                                                                                                                                                                |
| `SESSION`              | `session_id:H`, `client_nonce:H`, `handshake_digest:H`, `controller_id:I`, `controller_instance_id:H`, `application_id:I`, `credential_id:I`, `credential_epoch:P`, `authorization_epoch:P`, `policy_version:P`, `policy_digest:H`, `scope_digest:H`, `issued_at:T`, `expires_at:T` |
| `SESSION_REJECT`       | `client_nonce:H`, `reason_code:E`                                                                                                                                                                                                                                                   |
| `OBSERVE`، موجود       | schema فعلی بدون تغییر                                                                                                                                                                                                                                                              |
| `OBSERVATION_RESPONSE` | `controller_instance_id:H`, `session_id:H`, `request_id:H`, `sequence:P`, `request_digest:H`, `policy_version:P`, `policy_digest:H`, `observed_at:T`, `expires_at:T`, `observation:O`                                                                                               |
| `ERROR`                | `controller_instance_id:H`, `session_id:H`, `request_id:H`, `sequence:P`, `reason_code:E`, `admitted:bool`                                                                                                                                                                          |
| `SESSION_INVALIDATED`  | `controller_instance_id:H`, `session_id:H`, `reason_code:E`                                                                                                                                                                                                                         |

تعریف نمادها: `H` hex ۶۴، `I` identifier، `P` integer مثبت در محدودهٔ codec، `T` timestamp سیزده‌رقمی، `E` enum بسته و `O` object بسته.

ترتیب handshake:

```
Authenticated channel
→ HELLO
→ AUTH_CHALLENGE
→ AUTH_CONFIRM
→ SESSION یا SESSION_REJECT
```

هر connection فقط یک handshake و یک session دارد. duplicate یا out-of-order handshake باعث close می‌شود. Controller هویت‌های challenge را از context معتبر استخراج می‌کند؛ client آن‌ها را با metadata معتبر خودش تطبیق می‌دهد.

شرایط `SESSION`:

- nonce و transcript معتبر باشند.
- grant فعلی معتبر و non-revoked باشد؛ epochها دقیقاً برابر باشند.
- policy معتبر باشد و دست‌کم یک operation/resource مجاز باقی بماند.
- session ID را Controller تولید کند؛ client نمی‌تواند آن را انتخاب کند.
- lifetime حداکثر ۶۰ ثانیه و از پایان اعتبار policy/grant فراتر نرود.

`OBSERVATION_RESPONSE` به درخواست pending همان channel و instance متصل است. `request_digest` برابر SHA-256 بایت‌های canonical درخواست پذیرفته‌شده است. `observation` با operation ثبت‌شدهٔ همان درخواست انتخاب می‌شود؛ caller نمی‌تواند نوع پاسخ را عوض کند.

schemaهای پیشنهادی observation:

| operation                | فیلدهای دقیق `observation`                                                                                                |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| `OBSERVE_FILESYSTEM`     | `root_exists:bool`, `volume_kind:FIXED\|REMOVABLE\|NOT_DIRECTORY`, `filesystem:NTFS\|OTHER`, `reparse_point_present:bool` |
| `OBSERVE_FREESPACE`      | `bytes_free:D`, `bytes_total:D`                                                                                           |
| `OBSERVE_SERVICE_STATUS` | `present:bool`, `state:RUNNING\|STOPPED\|PENDING\|null`                                                                   |

`D` string دهدهی canonical با حداکثر ۲۰ رقم و مقدار حداکثر `18446744073709551615` است؛ این انتخاب از محدودیت integer فعلی codec برای ظرفیت storage جلوگیری می‌کند. leading zero جز برای `"0"` ممنوع و `bytes_free <= bytes_total` الزامی است.

برای service غایب، `state=null` الزامی است. observation نامعلوم یا تأییدنشده باید `ERROR` تولید کند؛ false یا صفر جایگزین UNKNOWN نشود. معنای native این measurementها و identity منابع همچنان H4 است.

پاسخ فقط روی channel زندهٔ احرازشده معتبر است؛ receipt قابل‌انتقال یا token مجوز عملیاتی ایجاد نمی‌شود. expiration پاسخ از receipt TTL، session، policy و grant تجاوز نکند.

دسته‌های بستهٔ خطا:

```
UNSUPPORTED_PROTOCOL
AUTHENTICATION_FAILED
AUTHORIZATION_DENIED
INVALID_POLICY
MALFORMED_REQUEST
REPLAY_DETECTED
SESSION_EXPIRED
SESSION_INVALIDATED
OBSERVATION_UNAVAILABLE
WORKER_TIMEOUT
CLEANUP_UNCERTAIN
CONTROLLER_UNAVAILABLE
```

خطای پیش از authentication با close محدود و بدون diagnostics داخلی پاسخ داده شود. `SESSION_REJECT` فقط برای HELLO معتبر و correlated است. اگر request correlation قابل‌استخراج معتبر نیست، `ERROR` فرضی ساخته نشود؛ connection بسته شود.

`admitted` از journal Controller می‌آید، نه caller. مقدار false یا دریافت خطا نیز مجوز retry خودکار ایجاد نمی‌کند.

این schemaها **WIRE_CONTRACT_DEFINED برای بررسی D2** هستند. validator فعلی آن‌ها را نمی‌پذیرد و تغییری نکرده است.

**۷. lifecycle جلسه و restart**

```
UNAUTHENTICATED
→ TRANSPORT_AUTHENTICATED
→ HANDSHAKE_CONFIRMED
→ CREATED
→ ACTIVE
→ INVALIDATED / EXPIRED / CLOSED
```

حالت terminal به ACTIVE بازنمی‌گردد. admission فقط در ACTIVE و پس از بررسی تمام bindings انجام می‌شود.

sequence از ۱، سقف ۱۰۲۴ و یک outstanding حفظ می‌شود. scope digest همان ۱۲ فیلد A2 است؛ occupancy وارد آن نمی‌شود. policy version/digest مستقل از scope digest به session متصل‌اند.

در restart، Controller instance جدید صادر می‌شود؛ sessionهای قبلی نامعتبرند و handshake تازه لازم است. درخواست پذیرفته‌شدهٔ قبلی redispatch نمی‌شود. recovery reservation از journal و cleanup evidence انجام می‌شود، نه از ادعای client.

**۸. authority policy**

Authority مستقل باید policy را صادر کند. Controller فقط policy با signature معتبر، schema صحیح، validity معتبر و نسخهٔ قابل‌قبول را publish کند.

قرارداد digest پیشنهادی:

```
policy_digest = SHA256(canonical_json(policy بدون فیلد policy_digest))
```

امضا باید domain، authority/key identity، algorithm profile و policy کاملِ دارای digest را پوشش دهد. profile امضای اولیهٔ پیشنهادی `Ed25519` است؛ پشتیبانی کتابخانه، key handling و verification آن هنوز تأیید نشده است. algorithm از یک allowlist مصوب می‌آید و caller امکان negotiation ضعیف‌تر ندارد.

trust root و verification keys خارج از اختیار PHP/worker نگهداری شوند. کلید امضای Authority در اختیار Controller نباشد.

`root_registry` و `service_registry` باید به resource descriptors محافظت‌شده متصل باشند. descriptor باید resource generation و identity digest همان policy را تأمین کند. PHP فقط ID می‌فرستد؛ انتخاب path، binary یا service واقعی از protected registry انجام می‌شود.

نسخهٔ پایین‌تر رد شود. نسخهٔ برابر فقط برای policy کاملاً یکسان قابل قبول است؛ conflict رد شود. تغییر policy، sessionهای قبلی را invalidate می‌کند حتی اگر A2 یکسان باشد.

**۹. authority credential و rollback**

رکورد معتبر credential حداقل این bindingها را دارد:

```
credential_id
application_id
credential_epoch
authorization_epoch
public_key_identity
valid_from
valid_until
revoked
authority_state_version
```

Controller رکورد را از publication امضاشدهٔ Authority می‌گیرد؛ certificate یا JSON client به‌تنهایی منبع epoch و authorization نیست.

rotation کلید، credential epoch را افزایش دهد. تغییر مجوز یا revocation، authorization epoch را افزایش دهد و state قبلی را نامعتبر کند. publication policy و credential state باید coherent باشد؛ mismatch یا نبود snapshot سازگار باعث توقف admission شود.

rollback protection برای مهاجم غیرprivileged شامل:

- publication امضاشده؛
- high-water mark durable برای نسخهٔ authority، policy و epochها؛
- ACL و principal isolation؛
- رد conflict و rollback.

ACL به‌تنهایی در برابر rollback privileged کل storage کافی نیست. این مهاجم در دامنهٔ فعلی خارج scope است؛ اگر بعداً وارد scope شود، anchor خارج از storage قابل rollback لازم خواهد بود.

**۱۰. رفتار fail-closed**

| رخداد                                             | رفتار                                                                       |
| ------------------------------------------------- | --------------------------------------------------------------------------- |
| handshake نامعتبر، endpoint ناشناخته یا downgrade | close؛ session صادر نشود                                                    |
| credential expired/revoked یا epoch mismatch      | session rejection یا invalidation؛ نتیجهٔ outstanding رد شود                |
| policy mismatch                                   | admission رد و session bound قدیمی invalidate شود                           |
| session نامعتبر                                   | بدون attach یا resume؛ handshake تازه                                       |
| replay یا sequence gap                            | درخواست اجرا نشود؛ تاریخچه حفظ و session terminal شود                       |
| lost response                                     | درخواست مصرف‌شده باقی بماند؛ بدون redispatch خودکار                         |
| partial frame                                     | فقط تا deadline محدود منتظر بماند؛ truncation/error decoder را terminal کند |
| disconnect                                        | session بسته؛ reservation outstanding تا cleanup معتبر حفظ شود              |
| Controller restart                                | instance جدید؛ sessionهای قبلی نامعتبر؛ recovery مستقل لازم                 |
| cleanup نامعلوم                                   | `CLEANUP_UNCERTAIN` latch حفظ شود؛ caller نمی‌تواند آن را پاک کند           |

رسیدن `AUTH_CONFIRM`، response، timeout یا reconnect هیچ‌کدام evidence پایان worker نیستند.

**۱۱. بررسی adversarial قرارداد**

| خطر طراحی                | اصلاح حداقلی قرارداد                                                                    |
| ------------------------ | --------------------------------------------------------------------------------------- |
| identity confusion       | نگاشت کلید احرازشده به application/credential از Authority؛ عدم اعتماد به labelهای JSON |
| credential substitution  | binding key identity، credential ID و epoch در context و transcript                     |
| downgrade                | profile/version ثابت؛ بدون fallback                                                     |
| replay میان connectionها | channel binding، nonce تازه و session اختصاصی connection                                |
| replay میان instanceها   | instance تازه و invalidation sessionهای قبلی                                            |
| policy rollback          | signature و high-water mark؛ conflict detection                                         |
| session fixation         | session ID فقط توسط Controller                                                          |
| confused deputy          | operation و target فقط از allowlist و protected resource registry                       |
| cleanup جعلی             | حذف lifecycle authority از wire client؛ تأیید مستقل supervisor                          |
| resource substitution    | binding generation/identity به policy و بررسی native آینده                              |

این موارد خطرهای طراحی‌اند که در قرارداد پیشنهادی کنترل شده‌اند؛ اثبات implementation یا نبود آسیب‌پذیری native محسوب نمی‌شوند.

**۱۲. blockerهای باقی‌مانده**

- **C1 — PARTIALLY_REFINED:** mutual-auth و session binding تعریف شدند؛ transport profile، PHP compatibility و channel-binding evidence باز است.
- **C2 — PARTIALLY_REFINED:** authority، digest، issuance، epoch و rollback scope تعریف شدند؛ provisioning، signature verification و publication اتمیک هنوز اثبات نشده‌اند.
- **C3 — OPEN:** containment و cleanup native.
- **H1 — OPEN:** trusted clock و adjustment policy.
- **H2 — OPEN:** journal durability و crash/restart recovery.
- **H3 — OPEN:** bounded I/O و adapter compatibility.
- **H4 — OPEN:** resource identity، binary integrity و path/reparse مقاومت.

بنابراین همچنان ۳ blocker بحرانی و ۴ blocker high برای implementation وجود دارد.

**۱۳. محدودهٔ پیشنهادی D3**

D3 بهتر است همچنان design/evidence-only باشد و این هشت پرسش را حل کند:

1. انتخاب دقیق accounts، SIDs و access matrix.
2. سازگاری client PHP با mutual TLS و channel binding.
3. profile دقیق TLS، certificates و signature implementation.
4. provisioning و publication coherent Authority state.
5. منبع ساعت معتبر و سیاست rollback/adjustment.
6. journal و قواعد crash/restart reconciliation.
7. primitives containment، cleanup و bounded I/O روی Windows هدف.
8. native resource/binary identity و کنترل TOCTOU/reparse.

در D3 باید schemaهای پیشنهادی نیز adversarial review شوند؛ به‌ویژه observation payloadها، policy digest و رفتار response/invalidation هم‌زمان. هیچ endpoint یا validator جدیدی پیش از تصویب این قراردادها ایجاد نشود.

```
PHASE=MODEL_B_TRUST_IDENTITY_PROTOCOL_DESIGN_2

FILES_CREATED=0
FILES_MODIFIED=0

THREAT_SCOPE_DEFINED=YES
PRINCIPAL_MODEL_DEFINED=YES_LOGICAL_ROLES_OS_ENFORCEMENT_UNVERIFIED
TRUST_BOUNDARIES_DEFINED=YES

MUTUAL_AUTH_CONTRACT=AUTHENTICATION_DESIGN_DEFINED_CRYPTOGRAPHIC_FEASIBILITY_UNVERIFIED
TRANSPORT_DECISION=PROVISIONAL_LOOPBACK_TCP_MUTUAL_TLS
WIRE_CONTRACT_STATUS=WIRE_CONTRACT_DEFINED_PROPOSED_NOT_IMPLEMENTED

POLICY_AUTHORITY_STATUS=CONTRACT_DEFINED_PROVISIONING_UNVERIFIED
CREDENTIAL_AUTHORITY_STATUS=CONTRACT_DEFINED_LIFECYCLE_ENFORCEMENT_UNVERIFIED
ROLLBACK_PROTECTION_STATUS=DEFINED_FOR_UNPRIVILEGED_SCOPE_DURABILITY_UNVERIFIED

C1_STATUS=PARTIALLY_REFINED_CONTRACT_DEFINED_IMPLEMENTATION_EVIDENCE_PENDING
C2_STATUS=PARTIALLY_REFINED_AUTHORITY_CONTRACT_DEFINED_ENFORCEMENT_PENDING
C3_STATUS=OPEN

H1_STATUS=OPEN
H2_STATUS=OPEN
H3_STATUS=OPEN
H4_STATUS=OPEN

CRITICAL_DESIGN_BLOCKERS=3
HIGH_DESIGN_BLOCKERS=4
UNRESOLVED_QUESTIONS=8

ARCHITECTURE_DECISION=DESIGN_REFINED_FOR_FURTHER_REVIEW
IMPLEMENTATION_READY=NO
NEXT_SAFE_ACTION=SEPARATELY_AUTHORIZED_D3_FEASIBILITY_AND_CONTRACT_REVIEW

NATIVE_CONTROLLER_IMPLEMENTED=NO
VM_START_AUTHORIZED=NO
VM_CREATED=NO
VM_STARTED=NO
MYSQL_STARTED=NO
MIGRATIONS_EXECUTED=NO
FULL_PROOF_EXECUTED=NO
REAL_LOCAL_RUNTIME_VERIFIED=NO
```

گزارش مستقیماً ارائه شد؛ هیچ سند یا فایل ایجاد نشد و implementation آغاز نشد.