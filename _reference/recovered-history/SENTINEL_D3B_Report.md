**DESIGN_REFINED_FOR_INDEPENDENT_REVIEW — IMPLEMENTATION_READY=NO**

D3-B قراردادهای پیشنهادی را دقیق‌تر می‌کند؛ کد synthetic تغییر نمی‌کند. تعارض انتقال خطای A1 در سطح طراحی اصلاح شده است. موارد وابسته به TLS، ساعت، persistence، containment و observation واقعی فقط **PARTIALLY_RESOLVED** هستند؛ برای آن‌ها شواهد implementation وجود ندارد.

هفت کلاس production فقط‌خواندنی بررسی شدند و با نسخهٔ D3-A یکسان‌اند. D2 و D3-A از گزارش‌های همین گفتگو در دسترس‌اند؛ **نسخهٔ مستقل و versioned از این اسناد در اختیار این بررسی نیست**. هیچ فایل، PHP، تست، شبکه یا API native اجرا نشد.

معماری همچنان شامل PHP client، Controller مستقل، Authority محافظت‌شده، journal متعلق به Controller و worker محدودشده است. authentication، admission، زمان، publication، invalidation و release زیر اختیار PHP یا worker قرار نمی‌گیرند. قراردادهای زیر candidate برای بازبینی مستقل‌اند.

**D3A-01 — profile دقیق TLS و channel binding**

candidate transport همچنان Loopback TCP با mutual TLS است؛ انتخاب نهایی transport تأیید نشده است.

| بخش                                 | قرارداد candidate                                                                                                           |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| نسخه                                | فقط TLS 1.3؛ بدون fallback                                                                                                  |
| cipher suite اولیه                  | `TLS_AES_128_GCM_SHA256`                                                                                                    |
| key exchange اولیه                  | `secp256r1`                                                                                                                 |
| certificate signature profile اولیه | ECDSA P-256/SHA-256؛ certificate و library compatibility باید بررسی شود                                                     |
| chain validation                    | chain کامل به trust anchor مصوب؛ validity، key usage، EKU، signature و constraints کنترل شوند                               |
| trust anchors                       | store محافظت‌شده با مدیریت Authority؛ PHP/worker حق تغییر ندارند                                                            |
| Controller identity                 | SAN URI دقیق `urn:sentinel:model-b:controller:<controller_id>`، همراه با هویت TLS endpoint مصوب در configuration محافظت‌شده |
| client identity                     | SAN URI دقیق `urn:sentinel:model-b:credential:<credential_id>` و fingerprint کلید متصل به credential record جاری            |
| client certificate selection        | certificate/key مشخص‌شده در deployment معتبر؛ نه انتخاب از JSON یا پیشنهاد peer                                             |
| private keys                        | کلید server فقط برای principal Controller؛ کلید client فقط برای principal client؛ هیچ کلیدی به worker نمی‌رسد               |
| revocation                          | وضعیت معتبر و تازهٔ certificate و credential هر دو لازم‌اند؛ unknown/stale باعث رد authentication یا توقف session می‌شود    |
| rotation                            | تغییر key identity با credential epoch جدید؛ اتصال‌های bound به epoch قبلی invalidate شوند                                  |
| resumption                          | در profile اولیه ممنوع؛ session tickets/PSK resumption پذیرفته نشوند                                                        |
| early data                          | ممنوع؛ هیچ درخواست با 0-RTT پذیرفته نشود                                                                                    |

روش تطبیق SAN و fingerprint باید مستقل از نام pipe، localhost، DNS lookup یا port باشد. Trust configuration باید endpoint موردانتظار را به Controller identity متصل کند.

channel binding candidate:

```
primitive: TLS 1.3 exporter
label: ASCII("EXPORTER-Sentinel-ModelB-V1")
context: ASCII("sentinel-model-b-auth-1")
output_length: 32 bytes
wire_representation: 64 lowercase hexadecimal characters
```

هر دو طرف باید دقیقاً همان exporter API و semantics context را استفاده کنند. نبود exporter، خطای آن یا mismatch باعث close بدون session issuance می‌شود. جایگزینی با nonce عمومی، connection ID، certificate fingerprint یا hash عمومی مجاز نیست.

