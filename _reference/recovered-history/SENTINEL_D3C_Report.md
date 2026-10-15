**DESIGN_REFINED_FOR_INDEPENDENT_REVIEW — IMPLEMENTATION_READY=NO**

این گزارش یک candidate یکپارچه برای جایگزینی جدول‌های wire قبلی است. سه ابهام مربوط به ERROR، schemaها و arrival-state در سطح طراحی مشخص می‌شوند. authority publication و send gate همچنان **PARTIALLY_RESOLVED** هستند، زیرا صحت عملیاتی آن‌ها به durability، trusted clock و primitiveهای native وابسته است.

هفت کلاس production فقط‌خواندنی بررسی شدند و با نسخهٔ D3-C یکسان‌اند. D2، D3-A، D3-B و D3-C در گفتگو موجودند؛ سند مستقل و versioned از آن‌ها در اختیار نیست. هیچ فایل، PHP، تست، شبکه یا کد native اجرا نشد.

قواعد زیر source فعلی را تغییر نمی‌دهند: A1، A2 دوازده‌فیلدی، مصرف دائمی replay history، terminal session، مالکیت reservation، latch مربوط به cleanup uncertainty و ممنوعیت automatic redispatch حفظ می‌شوند. پیام‌های جدید هنوز توسط validator فعلی پذیرفته نمی‌شوند.

**۱. D3C-01؛ generation، manifest و freshness**

canonical manifest و envelope امضای D3-B حفظ می‌شوند. تعریف جدید و دقیق digest:

```
M = exact canonical UTF-8 bytes of unsigned manifest
manifest_digest = lowercase_hex(SHA256(M))
```

signature وارد `M` نمی‌شود. `manifest_digest` نیز field داخل خود manifest نیست؛ بنابراین تعریف circular نیست. signature همچنان domain، authority/key/profile و تمام fields manifest را پوشش می‌دهد.

high-water mark متعلق به Authority مشخص است و شامل این state محافظت‌شده می‌شود:

```
authority_id
publication_generation
manifest_digest
canonical_manifest_bytes
policy_version / policy_digest
credential_state_version / credential_state_digest
resource_registry_version / resource_registry_digest
per-credential epoch/key/revocation high-water marks
per-resource generation/identity high-water marks
```

اعداد دهدهی `D` به‌صورت unsigned numeric مقایسه می‌شوند؛ مقایسهٔ لغوی ساده ممنوع است.

| publication ورودی                                          | تصمیم                                                                |
| ---------------------------------------------------------- | -------------------------------------------------------------------- |
| generation پایین‌تر                                        | رد؛ بدون تغییر state فعال                                            |
| generation برابر و manifest canonical کاملاً برابر         | idempotent؛ بدون activation جدید، تمدید lease یا تغییر session       |
| generation برابر و manifest متفاوت                         | conflict؛ رد و latch `AUTHORITY_UNAVAILABLE` تا reconciliation معتبر |
| generation بالاتر، signature و snapshots coherent          | candidate برای commit واحد                                           |
| generation بالاتر با version/epoch/resource state عقب‌رفته | رد؛ generation بالاتر stale state را مشروع نمی‌کند                   |
| generation بالاتر با snapshot مفقود یا digest نامنطبق      | publication فعال نشود                                                |
| journal یا high-water mark نامعلوم                         | بدون انتخاب snapshot قابل‌استفاده؛ fail closed                       |

برابری به **بایت‌های canonical برابر** وابسته است؛ digest برای lookup و integrity است و تنها معیار equality قراردادی نیست.

قیود snapshotها:

- version پایین‌تر ممنوع؛ version برابر مستلزم digest و محتوای برابر است.
- key identity در credential epoch برابر تغییر نمی‌کند.
- epochها کاهش نمی‌یابند؛ revocation/reissuance طبق epoch جدید Authority انجام می‌شود.
- application binding یک credential ID نباید خودسرانه جایگزین شود.
- resource generation کاهش نمی‌یابد؛ generation برابر مستلزم identity برابر است.
- digest epoch vector از credential snapshot بازسازی و با manifest مقایسه می‌شود.
- هر grant با credential/application/epoch متناظر و registry همان publication کنترل می‌شود.
- رکوردهای حذف‌شده، high-water mark و revocation history را پاک نمی‌کنند.

این‌ها قیود profile عملیاتی Authority هستند؛ ورودی‌های fixture موجود بازتعریف نمی‌شوند.

اعتبار زمانی manifest:

```
valid_from = issued_at
0 < valid_until - issued_at <= 10000ms
issued_at <= trusted_now < valid_until
```

future-dated، expired یا interval نامعتبر رد می‌شود. expiry همیشه همان `valid_until` امضاشده است؛ دریافت یا replay، deadline جدید نمی‌سازد.

publication commit:

1. signature، validity، digestها، typed schema و coherence کامل verify شوند.
2. snapshotها در state immutable متعلق به Controller آماده شوند.
3. manifest و high-water marks در transaction durable واحد commit شوند.
4. snapshot فعال و invalidation sessionهای publication قبلی تحت همان state owner اعمال شوند.
5. موفقیت publication فقط پس از تکمیل این ترتیب اعلام شود.