فرمول transcript D2 حفظ می‌شود؛ `AUTH_CONFIRM` فقط توافق transcript روی channel قبلاً احرازشده را تأیید می‌کند. **authentication مستقل یا proof-of-possession جدید نیست.**

**Invariant:** session فقط پس از authentication دوطرفه و channel-bound confirmation صادر شود.
**فرض باقی‌مانده:** TLS backend، certificate validation، key protection و exporter در PHP و Controller قابل پیاده‌سازی باشند.
**Evidence لازم:** profile/library review و سپس interoperability جداگانه با مجوز مستقل.
**وضعیت:** `PARTIALLY_RESOLVED`.

**D3A-02 — manifest انتشار coherent**

هر publication باید یک manifest امضاشده داشته باشد. snapshotهای policy، credential state و resource registry به‌صورت مستقل قابل ترکیب نیستند.

نمادهای مشترک:

- `I`: identifier مطابق الگوی موجود.
- `H`: دقیقاً ۶۴ hex lowercase.
- `P`: integer مثبت، حداکثر `2147483647`.
- `T`: timestamp سیزده‌رقمی UTC epoch milliseconds.
- `D`: string دهدهی canonical unsigned 64-bit؛ بدون leading zero جز `"0"`.

manifest بدون signature دارای این فیلدهای دقیق است:

```
manifest_schema_version: 1
authority_id: I
publication_generation: D
policy_version: P
policy_digest: H
credential_state_version: D
credential_state_digest: H
credential_epochs_digest: H
resource_registry_version: D
resource_registry_digest: H
issued_at: T
valid_from: T
valid_until: T
signing_profile: "sentinel-ed25519-v1"
signing_key_id: I
```

همه required و هر فیلد اضافی forbidden است. `credential_epochs_digest` به map canonical مربوط به credential ID و دو epoch آن متصل می‌شود. generation و versionهای publication باید مثبت باشند.

envelope:

```
{
  "manifest": <typed canonical manifest>,
  "signature": <128 lowercase hexadecimal characters>
}
```

signing bytes:

```
ASCII("SENTINEL-MODELB-AUTHORITY-PUBLICATION-V1\n")
|| canonical_json(manifest)
```

signature candidate، Ed25519 است. verifier ابتدا authority/key/profile را از allowlist محافظت‌شده تعیین می‌کند؛ metadata پیام اجازهٔ انتخاب algorithm دلخواه نمی‌دهد.

ترتیب verification:

1. bounds و schema envelope؛
2. trust-root/key/profile lookup؛
3. signature؛
4. validity manifest و rollback checks؛
5. دریافت دقیق snapshotهای دارای digest اعلام‌شده؛
6. digest و typed schema هر snapshot؛
7. coherence application، credential، epoch، policy و resource generation؛
8. publication commit واحد توسط مالک state.

برای coherence، هر grant باید credential record متناظر با همان application و epochها داشته باشد. revocation مؤثر برابر ممنوعیت ناشی از هر دو منبع است؛ grant یا credential revoked اجازه ایجاد نمی‌کند.

candidate freshness lease برای manifest حداکثر `10000ms` است. نبود publication تازه پس از پایان lease باعث توقف admission و پذیرش نتیجه می‌شود. این حد، latency مشاهدهٔ revocation را محدود می‌کند؛ **revocation در Authority و اعمال آن در Controller هم‌زمان فرض نمی‌شوند**. تضمین freshness به H1 و publication delivery وابسته است.

publication:

- snapshotها immutable و content-addressed باشند؛
- مجموعهٔ ناقص publish نشود؛
- generation، manifest digest، snapshot digests و high-water marks در یک transaction durable ثبت شوند؛
- سپس pointer snapshot فعال عوض شود؛
- sessionهای publication قبلی invalidate شوند، حتی اگر A2 یکسان باشد.

renewal lease با generation جدید در این candidate، publication جدید محسوب می‌شود و sessionهای قبلی را invalidate می‌کند. این انتخاب محافظه‌کارانه، محدودیت عملیاتی صریح طراحی است.

interruption پیش از commit، publication جدید را فعال نمی‌کند. commit نامطمئن، journal corrupt یا high-water mark ناسازگار باعث `AUTHORITY_UNAVAILABLE` می‌شود؛ rollback به snapshot قدیمی برای ادامهٔ کار ممنوع است.