interruption پیش از commit، snapshot جدید را فعال نمی‌کند. interruption پس از commit یا state نامطمئن نیازمند reconciliation است؛ rollback برای ادامهٔ کار ممنوع است. پس از restart، high-water mark بازیابی می‌شود، validity مطلق دوباره کنترل می‌شود و sessionهای قبلی قابل بازیابی نیستند.

سه مفهوم جدا می‌مانند:

- `PUBLICATION_FRESHNESS`: اعتبار snapshot تا deadline امضاشده.
- `REVOCATION_LATENCY`: فاصلهٔ تغییر در Authority تا اعمال publication معتبر در Controller؛ فوری نیست.
- `TRUSTED_CLOCK_EVIDENCE`: پیش‌شرط محاسبهٔ هر دو؛ در این مرحله تأیید نشده است.

**وضعیت D3C-01: PARTIALLY_RESOLVED.** قرارداد comparison مشخص است؛ durability، freshness delivery و trusted time هنوز evidence لازم دارند.

**۲. D3C-02؛ ERROR کامل و correlation امن**

سه سطح correlation تعریف می‌شود:

- **C0:** channel، message یا session قابل اعتماد نیست.
- **CH:** transport احرازشده و HELLO canonical معتبر است؛ session صادر نشده.
- **CR:** channel به session واقعی صادرشده متصل است، `OBSERVE` schema معتبر دارد، session ID دقیقاً همان binding است و state/journal قابل‌اعتمادند.

فقط CR اجازهٔ `ERROR` دارد. CH می‌تواند `SESSION_REJECT` محدود دریافت کند. C0 بدون authoritative response بسته می‌شود. هیچ session ID یا state فرضی ساخته نمی‌شود.

در CR:

```
request_digest = SHA256(exact canonical OBSERVE payload bytes)
admitted = existence of durable admission for this exact tuple:
(session_id, sequence, request_id, request_digest)
```

`admitted` دربارهٔ همان attempt است. request ID تکراری با digest متفاوت، attempt پذیرفته‌شدهٔ جدید نیست؛ اما تاریخچه و reservation قبلی حفظ می‌شوند. journal نامعلوم اجازهٔ ساخت boolean authoritative نمی‌دهد.

categories بسته:

```
UNSUPPORTED_PROTOCOL
MALFORMED_REQUEST
AUTHENTICATION_FAILED
AUTHORIZATION_DENIED
INVALID_POLICY
REPLAY_DETECTED
CONTROLLER_UNAVAILABLE
CLEANUP_UNCERTAIN
OBSERVATION_UNAVAILABLE
WORKER_TIMEOUT
```

diagnosticها دقیقاً union موارد جدول‌های زیرند. خارج از این مجموعه، wire diagnostic جدید تولید نمی‌شود. diagnosticهای fixture و lifecycle داخلی، RPC جدید ایجاد نمی‌کنند.

در جدول، `A` یعنی admitted دقیق tuple؛ `S` یعنی state واقعی موجود. ترتیب guardهای source حفظ می‌شود: جدول مجوز دورزدن guard مقدم نیست. عبارت «notify/close» یعنی در صورت امکان send gate کنترل، frame محدود ارسال و سپس connection بسته شود؛ اگر شرایط gate برقرار نیست، فقط close.