**Invariant:** هیچ تصمیمی از مجموعهٔ mixed-generation صادر نشود.
**فرض باقی‌مانده:** atomic durability، immutable storage و publication freshness.
**Evidence لازم:** signing vectors، mixed-snapshot vectors و crash-injection مستقل.
**وضعیت:** `PARTIALLY_RESOLVED`.

**D3A-03 — نقاط linearization و ترتیب invalidation**

تمام transitions زیر زیر اختیار همان Trusted State Owner serialized می‌شوند. I/O blocking خارج از owner انجام می‌شود و فقط نتیجهٔ قابل‌اعتبارسنجی به آن برمی‌گردد.

| عملیات                | نقطهٔ linearization                                                                       |
| --------------------- | ----------------------------------------------------------------------------------------- |
| Authority publication | commit durable مجموعه و high-water marks، سپس تعویض snapshot فعال                         |
| Session activation    | commit موفق bindingهای session پس از authority revalidation                               |
| Request admission     | commit durable مصرف sequence/request ID و reservation، پیش از dispatch                    |
| Result acceptance     | تصمیم owner بر مبنای نمونهٔ معتبر زمان و authority جاری، پیش از ایجاد response قابل ارسال |
| Session invalidation  | ثبت terminal state در ترتیب همان owner                                                    |
| Reservation release   | commit release پس از evidence مستقل cleanup؛ جدا از result acceptance                     |

قواعد ordering:

- اگر revocation، policy replacement یا invalidation قبل از result acceptance قرار گیرد، نتیجه discard شود.
- اگر زمان معتبر هنگام result acceptance به `grant.valid_until` رسیده باشد، نتیجه رد شود؛ equality به معنای expiry است.
- نتیجهٔ پذیرفته‌شده reservation را آزاد نمی‌کند.
- response queued، پیش از شروع ارسال دوباره به وضعیت session/publication متصل کنترل شود؛ invalidation می‌تواند response هنوز ارسال‌نشده را suppress کند.
- دادهٔ واردشده به transport buffer قابل پس‌گرفتن فرض نشود.
- response دریافتی پس از expiry قابل استفادهٔ جدید نباشد؛ هیچ پاسخ observation مجوز عملیاتی نیست.
- پس از دریافت invalidation، client پاسخ pending مربوط به آن session را discard کند.
- restart هنگام response، session قبلی را نامعتبر می‌کند؛ reconnect یا partial response اجازهٔ بازیابی یا redispatch نمی‌دهد.

این قرارداد «زمان تصمیم معتبر Controller» را تعریف می‌کند؛ تضمین دریافت شبکه پیش از expiry ارائه نمی‌دهد. پیاده‌سازی باید فاصلهٔ sampling زمان، commit و enqueue را تحت مدل H1/H2 بررسی کند.

**Invariant:** terminal event مقدم، با response مؤخر دور زده نشود؛ release مستقل بماند.
**فرض باقی‌مانده:** owner واقعی، trusted clock و journal durable.
**Evidence لازم:** race/crash vectors با ترتیب‌های دوگانهٔ completion و invalidation.
**وضعیت:** `PARTIALLY_RESOLVED`.

**D3A-04 — قرارداد بستهٔ ERROR و حفظ A1**

schema اصلاح‌شدهٔ `ERROR`:

```
protocol_version: 1
message_type: "ERROR"
controller_instance_id: H
session_id: H
request_id: H
sequence: P
request_digest: H
error_category: <closed enum>
diagnostic_code: <closed enum>
admitted: boolean
session_state: ACTIVE | INVALIDATED | EXPIRED | CLOSED
```

همه required و fields اضافی forbidden هستند. schema فقط برای `OBSERVE` دارای correlation معتبر استفاده شود؛ برای پیام malformed که correlation قابل‌اعتماد ندارد، connection بسته و خطا فقط در audit محدود ثبت شود.

`request_digest` برابر SHA-256 بایت‌های دقیق canonical همان attempt است.

نگاشت‌های ضروری:

| وضعیت                       | `error_category`                                            | `diagnostic_code`                |
| --------------------------- | ----------------------------------------------------------- | -------------------------------- |
| timeout بیش از limit مؤثر   | `AUTHORIZATION_DENIED`                                      | `TIMEOUT_LIMIT`                  |
| timeout ناکافی برای cleanup | `AUTHORIZATION_DENIED`                                      | `CLEANUP_BUDGET`                 |
| operation خارج scope        | `AUTHORIZATION_DENIED`                                      | `OPERATION_SCOPE`                |
| resource خارج scope         | `AUTHORIZATION_DENIED`                                      | `RESOURCE_SCOPE`                 |
| ظرفیت پر                    | `AUTHORIZATION_DENIED`                                      | `CONCURRENCY_LIMIT`              |
| replay/sequence gap         | `REPLAY_DETECTED`                                           | `SEQUENCE_OR_REQUEST_ID`         |
| policy binding mismatch     | `INVALID_POLICY`                                            | `POLICY_BINDING_MISMATCH`        |
| policy expired              | `INVALID_POLICY`                                            | `POLICY_EXPIRED`                 |
| grant expired               | `AUTHORIZATION_DENIED`                                      | `GRANT_EXPIRED_OR_NOT_YET_VALID` |
| credential revoked          | `AUTHORIZATION_DENIED`                                      | `GRANT_REVOKED_OR_UNKNOWN`       |
| cleanup نامطمئن             | `CLEANUP_UNCERTAIN`                                         | `CLEANUP_UNCERTAIN`              |
| completion نامنطبق          | `CONTROLLER_UNAVAILABLE` یا latch موجود `CLEANUP_UNCERTAIN` | `COMPLETION_STATE_UNCERTAIN`     |
| clock/state نامعتبر         | `CONTROLLER_UNAVAILABLE`                                    | `CLOCK_OR_STATE_INVALID`         |
| worker timeout              | `WORKER_TIMEOUT`                                            | `WORKER_TIMEOUT`                 |

سایر diagnosticهای مجاز باید از فهرست بستهٔ source موجود و قرارداد observation انتخاب شوند؛ unknown string از exception وارد wire نشود. raw exception، stack trace و secret ممنوع‌اند.

`session_state` گزارشی از state واقعی پس از تصمیم است؛ client آن را برای reactivation استفاده نمی‌کند. `SESSION_REJECT` نیز category و diagnostic جدا دارد، بدون session ID صادرنشده و بدون echo کردن ورودی نامعتبر.

**Invariant:** زوج `AUTHORIZATION_DENIED / TIMEOUT_LIMIT` دقیقاً حفظ شود.
**فرض:** enum mapping در implementation آینده صریح اعمال شود.
**Evidence لازم:** schema و mapping vectors مستقل.
**وضعیت:** `RESOLVED_AT_DESIGN_LEVEL`.

**D3A-05 — truth conditions پاسخ‌ها**

`OBSERVATION_RESPONSE` همان correlationهای D2 را دارد و فیلد required زیر افزوده می‌شود:

```
accepted_at: T
```

همهٔ fields بسته‌اند. validation مشترک:

- channel، Controller instance و session دقیقاً مطابق session فعال باشند؛
- request ID، sequence و request digest با همان attempt pending برابر باشند؛
- policy version/digest با snapshot bound برابر باشند؛
- operation از درخواست pending تعیین شود؛ observation نمی‌تواند نوع دیگری انتخاب کند؛
- `observed_at <= accepted_at < expires_at`؛
- `expires_at` از کمینهٔ receipt TTL، session، policy، grant، credential و manifest lease تجاوز نکند؛
- terminal session یا publication نامعتبر مانع پذیرش نتیجه باشد.

truth table observation:

| operation                | fields دقیق                                                                                                               | قیود وابسته                                                                                                                                                                                |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `OBSERVE_FILESYSTEM`     | `root_exists:bool`, `volume_kind:FIXED\|REMOVABLE\|NOT_DIRECTORY`, `filesystem:NTFS\|OTHER`, `reparse_point_present:bool` | `NOT_DIRECTORY` مستلزم `root_exists=false`؛ root موجود باید directory معتبر باشد. filesystem مربوط به volume همان binding باشد. reparse boolean فقط پس از observation کامل chain مجاز است. |
| `OBSERVE_FREESPACE`      | `bytes_free:D`, `bytes_total:D`                                                                                           | هر دو canonical decimal و در uint64؛ `bytes_free <= bytes_total`؛ float conversion ممنوع                                                                                                   |
| `OBSERVE_SERVICE_STATUS` | `present:bool`, `state:RUNNING\|STOPPED\|PENDING\|null`                                                                   | `present=false` دقیقاً مستلزم `state=null`؛ `present=true` مستلزم state غیرnull و قابل نگاشت                                                                                               |

`PENDING` فقط برای مجموعهٔ مصوب native transition states است، نه UNKNOWN. state native بدون mapping باید `OBSERVATION_UNAVAILABLE` تولید کند.

`OTHER` یعنی filesystem با موفقیت مشاهده شده ولی NTFS نیست؛ به معنی query failure نیست. root غایب و reparse observation نامعلوم متفاوت‌اند. observation ناقص نباید false، صفر، `OTHER` یا `PENDING` بسازد.

Controller پیش از پاسخ باید resource generation/identity ثبت‌شده را با evidence معتبر native مرتبط کند. authenticated response به‌تنهایی truthful observation را تضمین نمی‌کند؛ H4 باقی است.

تعریف دقیق `ERROR.admitted`:

> آیا همین tuple شامل session، sequence، request ID و request digest قبلاً به admission durable رسیده است؟

- rejection پیش از commit: `false`؛
- duplicate دقیق tuple پذیرفته‌شده: `true`، بدون redispatch؛
- payload متفاوت با ID تکراری: attempt جدید پذیرفته نشده، اما تاریخچه و reservation قبلی حفظ می‌شوند؛
- unknown journal state: پاسخ authoritative صادر نشود؛ fail closed.

هیچ مقدار `admitted` اجازهٔ release یا automatic retry نمی‌دهد.

**Invariant:** malformed یا uncertain measurement به پاسخ سالم تبدیل نشود.
**فرض باقی‌مانده:** truthful native observation، trusted timestamps و identity binding.
**Evidence لازم:** dependent-field vectors و native resource evidence در مرحلهٔ مجاز آینده.
**وضعیت:** `PARTIALLY_RESOLVED`.

**D3A-06 — canonical policy و signing bytes**

typed representation باید schema-aware باشد:

| نوع                         | representation                      |
| --------------------------- | ----------------------------------- |
| registry/map                | JSON object؛ حتی در حالت خالی `{}`  |
| allowlist                   | JSON array؛ حتی در حالت خالی `[]`   |
| nested registry record      | object با fields بسته               |
| allowlist identifiers       | unique و ASCII-sorted               |
| integer                     | integer سخت‌گیرانه در محدودهٔ موجود |
| boolean                     | فقط `true/false`                    |
| identifier/digest/timestamp | string با validation نوع خود        |

cast سادهٔ `$policy->data()` کافی نیست؛ associative arrays باید طبق schema به object تبدیل شوند و lists به list باقی بمانند.

candidate operational profile:

- همان قواعد canonical، ASCII، integer و عمق ۶؛
- حداکثر canonical policy bytes برابر `16384`؛
- snapshotهای بزرگ‌تر برای profile عملیاتی رد می‌شوند.

این سقف **محدودیت صریح profile عملیاتی** است؛ دامنهٔ ورودی fixture فعلی یا semantics synthetic تغییر نمی‌کند.

policy body دارای همان fields موجود است، جز حذف `policy_digest` فقط هنگام محاسبهٔ digest:

```
policy_digest =
SHA256(canonical_bytes(typed_policy_without_policy_digest))
```

credential-state و resource-registry digest نیز از typed canonical bytes کامل snapshot مربوط محاسبه می‌شوند. manifest، این digestها را به generation واحد متصل می‌کند.

signature مستقیم روی policy object جدید لازم نیست؛ امضای manifest معتبر، digest دقیق آن را پوشش می‌دهد. اگر snapshot signature جدا داشته باشد، جایگزین manifest coherence نمی‌شود.

A2 بدون تغییر می‌ماند:

```
scope_digest =
SHA256(canonical_json(exact_12_field_effective_grant))
```