| failure                                                   | category / diagnostic                                                              | correlation و admitted                               | session/connection و رفتار terminal                        |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------------- |
| timeout بالاتر از effective limit                         | `AUTHORIZATION_DENIED / TIMEOUT_LIMIT`                                             | CR؛ false                                            | ACTIVE؛ connection باقی؛ بدون مصرف                         |
| timeout ≤ cleanup budget                                  | `AUTHORIZATION_DENIED / CLEANUP_BUDGET`                                            | CR؛ false                                            | ACTIVE؛ بدون مصرف                                          |
| operation scope                                           | `AUTHORIZATION_DENIED / OPERATION_SCOPE`                                           | CR؛ false                                            | ACTIVE؛ بدون مصرف                                          |
| resource scope                                            | `AUTHORIZATION_DENIED / RESOURCE_SCOPE`                                            | CR؛ false                                            | ACTIVE؛ بدون مصرف                                          |
| concurrency limit                                         | `AUTHORIZATION_DENIED / CONCURRENCY_LIMIT`                                         | CR؛ false                                            | ACTIVE؛ reservation موجود حفظ شود                          |
| grant خارج validity                                       | `AUTHORIZATION_DENIED / GRANT_EXPIRED_OR_NOT_YET_VALID`                            | CR؛ A                                                | INVALIDATED؛ notify/close؛ outstanding حفظ                 |
| grant/credential ناشناخته یا revoked در guard coordinator | `AUTHORIZATION_DENIED / CREDENTIAL_OR_EPOCH_INVALID`                               | CR؛ false برای attempt جدید                          | INVALIDATED؛ notify/close                                  |
| revocation در pure evaluator                              | `AUTHORIZATION_DENIED / GRANT_REVOKED_OR_UNKNOWN`                                  | CR؛ false                                            | state واقعی؛ caller مستقیم evaluator عملیاتی ایجاد نمی‌شود |
| identity/epoch mismatch در evaluator                      | `AUTHORIZATION_DENIED / IDENTITY_OR_EPOCH_MISMATCH`                                | CR؛ false                                            | state واقعی؛ notify/close                                  |
| scope digest mismatch                                     | `AUTHORIZATION_DENIED / SCOPE_DIGEST_MISMATCH`                                     | CR؛ false                                            | invalidate/close در adapter عملیاتی                        |
| policy binding mismatch                                   | `INVALID_POLICY / POLICY_BINDING_MISMATCH`                                         | CR؛ A                                                | INVALIDATED؛ notify/close                                  |
| policy expired                                            | `INVALID_POLICY / POLICY_EXPIRED`                                                  | CR؛ A                                                | INVALIDATED؛ notify/close                                  |
| session ID یا context نامنطبق                             | `AUTHENTICATION_FAILED / SESSION_BINDING_MISMATCH` یا `TRANSPORT_CONTEXT_MISMATCH` | C0 نسبت به request؛ ERROR ممنوع                      | close؛ state session واقعی طبق disconnect حفظ/terminal شود |
| session expired                                           | `AUTHORIZATION_DENIED / SESSION_EXPIRED`                                           | CR؛ A                                                | EXPIRED؛ notify/close                                      |
| session terminal در coordinator                           | `AUTHORIZATION_DENIED / SESSION_INACTIVE`                                          | CR؛ A                                                | terminal باقی؛ notify/close                                |
| session inactive/expired در evaluator                     | `AUTHORIZATION_DENIED / SESSION_INACTIVE_OR_EXPIRED`                               | CR؛ A                                                | state واقعی؛ بدون reactivation                             |
| duplicate دقیق درخواست پذیرفته‌شده                        | `REPLAY_DETECTED / SEQUENCE_OR_REQUEST_ID`                                         | CR؛ true                                             | اگر ACTIVE بود CLOSED؛ notify/close                        |
| sequence gap                                              | `REPLAY_DETECTED / SEQUENCE_OR_REQUEST_ID`                                         | CR؛ false                                            | CLOSED؛ history قبلی حفظ                                   |
| duplicate ID یا ID collision با payload متفاوت            | `REPLAY_DETECTED / SEQUENCE_OR_REQUEST_ID`                                         | CR؛ false برای tuple جدید                            | CLOSED؛ consumption قبلی پاک نشود                          |
| سقف ۱۰۲۴ admission                                        | `AUTHORIZATION_DENIED / REQUEST_LIMIT`                                             | CR؛ false                                            | CLOSED؛ notify/close                                       |
| malformed frame یا JSON                                   | `MALFORMED_REQUEST / SCHEMA_INVALID`، فقط audit                                    | C0؛ admitted صادر نشود                               | close؛ outstanding قبلی بازگردانده نشود                    |
| invalid protocol version                                  | `UNSUPPORTED_PROTOCOL / SCHEMA_INVALID`، audit                                     | C0؛ response authoritative ممنوع                     | close؛ بدون fallback                                       |
| message غیرOBSERVE در phase درخواست                       | `MALFORMED_REQUEST / OBSERVE_REQUIRED`، audit                                      | C0 برای آن attempt                                   | close                                                      |
| worker timeout، رخداد عملیاتی جدید                        | `WORKER_TIMEOUT / WORKER_TIMEOUT`                                                  | CR؛ true                                             | CLOSED؛ latch uncertainty و reservation حفظ                |
| worker unavailable، extension عملیاتی                     | `OBSERVATION_UNAVAILABLE / WORKER_UNAVAILABLE`                                     | CR؛ A از journal                                     | CLOSED؛ اگر cleanup نامعلوم است reservation حفظ            |
| observation نامعلوم/نامعتبر                               | `OBSERVATION_UNAVAILABLE / OBSERVATION_UNAVAILABLE`                                | CR؛ true                                             | نتیجه موفق صادر نشود؛ cleanup مستقل                        |
| service state بدون mapping                                | `OBSERVATION_UNAVAILABLE / SERVICE_STATE_UNREPRESENTABLE`                          | CR؛ true                                             | بدون تبدیل به PENDING/null                                 |
| cleanup uncertain                                         | `CLEANUP_UNCERTAIN / CLEANUP_UNCERTAIN`                                            | CR؛ true                                             | terminal موجود حفظ؛ notify/close؛ reservation باقی         |
| admission زیر latch uncertainty                           | `CLEANUP_UNCERTAIN / ADMISSION_BLOCKED`                                            | CR؛ A                                                | latch و terminal موجود حفظ                                 |
| admission زیر blocker Controller                          | `CONTROLLER_UNAVAILABLE / ADMISSION_BLOCKED`                                       | CR؛ A                                                | state موجود؛ notify/close                                  |
| completion نامنطبق/تکراری داخلی                           | category blocker موجود / `COMPLETION_STATE_UNCERTAIN`                              | فقط event trusted داخلی؛ RPC client نیست             | بدون release؛ CLEANUP_UNCERTAIN تضعیف نشود                 |
| counter state نامعتبر                                     | `CONTROLLER_UNAVAILABLE / CONCURRENCY_STATE_INVALID`                               | trust state از دست رفته؛ ERROR ممنوع                 | close و توقف admission                                     |
| instance/state mismatch                                   | `CONTROLLER_UNAVAILABLE / INSTANCE_OR_STATE_INVALID`                               | اگر CR برقرار نیست ERROR ممنوع                       | close                                                      |
| clock/state invalid در source                             | `CONTROLLER_UNAVAILABLE / CLOCK_OR_STATE_INVALID`                                  | ERROR authoritative ممنوع                            | close، admission/result acceptance متوقف                   |
| clock trust failure عملیاتی                               | `CONTROLLER_UNAVAILABLE / CLOCK_TRUST_FAILURE`، audit                              | ERROR ممنوع                                          | invalidation و close؛ reservation نامطمئن حفظ              |
| journal نامعلوم                                           | `CONTROLLER_UNAVAILABLE / JOURNAL_STATE_UNKNOWN`، audit                            | ERROR ممنوع؛ A نامعلوم                               | fail closed؛ بدون redispatch/release                       |
| Authority conflict/unavailable                            | `CONTROLLER_UNAVAILABLE / AUTHORITY_UNAVAILABLE`                                   | فقط state قبلی معلوم برای control؛ observation ممنوع | notify/close یا صرفاً close                                |
| arrival pipelining violation                              | `MALFORMED_REQUEST / PIPELINING_VIOLATION`، audit                                  | response دوم ساخته نشود                              | close؛ درخواست اول consumed باقی                           |