occupancy، manifest generation و whole-policy digest وارد آن نمی‌شوند. policy/session binding مستقل همچنان الزامی است.

**Invariant:** representation و digest یک policy در همهٔ implementationها یکسان باشد؛ A2 تغییر نکند.
**فرض:** typed serializer مطابق قرارداد باشد.
**Evidence لازم:** golden canonical/digest vectors، شامل empty map/list و budget boundaries.
**وضعیت:** `RESOLVED_AT_DESIGN_LEVEL`.

**D3A-07 — issuance و activation**

ترتیب دقیق:

```
Authenticated transport
→ HELLO
→ AUTH_CHALLENGE
→ AUTH_CONFIRM
→ Authority revalidation
→ Session creation
→ Activation commit
→ SESSION response
```

challenge به publication generation و manifest digest جاری متصل می‌شود؛ این دو مقدار در transcript و session binding قرار می‌گیرند. تغییر publication بین challenge و confirmation باعث rejection و close می‌شود؛ هویت یا epoch جدید بی‌صدا جایگزین نمی‌شود.

activation commit باید:

- context معتبر، grant non-revoked، epochها و policy جاری را کنترل کند؛
- session ID را Controller تولید کند؛
- lifetime را به حداکثر ۶۰ ثانیه و validityهای bound محدود کند؛
- state را قبل از enqueue پیام `SESSION` به ACTIVE برساند.

`SESSION` پیش از commit موفق صادر نمی‌شود. در failure، duplicate handshake، ordering نامعتبر یا timeout، connection بسته می‌شود. connection loss پس از commit session را terminal می‌کند؛ دریافت‌نشدن SESSION مجوز صدور مجدد همان session نیست.

restart instance را تغییر می‌دهد و reconnect session قبلی را احیا نمی‌کند.

**Invariant:** تنها session committed و ACTIVE به client معرفی شود.
**فرض باقی‌مانده:** owner و authority revalidation واقعی.
**Evidence لازم:** duplicate issuance، publication-change و connection-loss vectors.
**وضعیت:** `PARTIALLY_RESOLVED`.

**D3A-08 — ساعت native**

سه وضعیت جدا:

```
MONOTONIC_DEADLINES
WALL_CLOCK_VALIDITY
CLOCK_TRUST_FAILURE
```

candidate representation داخلی:

- monotonic unit: nanoseconds؛
- width: unsigned 64-bit؛
- origin: مبدا مشخص provider، bound به Controller instance؛
- arithmetic: checked؛ بدون truncate، modulo یا wrap؛
- تبدیل provider ticks به nanoseconds با arithmetic بدون overflow؛
- مقایسهٔ deadline با مقدار native کامل، نه projection ۳۲‌بیتی.

تبدیل session-relative میلی‌ثانیه فقط برای view یا adapter محدود است:

```
elapsed_ms = floor((now_ns - session_created_ns) / 1000000)
```

این مقدار منبع تصمیم native نیست. اگر projection خارج محدودهٔ synthetic باشد، تبدیل رد می‌شود؛ counter reset برای دورزدن limit ممنوع است.

restart، مبدا و instance جدید می‌سازد. monotonic مقدار قبلی بین restartها مقایسه نمی‌شود؛ sessionهای قبلی نامعتبرند.

wall-clock validity به provider معتبر و سیاست uncertainty نیاز دارد. rollback، اختلاف خارج tolerance یا provider failure باعث `CLOCK_TRUST_FAILURE`، توقف admission/result acceptance و invalidation sessionها می‌شود. adjustment نمی‌تواند session یا grant را تمدید کند.

منبع wall-clock، tolerance و رفتار suspend/resume هنوز باید با evidence تعیین شوند؛ انتخاب دلخواه برای آن‌ها در این گزارش انجام نمی‌شود.

**Invariant:** clock conversion یا adjustment expiry را دور نزند.
**فرض باقی‌مانده:** provider معتبر، resolution و adjustment policy.
**Evidence لازم:** native clock/overflow/rollback verification جداگانه.
**وضعیت:** `PARTIALLY_RESOLVED`.

**D3A-09 — adapter framing چندپیامی**

`FrameDecoder` تغییر نمی‌کند. adapter connection مسئول تفکیک stream است.

| قید                              | candidate                                                 |
| -------------------------------- | --------------------------------------------------------- |
| payload هر frame                 | `1..16384` بایت                                           |
| frame کامل                       | حداکثر `16388` بایت                                       |
| queued frames                    | حداکثر ۲                                                  |
| frame در حال accumulation        | حداکثر ۱                                                  |
| aggregate plaintext byte buffers | حداکثر `65536` بایت، شامل read scratch و staging          |
| read scratch                     | حداکثر `4096` بایت                                        |
| decoder                          | یک instance تازه برای هر frame؛ بدون reuse                |
| pipelining client                | ممنوع؛ حداکثر یک attempt بی‌پاسخ                          |
| handshake deadline candidate     | `3000ms` از شروع اتصال؛ بدون تمدید با دریافت بایت         |
| partial-frame deadline candidate | `1000ms` از اولین بایت؛ محدود به deadline بیرونی کوتاه‌تر |

adapter باید partial header/payload را accumulate کند، چند frame در یک read را به ترتیب جدا کند و فقط bytes همان frame را به decoder بدهد. EOF روی frame ناقص، trailing bytes داخل frame، oversized length و parser error terminal هستند.

در queue پر، read متوقف و backpressure اعمال می‌شود؛ buffer رشد نمی‌کند. اگر deadline پایان یابد، connection بسته می‌شود. server invalidation و response نیز از یک writer مرتب عبور کنند.

این byte budget، تضمین کل heap یا native TLS buffers نیست؛ resource cap کلی و bounded I/O همچنان H3/C3 هستند.

**Invariant:** مرز read با مرز frame اشتباه گرفته نشود و accumulation محدود بماند.
**فرض باقی‌مانده:** adapter، clock و transport I/O bounded.
**Evidence لازم:** split/coalesced frame، queue saturation و deadline vectors.
**وضعیت:** `PARTIALLY_RESOLVED`.

**بردارهای conformance؛ فقط طراحی، بدون اجرا**

فرض مشترک: connectionهای A/B binding متفاوت، instanceهای X/Y متفاوت و tuple درخواست شامل digest دقیق است.

| بردار                    | ورودی/ترتیب deterministic                    | نتیجهٔ لازم                                                             |
| ------------------------ | -------------------------------------------- | ----------------------------------------------------------------------- |
| Cross-connection replay  | confirmation A روی B                         | mismatch و close؛ بدون SESSION                                          |
| Cross-instance replay    | session X روی Y                              | rejection؛ بدون attach یا dispatch                                      |
| Rotation                 | epoch ۱ → ۲، سپس درخواست epoch ۱             | invalidation/rejection؛ reservation قبلی مستقل حفظ شود                  |
| Revocation               | publication revoked قبل از result commit     | result discard؛ release فقط با cleanup                                  |
| Mixed snapshots          | manifest G، credential digest از G−1         | publication رد؛ هیچ pointer جزئی فعال نشود                              |
| Policy rollback          | generation پایین‌تر از high-water mark       | رد؛ بدون fallback                                                       |
| Duplicate issuance       | دومین confirmation همان connection           | close؛ SESSION دوم صادر نشود                                            |
| Result/invalidation race | ابتدا invalidation سپس completion            | discard                                                                 |
| ترتیب معکوس race         | result commit قبل از invalidation            | نتیجهٔ قبلاً committed مجوز جدید نشود؛ queued response دوباره کنترل شود |
| Grant boundary           | `valid_until−1ms` و دقیقاً `valid_until`     | اولی فقط با سایر شروط معتبر؛ دومی رد                                    |
| Malformed ERROR          | digest نامنطبق یا category/diagnostic نامجاز | پاسخ رد؛ بدون retry/release                                             |
| Service fields           | `present=true,state=null`                    | رد، نه UNKNOWN→false                                                    |
| Free-space fields        | `"00"`، مقدار بیش از uint64 یا free>total    | رد؛ بدون numeric coercion                                               |
| Filesystem uncertainty   | reparse observation ناقص با boolean false    | پاسخ سالم صادر نشود                                                     |
| Oversized frame          | length برابر 16385                           | terminal قبل از allocation payload بزرگ                                 |
| Coalesced read           | دو frame مجاز در یک read                     | تفکیک ordered؛ decoder مستقل                                            |
| Forbidden pipelining     | OBSERVE دوم پیش از پاسخ اول                  | protocol violation؛ مصرف درخواست اول بازگردانده نشود                    |
| Clock overflow           | جمع deadline از uint64 عبور کند              | clock trust failure؛ بدون wrap یا admission                             |
| Publication interruption | توقف پیش/پس از commit                        | پیش: جدید فعال نشود؛ پس/نامطمئن: reconciliation fail closed             |