categories extension برای worker/clock/journal/authority/pipelining، **طراحی جدید** هستند؛ خروجی implementation موجود تلقی نمی‌شوند.

باقی diagnostics موجود برای handshake یا رویدادهای داخلی:

| diagnostic                                                            | category/مسیر مجاز                                                                   |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `FIXTURE_PROVENANCE_INVALID`                                          | `AUTHENTICATION_FAILED`؛ فقط audit fixture؛ native authority از آن ساخته نشود        |
| `SESSION_DUPLICATE_OR_BLOCKED`                                        | `CONTROLLER_UNAVAILABLE`؛ CH rejection یا audit                                      |
| `GRANT_OR_POLICY_UNAVAILABLE`                                         | `INVALID_POLICY`؛ CH rejection                                                       |
| `SESSION_NOT_CREATED`, `ACTIVATION_DENIED`                            | `AUTHORIZATION_DENIED`؛ CH rejection؛ SESSION صادر نشود                              |
| `SESSION_UNKNOWN`                                                     | `CONTROLLER_UNAVAILABLE`؛ بدون ERROR ساختگی                                          |
| `REENTRANT_MUTATION`                                                  | `CONTROLLER_UNAVAILABLE`؛ internal denial؛ client callback ایجاد نشود                |
| `POLICY_DOWNGRADE_OR_CONFLICT`                                        | `INVALID_POLICY`؛ publication audit                                                  |
| `INSTANCE_NOT_CHANGED`                                                | `CONTROLLER_UNAVAILABLE`؛ restart audit                                              |
| `RESERVATIONS_REMAIN`                                                 | `CLEANUP_UNCERTAIN`؛ reconciliation داخلی                                            |
| `DISCONNECTED`, `RESPONSE_LOST`, `CONTROLLER_RESTARTED`               | `CONTROLLER_UNAVAILABLE`؛ notification معتبر یا audit                                |
| `CREDENTIAL_REVOKED`                                                  | `AUTHORIZATION_DENIED`؛ invalidation notification                                    |
| `POLICY_REPLACED`                                                     | `INVALID_POLICY`؛ invalidation notification                                          |
| `TRANSCRIPT_MISMATCH`, `HANDSHAKE_ORDER_INVALID`, `HANDSHAKE_TIMEOUT` | `AUTHENTICATION_FAILED`؛ extension handshake؛ CH rejection یا close                  |
| `CERTIFICATE_INVALID`, `CERTIFICATE_REVOKED`, `EXPORTER_UNAVAILABLE`  | `AUTHENTICATION_FAILED`؛ audit و close؛ channel نامعتبر response authoritative ندارد |

عبارت‌های exception عمومی مانند serialization guard یا `INVALID_POLICY` بدون diagnostic ساختاری، مستقیماً روی wire کپی نمی‌شوند. این مسیرها قبل از فعالیت عملیاتی verify یا به failure داخلی محدود تبدیل می‌شوند.

**وضعیت D3C-02: RESOLVED_AT_DESIGN_LEVEL** برای این candidate؛ conformance mapping هنوز اجرا نشده است.

**۳. D3C-03؛ schema یکپارچهٔ wire**

این جدول جایگزین جدول‌های پیشنهادی D2 است، نه validator موجود.

قواعد نوع و اندازه:

| نماد    | نوع و حداکثر                                             |
| ------- | -------------------------------------------------------- |
| `I`     | ASCII identifier موجود؛ ۱ تا ۶۴ کاراکتر                  |
| `H`     | hex lowercase؛ دقیقاً ۶۴ کاراکتر                         |
| `P`     | JSON integer؛ `1..2147483647`                            |
| `D+`    | decimal string مثبت؛ حداکثر ۲۰ رقم و uint64              |
| `D0`    | decimal string غیرمنفی canonical؛ حداکثر ۲۰ رقم و uint64 |
| `T`     | string دقیقاً ۱۳ رقم؛ UTC epoch milliseconds             |
| `B`     | JSON boolean؛ بدون coercion                              |
| `S`     | `ACTIVE\|INVALIDATED\|EXPIRED\|CLOSED`                   |
| `EC/DC` | category/diagnostic فقط از مجموعه‌های بخش ۲              |

هر field فهرست‌شده required است؛ fields اضافی forbidden هستند. payload هر پیام حداکثر 16384 بایت، عمق حداکثر ۶ و key order canonical است.

تعریف مشترک:

```
C(type):
  protocol_version: integer literal 1
  message_type: exact literal type
```

و correlation مشترک پاسخ درخواست:

```
R:
  controller_instance_id: H
  session_id: H
  request_id: H
  sequence: P
  request_digest: H
  publication_generation: D+
  manifest_digest: H
  policy_version: P
  policy_digest: H
```

مقادیر publication/policy در R مربوط به **binding همان session** هستند؛ در ERROR یا invalidation ادعای فعال‌بودن publication منقضی یا قبلی نمی‌سازند.

| پیام                   | schema دقیق افزوده بر C                                                                                                                                                                                                                                                                                                               | sender، نوع و phase                                                            |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `HELLO`                | `client_nonce:H`                                                                                                                                                                                                                                                                                                                      | client request؛ transport authenticated، handshake شروع‌نشده                   |
| `AUTH_CHALLENGE`       | `auth_profile:"sentinel-model-b-auth-1"`, `controller_id:I`, `controller_instance_id:H`, `controller_nonce:H`, `application_id:I`, `credential_id:I`, `credential_epoch:P`, `authorization_epoch:P`, `publication_generation:D+`, `manifest_digest:H`                                                                                 | Controller response؛ فقط پس از HELLO معتبر                                     |
| `AUTH_CONFIRM`         | `handshake_digest:H`                                                                                                                                                                                                                                                                                                                  | client request؛ دقیقاً پس از challenge                                         |
| `SESSION`              | `session_id:H`, `client_nonce:H`, `handshake_digest:H`, `controller_id:I`, `controller_instance_id:H`, `application_id:I`, `credential_id:I`, `credential_epoch:P`, `authorization_epoch:P`, `publication_generation:D+`, `manifest_digest:H`, `policy_version:P`, `policy_digest:H`, `scope_digest:H`, `issued_at:T`, `expires_at:T` | Controller response؛ فقط پس از activation commit                               |
| `SESSION_REJECT`       | `client_nonce:H`, `controller_instance_id:H`, `error_category:EC`, `diagnostic_code:DC`                                                                                                                                                                                                                                               | Controller response؛ CH، بدون session صادرشده؛ سپس close                       |
| `OBSERVE`              | `operation:OP`, `policy_digest:H`, `policy_version:P`, `request_id:H`, `sequence:P`, `session_id:H`, `target:TARGET`, `timeout_ms:int 1..5000`                                                                                                                                                                                        | client request؛ ACTIVE و arrival slot آزاد                                     |
| `OBSERVATION_RESPONSE` | R، `observed_at:T`, `accepted_at:T`, `expires_at:T`, `observation:O`                                                                                                                                                                                                                                                                  | Controller response؛ attempt pending و result معتبر                            |
| `ERROR`                | R، `error_category:EC`, `diagnostic_code:DC`, `admitted:B`, `session_state:S`                                                                                                                                                                                                                                                         | Controller response؛ فقط CR و mapping مجاز                                     |
| `SESSION_INVALIDATED`  | `controller_instance_id:H`, `session_id:H`, `publication_generation:D+`, `manifest_digest:H`, `policy_version:P`, `policy_digest:H`, `error_category:EC`, `diagnostic_code:DC`, `session_state:S`                                                                                                                                     | Controller asynchronous notification؛ session صادرشده و terminal؛ ACTIVE ممنوع |

`OP` دقیقاً سه operation موجود است. TARGET:

```
OBSERVE_FILESYSTEM / OBSERVE_FREESPACE:
  {"root_id": I}

OBSERVE_SERVICE_STATUS:
  {"service_id": I}
```

در TARGET هر field دیگر ممنوع است. هیچ publication field به HELLO یا OBSERVE موجود اضافه نمی‌شود؛ binding آن‌ها از channel/session معتبر می‌آید.

O دقیقاً مطابق operation درخواست pending است:

```
FILESYSTEM:
  root_exists: B
  volume_kind: FIXED | REMOVABLE | NOT_DIRECTORY
  filesystem: NTFS | OTHER
  reparse_point_present: B

FREESPACE:
  bytes_free: D0
  bytes_total: D0

SERVICE:
  present: B
  state: RUNNING | STOPPED | PENDING | null
```

truth conditions D3-B حفظ می‌شوند: present=false فقط null؛ present=true غیرnull؛ NOT_DIRECTORY مستلزم root_exists=false؛ free≤total؛ UNKNOWN با false/zero/OTHER/PENDING جایگزین نمی‌شود. mapping native و truthful resource evidence همچنان H4 است.

transcript دقیق:

```
handshake_digest =
lowercase_hex(SHA256(
  ASCII("sentinel-model-b-handshake-1\n")
  || canonical_json({
       "challenge": exact AUTH_CHALLENGE object,
       "hello": exact HELLO object,
       "transport_binding": H
     })
))
```

بنابراین publication_generation و manifest_digest از طریق challenge کامل وارد transcript می‌شوند؛ خود manifest یا SESSION داخل فرمول نیست و circular dependency ایجاد نمی‌شود.

قبل از activation، همان publication و credential bindings دوباره verify می‌شوند. تغییر آن‌ها باعث rejection است. SESSION projection از committed ACTIVE state است؛ fields wire مستقیماً به constructor synthetic تزریق نمی‌شوند.

**وضعیت D3C-03: RESOLVED_AT_DESIGN_LEVEL.**

**۴. D3C-04؛ fenced send gate**

مسیر logical:

```
RESULT_ACCEPTED
→ RESPONSE_QUEUED
→ SEND_AUTHORIZED
→ SEND_IN_FLIGHT
→ SEND_COMPLETED

یا در مسیرهای مجاز:
→ SEND_SUPPRESSED
```

برای هر session یک `session_fence` داخلی و افزایشی وجود دارد؛ terminal شدن آن fence را تغییر می‌دهد. permit داخلی، غیرwire و single-use به این موارد متصل است:

```
connection identity
controller instance
session identity + session_fence
publication generation + manifest digest
request tuple
immutable frame digest
absolute deadline
send offset
```

مالکیت transitions:

- State Owner: result acceptance، queue registration، authorization، invalidation و suppression.
- Transport writer: تلاش bounded برای write و گزارش offset/completion.
- هیچ client یا worker permit صادر یا مصرف نمی‌کند.

`SEND_AUTHORIZED` در همان serialized owner commit می‌شود که state، زمان و fences را کنترل کرده است. برای observation، ACTIVE بودن و validity تمام bindings لازم است. برای ERROR/INVALIDATION مجاز، terminal state معلوم قابل گزارش است، اما observation data یا ادعای authorization جدید ارسال نمی‌شود.

برای فاصلهٔ authorization تا ورود bytes، **send fence مشترک** لازم است:

1. writer پیش از هر bounded `TryWrite`، permit، fence، instance و deadline را کنترل می‌کند.
2. کنترل و ورود bytes به transport باید در یک critical section محدود با همان fence باشند.
3. invalidation نیز برای تغییر fence از همین serialization استفاده می‌کند.
4. اگر invalidation پیش از ورود bytes پیروز شود، permit باطل و frame suppress می‌شود.
5. اگر bounded write پیش‌تر bytes را پذیرفته باشد، آن بخش in-flight است؛ recall تضمین نمی‌شود.

`TryWrite` نباید انتظار network، polling یا write نامحدود انجام دهد. در would-block، fence آزاد و انتظار بیرون از owner/fence انجام شود؛ تلاش بعدی دوباره validate می‌شود. lock ordering ثابت و bounded الزامی است.

در invalidation:

- قبل از authorization: suppress.
- بعد از authorization ولی پیش از ورود هر byte: suppress از طریق fence.
- پس از partial write: ادامهٔ frame متوقف و connection بسته شود؛ receiver frame ناقص را نپذیرد.
- پس از complete write: recall ممکن نیست؛ session terminal باقی می‌ماند و expiry/correlation client اعمال می‌شود.

در connection loss یا restart، permit و queue قبلی احیا یا resend نمی‌شوند. reservation release مستقل از تمام send states است و فقط cleanup evidence می‌تواند آن را آزاد کند.

**شرط implementation:** primitive bounded و enforceable برای check-plus-transport-entry هنوز تأیید نشده است. صرف lock داخل PHP یا بررسی قبل از write، این قرارداد را اثبات نمی‌کند.

**وضعیت D3C-04: PARTIALLY_RESOLVED.**

**۵. D3C-05؛ arrival قبل از queue**

«arrival» در این candidate، لحظهٔ مشاهدهٔ **اولین بایت plaintext frame جدید در ingress adapter** است؛ نه زمان send مهاجم یا زمان دریافت مخفی در kernel/TLS buffer. برای زمان غیرقابل‌مشاهده ادعایی نمی‌شود.

arrival actor مربوط به connection این stateها را دارد:

```
HANDSHAKE
READY
ATTEMPT_OPEN
TERMINAL
```

در READY، نخستین بایت frame درخواست یک arrival slot می‌گیرد و state به ATTEMPT_OPEN می‌رود؛ این تغییر قبل از queue/dequeue و admission است. malformed frame نیز slot را پس نمی‌دهد؛ connection terminal می‌شود.

در ATTEMPT_OPEN:

- frame دوم client violation است؛ در server ACTIVE، پیام application دیگری از client مجاز نیست.
- parser نباید آن را برای معتبرشدن بعدی نگه دارد.
- اگر دو frame در یک read باشند، پردازش arrivalهای همان batch با ترتیب byte و قبل از بازکردن slot انجام می‌شود.
- reader eventهای شروع‌شده قبل از terminal response، arrival epoch قبلی را حفظ می‌کنند؛ dequeue دیرهنگام آن را تغییر نمی‌دهد.

READY فقط پس از `SEND_COMPLETED` پاسخ نهاییِ غیرterminal همان attempt و اعتبار session باز می‌شود. response suppression، partial write، terminal ERROR یا connection loss به TERMINAL می‌روند.

کنترل‌های server مانند `SESSION_INVALIDATED` در receiver client، OBSERVE attempt نیستند؛ می‌توانند طبق lifecycle در کنار response queued شوند. client حق ارسال چنین control frameهایی ندارد.

حدود adapter D3-B حفظ می‌شوند:

```
payload <= 16384
full frame <= 16388
queued frames <= 2
accumulating frame <= 1
aggregate plaintext buffers <= 65536
scratch <= 4096
```

queue دو‌فریمی مجوز دو OBSERVE نیست. ingress metadata شامل arrival order، phase و slot است. در queue پر، backpressure و deadline اعمال می‌شود؛ bounds افزایش نمی‌یابند.

تاخیر read به‌علت backpressure، تعریف observable arrival را تغییر نمی‌دهد؛ این طراحی تضمین تشخیص زمان ارسال remote ندارد. جلوگیری از معتبرشدن **arrival مشاهده‌شدهٔ زودهنگام** مستقل از زمان dequeue است.

FrameDecoder مستقل برای هر frame، partial/coalesced reads، EOF و terminal parser errors همان قرارداد D3-B را دارند. handshake deadline 3000ms و partial-frame deadline 1000ms candidate هستند؛ deadline با هر بایت تمدید نمی‌شود.

**وضعیت D3C-05: RESOLVED_AT_DESIGN_LEVEL** برای observable ingress contract؛ adapter واقعی همچنان H3 است.

**۶. cross-contract consistency**

زنجیرهٔ تصمیم به این صورت است:

```
TLS-verified identity
→ channel-bound transcript
→ coherent signed publication
→ committed session binding
→ arrival slot
→ authorization + durable admission
→ worker evidence
→ result acceptance
→ fenced send
```

cleanup و reservation release شاخه‌ای مستقل دارند؛ response delivery پایان worker را ثابت نمی‌کند.

کنترل‌های کلیدی:

- authority snapshot با digestهای manifest متصل است؛ session به generation/digest همان publication متصل می‌شود.
- OBSERVE بدون تغییر schema، publication را از binding session می‌گیرد.
- A1 همچنان rejection صریح دارد؛ requested timeout clamp نمی‌شود.
- A2 همان ۱۲ فیلد است؛ publication، occupancy یا send state وارد scope digest نمی‌شود.
- response correlation به attempt دقیق متصل است؛ ERROR policy mismatch از policy bound session گزارش می‌دهد، نه از claim request.
- ERROR/INVALIDATION terminal، مجوز observation یا reactivation نیست.
- replay برابر با equality manifest نیست: manifest idempotent هیچ request/session history را reset نمی‌کند.
- clock failure یا journal uncertainty اجازهٔ authoritative admitted/state نمی‌دهد.
- send gate، admission consumption یا reservation را rollback نمی‌کند.
- schema و framing budgetها circular dependency ندارند.

تعارض جدیدی در candidate شناسایی نشد، اما این نتیجه independent acceptance یا native verification نیست. atomic durability و atomic transport-entry، شروط صریح و هنوز اثبات‌نشده‌اند.

**۷. بردارهای deterministic؛ بدون اجرا**