**محدودیت‌ها و بررسی مستقل بعدی**

دو قرارداد D3A-04 و D3A-06 در سطح طراحی اصلاح شده‌اند. هفت مورد دیگر به‌دلیل وابستگی به evidence عملیاتی فقط partially resolved هستند. ابهام ماشینی اصلی در candidate حاضر مشخص شده است، اما این گزارش ادعای تأیید مستقل یا رفع تمام ریسک‌ها نمی‌کند.

C1 و C2 هنوز به identity/TLS و Authority enforcement واقعی وابسته‌اند. C3، یعنی containment و cleanup، باز است. H1 ساعت، H2 durability/recovery، H3 bounded I/O و interoperability و H4 resource/binary identity نیز بازند.

مرحلهٔ پیشنهادی بعد، **بازبینی مستقل همین قرارداد D3-B** است؛ با تمرکز بر exporter profile، manifest signing bytes، publication lease، linearization، truth tables، error mapping و clock/framing boundaries. در آن مرحله نیز implementation نباید از رفع ابهام قرارداد پیشی بگیرد.

```
PHASE=MODEL_B_SECURITY_CONTRACT_REMEDIATION_D3_B

FILES_CREATED=0
FILES_MODIFIED=0

D3A_01_STATUS=PARTIALLY_RESOLVED
D3A_02_STATUS=PARTIALLY_RESOLVED
D3A_03_STATUS=PARTIALLY_RESOLVED
D3A_04_STATUS=RESOLVED_AT_DESIGN_LEVEL
D3A_05_STATUS=PARTIALLY_RESOLVED
D3A_06_STATUS=RESOLVED_AT_DESIGN_LEVEL
D3A_07_STATUS=PARTIALLY_RESOLVED
D3A_08_STATUS=PARTIALLY_RESOLVED
D3A_09_STATUS=PARTIALLY_RESOLVED

CONFIRMED_DESIGN_CONFLICTS_REMAINING=0_IN_CORRECTED_CANDIDATE_PENDING_INDEPENDENT_REVIEW
UNRESOLVED_DESIGN_AMBIGUITIES=2_WALL_CLOCK_PROVIDER_AND_ADJUSTMENT_POLICY
IMPLEMENTATION_FEASIBILITY_GAPS=2_ORIGINAL_GAPS_REMAIN_TLS_EXPORTER_AND_NATIVE_CLOCK

CRITICAL_DESIGN_BLOCKERS=3
HIGH_DESIGN_BLOCKERS=4

C1_STATUS=PARTIALLY_REFINED_NATIVE_AUTHENTICATION_UNVERIFIED
C2_STATUS=PARTIALLY_REFINED_AUTHORITY_ENFORCEMENT_UNVERIFIED
C3_STATUS=OPEN

H1_STATUS=OPEN
H2_STATUS=OPEN
H3_STATUS=OPEN
H4_STATUS=OPEN

ARCHITECTURE_DECISION=DESIGN_REFINED_FOR_INDEPENDENT_REVIEW
IMPLEMENTATION_READY=NO
NEXT_SAFE_ACTION=SEPARATELY_AUTHORIZED_INDEPENDENT_REVIEW_OF_D3_B_CONTRACTS

NATIVE_CONTROLLER_IMPLEMENTED=NO
VM_START_AUTHORIZED=NO
VM_CREATED=NO
VM_STARTED=NO
MYSQL_STARTED=NO
MIGRATIONS_EXECUTED=NO
FULL_PROOF_EXECUTED=NO
REAL_LOCAL_RUNTIME_VERIFIED=NO
```

هیچ سند یا فایل ایجاد نشد؛ implementation آغاز نشد و کار پس از گزارش متوقف شد.