| state اولیه                        | input sequence                      | تصمیم موردانتظار                      | invariant                                         |
| ---------------------------------- | ----------------------------------- | ------------------------------------- | ------------------------------------------------- |
| HWM=G/M                            | همان canonical manifest G/M دوباره  | idempotent؛ همان absolute expiry      | replay lease را تمدید نمی‌کند                     |
| HWM=G/M                            | manifest امضاشده G/M′               | conflict و latch                      | generation برابر اجازهٔ substitution ندارد        |
| HWM=G، epoch=5                     | G+1 با epoch=4                      | رد                                    | generation جدید stale credential را مشروع نمی‌کند |
| restart با HWM durable G/M         | replay معتبر G/M                    | فقط reconciliation؛ session جدید لازم | session قبلی attach نشود                          |
| HWM=G/M، now=valid_until           | replay G/M                          | expired؛ رد                           | equality زمان expiry است                          |
| session A bound به channel         | OBSERVE با session B                | close بدون ERROR authoritative        | correlation ساخته نشود                            |
| ACTIVE، tuple اول consumed         | همان ID/digest/sequence             | replay، A=true؛ terminal              | redispatch ممنوع                                  |
| ACTIVE، ID قبلی consumed           | همان ID، payload/digest متفاوت      | replay، A=false برای جدید             | مصرف و reservation قبلی حفظ                       |
| attempt pending                    | ERROR با digest/session نامنطبق     | client رد کند؛ close                  | error مجوز retry/release نیست                     |
| challenge از G/M                   | publication G+1 پیش از confirmation | rejection؛ SESSION صادر نشود          | handshake به snapshot جدید جابه‌جا نشود           |
| RESPONSE_QUEUED                    | invalidation پیش از gate            | SEND_SUPPRESSED                       | نتیجهٔ terminal ارسال مجاز نگیرد                  |
| SEND_AUTHORIZED، offset=0          | invalidation قبل از TryWrite        | fence permit را باطل کند              | byte جدید وارد transport نشود                     |
| SEND_IN_FLIGHT، offset>0           | invalidation                        | abort ادامه و close                   | bytes قبلی recall فرض نشوند                       |
| writer با partial write            | would-block سپس disconnect          | بدون resend؛ terminal                 | reservation مستقل باقی                            |
| READY                              | دو OBSERVE در یک read               | دومی arrival violation                | dequeue دیرهنگام آن را معتبر نکند                 |
| ATTEMPT_OPEN                       | OBSERVE دوم                         | terminal violation                    | یک attempt بی‌پاسخ                                |
| admission committed، reservation=1 | connection loss                     | terminal؛ reservation تا cleanup      | consumed request recovery خودکار ندارد            |

تمام این‌ها design vectors هستند؛ هیچ تستی اجرا نشده است.

**۸. gaps و بررسی مستقل بعدی**

شش گروه evidence همچنان لازم‌اند:

1. TLS، certificate validation، exporter و PHP interoperability.
2. trusted monotonic/wall clock، rollback و adjustment.
3. journal durability، high-water marks و crash reconciliation.
4. bounded fenced write و ordering واقعی transport.
5. worker containment و independent cleanup.
6. resource/binary identity و مقاومت در برابر substitution/reparse.

C1 و C2 فقط در سطح قرارداد دقیق‌تر شده‌اند. C3 و H1–H4 بازند. provider معتبر wall-clock و adjustment policy هنوز دو تصمیم طراحی باز هستند؛ در این گزارش با فرض دلخواه پر نشده‌اند.

مرحلهٔ امن بعدی، **بازبینی مستقل همین candidate یکپارچه** است؛ مخصوصاً error matrix، terminal control send، publication equality، arrival definition و فرض bounded TryWrite. implementation نباید آغاز شود.

```
PHASE=MODEL_B_CONSOLIDATED_CONTRACT_CORRECTION_D3_D

FILES_CREATED=0
FILES_MODIFIED=0

D3C_01_STATUS=PARTIALLY_RESOLVED
D3C_02_STATUS=RESOLVED_AT_DESIGN_LEVEL
D3C_03_STATUS=RESOLVED_AT_DESIGN_LEVEL
D3C_04_STATUS=PARTIALLY_RESOLVED
D3C_05_STATUS=RESOLVED_AT_DESIGN_LEVEL

NEW_DESIGN_CONFLICTS=0_IDENTIFIED_PENDING_INDEPENDENT_REVIEW
UNRESOLVED_DESIGN_AMBIGUITIES=2_WALL_CLOCK_SOURCE_AND_ADJUSTMENT_POLICY
IMPLEMENTATION_FEASIBILITY_GAPS=6_EVIDENCE_GROUPS

C1_STATUS=OPEN_CONTRACT_REFINED_NATIVE_AUTHENTICATION_UNVERIFIED
C2_STATUS=OPEN_CONTRACT_REFINED_DURABILITY_AND_AUTHORITY_ENFORCEMENT_UNVERIFIED
C3_STATUS=OPEN

H1_STATUS=OPEN
H2_STATUS=OPEN
H3_STATUS=OPEN
H4_STATUS=OPEN

ARCHITECTURE_DECISION=DESIGN_REFINED_FOR_INDEPENDENT_REVIEW
IMPLEMENTATION_READY=NO
NEXT_SAFE_ACTION=SEPARATELY_AUTHORIZED_INDEPENDENT_REVIEW_OF_CONSOLIDATED_D3_D

NATIVE_CONTROLLER_IMPLEMENTED=NO
VM_START_AUTHORIZED=NO
VM_CREATED=NO
VM_STARTED=NO
MYSQL_STARTED=NO
MIGRATIONS_EXECUTED=NO
FULL_PROOF_EXECUTED=NO
REAL_LOCAL_RUNTIME_VERIFIED=NO
```

هیچ سند یا فایل ایجاد نشد؛ implementation آغاز نشد و کار پس از این گزارش متوقف شد.