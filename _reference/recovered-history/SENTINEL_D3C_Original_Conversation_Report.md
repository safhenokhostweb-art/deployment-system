**REVIEW_BLOCKED — IMPLEMENTATION_READY=NO**

D3-B قراردادها را بهتر کرده، اما برای بسته‌شدن بازبینی کافی نیست. تفکیک operational `policy_digest` از A2 و representation نوع‌دار policy در سطح طراحی قابل قبول است. انتقال زوج‌های A1 نیز اصلاح شده؛ بااین‌حال، ادعای بسته‌شدن **کل قرارداد ERROR** پذیرفته نمی‌شود.

پنج ابهام تکمیلی ثبت شد: یک HIGH، سه MEDIUM و یک LOW. هیچ آسیب‌پذیری native یا production تأیید نشده است.

هفت کلاس production فقط‌خواندنی بررسی شدند و با نسخهٔ D3-B یکسان‌اند. گزارش کامل D3-B و شواهد D2/D3-A از همین گفتگو بررسی شدند؛ **سند versioned مستقلی در اختیار نیست**. هیچ فایل تغییر نکرد و هیچ PHP، تست، شبکه یا عملیات native اجرا نشد.

**یافته‌های مستقل**

| ID     | شدت    | محل دقیق در D3-B                                   | یافته                                                                                            | اصلاح حداقلی                                                |
| ------ | ------ | -------------------------------------------------- | ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| D3C-01 | HIGH   | D3A-02، rollback/high-water mark و freshness lease | رفتار generation برابر با manifest متفاوت، replay همان manifest و محاسبهٔ lease کاملاً صریح نیست | equality/conflict rules و expiry مطلق تعریف شوند            |
| D3C-02 | MEDIUM | D3A-04، schema و mappingهای `ERROR`                | enum کامل و correlation امن تمام failureها مشخص نشده است                                         | ماتریس بستهٔ category/diagnostic/correlation/state          |
| D3C-03 | MEDIUM | D3A-07، binding publication در handshake/session   | generation و manifest digest اضافه شده‌اند، ولی schema نهایی پیام‌ها به‌صورت یکپارچه ارائه نشده  | schema دقیق جایگزین D2، با نام و نوع همهٔ fields            |
| D3C-04 | MEDIUM | D3A-03، کنترل queued response پیش از ارسال         | نقطهٔ hand-off به writer و ordering آن با invalidation تعریف کافی ندارد                          | send gate serialized با fencing مشخص                        |
| D3C-05 | LOW    | D3A-09، queue و forbidden pipelining               | بررسی pipelining هنگام arrival یا dequeue مشخص نیست                                              | arrival-state ثبت و violation پیش از dequeue تشخیص داده شود |

هر پنج مورد `UNRESOLVED_DESIGN_AMBIGUITY` هستند. موارد HIGH و MEDIUM مانع implementation بخش مربوط‌اند؛ LOW نیز باید پیش از نهایی‌شدن adapter specification رفع شود.

**TLS و channel binding**

در سطح قرارداد، این اجزا با یکدیگر تعارض آشکار ندارند:

- TLS 1.3، mutual certificate authentication، ممنوعیت resumption و early data.
- label برابر `EXPORTER-Sentinel-ModelB-V1`.
- context برابر بایت‌های ASCII `sentinel-model-b-auth-1`.
- خروجی exporter برابر ۳۲ بایت.
- transcript دارای HELLO، challenge، Controller instance، credential binding و channel binding.
- نبود fallback به nonce عمومی یا certificate fingerprint به‌عنوان channel binding.

`AUTH_CONFIRM` فقط توافق transcript روی channel احرازشده را کنترل می‌کند. public hash به‌تنهایی authentication یا proof-of-possession ایجاد نمی‌کند.

بااین‌حال:

- semantics دقیق API exporter، از جمله context و محل فراخوانی، باید با implementation منتخب تطبیق داده شود.
- certificate validation باید شامل chain، EKU، identity دقیق و credential-to-key binding باشد.
- certificate دارای هویت مبهم یا نگاشت کلید به چند credential فعال نباید با انتخاب دلخواه پذیرفته شود.
- freshness revocation certificate و credential باید هر دو معیار مشخص داشته باشند؛ واژهٔ «fresh» به‌تنهایی کافی نیست.
- rotation باید connection/session قبلی را invalidate کند؛ key جدید نباید داخل session قبلی جایگزین شود.

نتیجه:

```
CONTRACT_CORRECTNESS=CONDITIONAL
CRYPTOGRAPHIC_FEASIBILITY=UNVERIFIED
PHP_INTEROPERABILITY=UNVERIFIED
```

D3A-01 همچنان partially resolved است.

**publication و D3C-01**

manifest دارای domain-separated signing bytes، authority/key/profile binding و digestهای policy، credential، epoch vector و resource registry است. این ساختار امکان جلوگیری از mix-and-match را فراهم می‌کند، مشروط به اینکه Controller تمام digestها و روابط snapshotها را بازسازی و کنترل کند.

سه قاعده باید صریح شوند:

1. **generation پایین‌تر:** همیشه رد شود.
2. **generation برابر، manifest canonical برابر:** فقط idempotent؛ هیچ تمدید lease، reset freshness یا publication جدید ایجاد نکند.
3. **generation برابر، manifest متفاوت:** conflict؛ رد و توقف publication تا reconciliation معتبر.

high-water mark باید generation و digest مربوط را با هم نگه دارد. signature معتبر به‌تنهایی conflict یا freshness را حل نمی‌کند.

برای lease:

- بازهٔ `issued_at/valid_from/valid_until` و روابط آن‌ها باید دقیق باشد.
- حد 10000ms باید به validity مطلق manifest متصل شود، نه «زمان دریافت + ده ثانیه».
- دریافت دوبارهٔ manifest قبلی نباید deadline را تمدید کند.
- Controller باید پس از restart نیز manifest منقضی را رد کند.
- renewal جدید همان‌طور که D3-B بیان کرده، generation جدید و invalidation sessionهای قبلی ایجاد می‌کند.

این lease **revocation فوری نیست**. حتی حد ده‌ثانیه‌ای فقط با ساعت معتبر، انتشار state جاری و قرارداد دقیق freshness قابل ادعاست. امضای جدید روی state قدیمی نیز بدون قواعد monotonic version/epoch لزوماً freshness نمی‌سازد.

همچنین schema کامل credential-state، epoch vector و resource registry و برابری versionهای داخل snapshot با manifest باید در specification نهایی قرار گیرد. atomic storage، high-water mark durability و recovery همچنان H2 هستند.

**lifecycle و D3C-04**

مالک واحد و تفکیک admission، result acceptance و reservation release صحیح‌اند. source synthetic نیز:

- completion نامنطبق را پیش از release رد می‌کند؛
- terminal session را دوباره فعال نمی‌کند؛
- cleanup نامطمئن را با reservation نگه می‌دارد؛
- درخواست consumed را خودکار redispatch نمی‌کند.

اما برای writer این توالی هنوز مبهم است:

```
بررسی مجدد queued response
→ invalidation
→ شروع ارسال توسط writer
```

کنترل اولیهٔ state، به‌تنهایی ordering ارسال را تعیین نمی‌کند. لازم است hand-off مجاز به writer تحت همان owner و با generation/session fencing ثبت شود. تعریف شود که پس از کدام نقطه پاسخ «in-flight» محسوب می‌شود و دیگر suppress کردن آن تضمین نمی‌شود.

این اصلاح نباید owner را برای network I/O نامحدود قفل کند. response دیررس نیز operational token نیست و session را احیا نمی‌کند.

result durability، recovery پس از crash و evidence release هنوز assumptions عملیاتی‌اند. هیچ توالی بررسی‌شدهٔ synthetic، release بدون تأیید fixture نشان نداد؛ **تأیید fixture معادل cleanup native نیست**.

**ERROR و D3C-02**

این دو نگاشت درست شده‌اند:

```
AUTHORIZATION_DENIED / TIMEOUT_LIMIT
AUTHORIZATION_DENIED / CLEANUP_BUDGET
```

اما schema همچنان `<closed enum>` دارد و «سایر diagnosticها» را به فهرستی که کامل ارائه نشده ارجاع می‌دهد. compatibility فقط برای زوج‌های جدول اثبات طراحی دارد، نه تمام failures موجود.

نمونهٔ مهم: [session mismatch (line 40)]\(/C:/laragon/www/deployment-system/service/app/Sentinel/ModelB/Synthetic/AuthorizationEvaluator.php:40). درخواست از نظر syntax معتبر است، اما session ID آن با session واقعی channel تطابق ندارد. specification باید تعیین کند:

- آیا connection بدون ERROR بسته می‌شود؟
- اگر ERROR مجاز است، correlation به کدام session متصل می‌شود؟
- `session_state` از کدام state واقعی گرفته می‌شود؟

echo کردن session ID نامعتبر همراه با state فرضی مجاز نیست.

ماتریس نهایی باید برای هر failure تعیین کند: category، diagnostic، prerequisites correlation، `admitted`، state و close behavior. برای malformed یا unknown correlation، عدم صدور ERROR ساختگی و audit محدود انتخاب امنی است.

تعریف tuple برای `admitted` پیشرفت مناسبی است. `false` در attempt دارای payload متفاوت، مصرف قبلی همان request ID را پاک نمی‌کند. در journal نامعلوم نیز نباید boolean authoritative تولید شود. هیچ مقدار آن مجوز retry یا release نیست.

بنابراین D3A-04 **REOPENED** است؛ زوج A1 اصلاح شده، اما کل error contract بسته نشده است.

**observation truth conditions**

قواعد وابستهٔ D3-B نسبت به D2 بهترند:

- `present=false → state=null` و `present=true → state!=null`.
- UNKNOWN به false، صفر، `OTHER` یا `PENDING` تبدیل نمی‌شود.
- `bytes_free` و `bytes_total` stringهای canonical uint64 هستند؛ float coercion ممنوع است.
- `free <= total`.
- `observed_at <= accepted_at < expires_at`.
- response با request digest، policy، session و Controller instance correlated است.

موارد باقی‌مانده:

- مجموعهٔ native states قابل نگاشت به `PENDING` هنوز دقیقاً فهرست نشده است.
- منبع truth برای root غایب، filesystem `OTHER` و reparse=false باید evidence کامل داشته باشد؛ query failure با این مقادیر تفاوت دارد.
- resource identity باید قبل از response acceptance کنترل شود؛ TLS فقط sender را احراز می‌کند.
- مقایسهٔ response expiry در client، بدون مدل زمان معتبر client، نباید تضمین امنیتی مستقلی تلقی شود.

در schema اصلاح‌شده، مجوز صریحی برای UNKNOWN→false دیده نشد؛ اما truthful native measurement اثبات نشده است. D3A-05 partially resolved می‌ماند.

**canonicalization**

D3A-06 در سطح طراحی قابل بسته‌شدن است:

- object/list و `{}`/`[]` صریح تفکیک شده‌اند.
- nested maps طبق schema تبدیل می‌شوند.
- allowlists unique و ASCII-sorted هستند.
- integerها محدود و سخت‌گیرانه‌اند.
- operational digest از policy بدون `policy_digest` ساخته می‌شود.
- manifest امضاشده digest policy را پوشش می‌دهد؛ signature خودش وارد digest نمی‌شود.
- A2 همچنان digest دوازده‌فیلدی grant مؤثر است.

در این تعریف، digest circular نیست. signature، authority، key/profile و digestهای snapshotها را در manifest پوشش می‌دهد.

بودجهٔ 16384 بایت باید برای representation کامل policy profile، شامل فیلد digest نهایی، اندازه‌گیری شود. این محدودیت operational صریح است و پذیرش fixtureهای بزرگ‌تر را تغییر نمی‌دهد. conformance serializer هنوز اجرا نشده است.

**activation و D3C-03**

ترتیب revalidation، creation، activation commit و سپس SESSION صحیح است. تغییر publication وسط handshake، duplicate confirmation، connection loss و restart نیز fail closed توصیف شده‌اند.

اما D3-B افزودن publication generation/manifest digest را در prose بیان می‌کند، بدون ارائهٔ schema نهایی جایگزین برای `AUTH_CHALLENGE` و `SESSION`. استفاده هم‌زمان از جدول D2 و توضیح D3-B دو برداشت ایجاد می‌کند.

اصلاح حداقلی: نام، نوع، required بودن و جایگاه این fields در transcript و session projection صریح شود؛ fields جدید وارد constructor فعلی `SessionState` فرض نشوند.

همچنین session ID نباید در همان instance برای session دیگری reuse شود، حتی اگر session قبلی terminal باشد. reconnect، SESSION دریافت‌نشده یا AUTH_CONFIRM تکراری نباید session committed قبلی را دوباره صادر کند.

D3A-07 partially resolved است.

**clock**

uint64 nanoseconds، checked arithmetic و منع modulo/truncation برای native candidate مناسب‌اند. session-relative projection نیز جایگزین native deadline نشده است.

از source synthetic نمی‌توان این موارد را اثبات کرد:

- provider monotonic و conversion ticks؛
- رفتار suspend/resume؛
- wall-clock trust، uncertainty و adjustment؛
- کنترل deadline نزدیک overflow؛
- freshness lease پس از restart.

رفتار محافظه‌کارانه باید برای suspend/resume نامطمئن و provider discontinuity، clock trust failure باشد؛ زمان ازدست‌رفته نباید grant یا session را تمدید کند. D3A-08 partially resolved می‌ماند.

**framing و D3C-05**

بودجه‌ها از نظر حسابی تعارض ندارند:

```
2 queued frames + 1 accumulating frame + scratch
= 3 × 16388 + 4096
= 53260 bytes
< 65536 bytes
```

این محاسبه فقط buffers تعریف‌شده را پوشش می‌دهد؛ copies، decoded objects و TLS buffers تضمین‌شده نیستند. cap باید aggregate باشد، نه cap جداگانهٔ هر buffer.

ابهام pipelining:

```
یک read شامل OBSERVE اول و دوم
→ frame دوم queued می‌شود
→ پاسخ اول تکمیل می‌شود
→ frame دوم dequeue می‌شود
```

اگر prohibition فقط هنگام dequeue بررسی شود، arrival غیرمجاز پنهان می‌شود. adapter باید arrival-state و protocol phase را حفظ کند و دومین attempt بی‌پاسخ را طبق قرارداد رد کند.

partial/coalesced reads، decoder مستقل و deadline بدون تمدید مناسب‌اند. enqueue، parser errors، backpressure و owner ordering هنوز نیازمند implementation evidence هستند. D3A-09 partially resolved است.

**توالی‌های adversarial؛ بدون اجرا**

| سناریو                   | پیش‌شرط و حمله                              | rejection/invariant لازم                           | uncertainty باقی               |
| ------------------------ | ------------------------------------------- | -------------------------------------------------- | ------------------------------ |
| Endpoint جعلی            | listener محلی با certificate نامنطبق        | قبل از HELLO رد؛ identity مستقل از localhost       | PHP certificate matching       |
| Transcript replay        | confirmation اتصال A روی B                  | binding mismatch؛ بدون SESSION                     | exporter interoperability      |
| Mixed snapshots          | manifest G با blob نامنطبق                  | digest/coherence failure؛ بدون partial publication | schema و durability            |
| Revocation race          | revocation commit پیش از result             | discard؛ reservation تا cleanup حفظ شود            | delivery freshness و clock     |
| Issuance race            | publication وسط handshake عوض شود           | rejection؛ session قبلی صادر نشود                  | schema bindings نهایی          |
| Result/invalidation race | invalidation بین writer check و send        | fenced hand-off یا suppression تعریف‌شده           | send gate                      |
| Malformed ERROR          | session/digest/state نامنطبق                | client رد کند؛ بدون retry/release                  | error matrix                   |
| False observation        | worker query failure را صفر/false اعلام کند | Controller نتیجه را نامطمئن بداند                  | native independent evidence    |
| Clock overflow           | deadline arithmetic از uint64 عبور کند      | clock failure؛ بدون wrap/admission                 | provider و cleanup supervision |
| Queue exhaustion         | چند attempt coalesced و queue پر            | arrival violation/backpressure؛ buffer bounded     | adapter و native I/O           |
| Controller crash         | admission committed، پاسخ نرسیده            | مصرف دائمی؛ instance جدید؛ بدون redispatch         | journal recovery و cleanup     |

**وضعیت دوباره‌ارزیابی‌شدهٔ نه یافته**

| ID     | وضعیت                    | evidence و ریسک باقی                                                  | حداقل اقدام                     |
| ------ | ------------------------ | --------------------------------------------------------------------- | ------------------------------- |
| D3A-01 | `PARTIALLY_RESOLVED`     | profile مشخص‌تر؛ TLS/PHP/exporter و revocation freshness تأیید نشده   | profile conformance evidence    |
| D3A-02 | `PARTIALLY_RESOLVED`     | manifest binding مناسب؛ equality/replay/lease و schemas نیازمند تکمیل | D3C-01 و snapshot specification |
| D3A-03 | `PARTIALLY_RESOLVED`     | owner ordering مناسب؛ writer hand-off مبهم                            | D3C-04                          |
| D3A-04 | `REOPENED`               | A1 درست؛ enum/correlation کامل نیست                                   | D3C-02                          |
| D3A-05 | `PARTIALLY_RESOLVED`     | truth conditions بهتر؛ native truth و mapping کامل باقی               | native mappings و evidence      |
| D3A-06 | `CLOSED_AT_DESIGN_LEVEL` | typed canonicalization و digest separation روشن                       | golden vectors در مرحلهٔ مجاز   |
| D3A-07 | `PARTIALLY_RESOLVED`     | activation قبل از SESSION درست؛ schema patch یکپارچه نیست             | D3C-03                          |
| D3A-08 | `PARTIALLY_RESOLVED`     | representation امن‌تر؛ provider/adjustment نامعلوم                    | clock specification/evidence    |
| D3A-09 | `PARTIALLY_RESOLVED`     | budget سازگار؛ arrival/pipelining و adapter تأیید نشده                | D3C-05                          |

C1 و C2 همچنان بازند. C3 و H1–H4 نیز با طراحی synthetic رفع نشده‌اند. مرحلهٔ امن بعدی، **اصلاح design-only پنج ابهام D3-C و ارائهٔ specification یکپارچه برای review مستقل** است؛ نه implementation یا native testing.

```
PHASE=MODEL_B_SECURITY_CONTRACT_REVIEW_D3_C

FILES_CREATED=0
FILES_MODIFIED=0

D3A_01_STATUS=PARTIALLY_RESOLVED
D3A_02_STATUS=PARTIALLY_RESOLVED
D3A_03_STATUS=PARTIALLY_RESOLVED
D3A_04_STATUS=REOPENED
D3A_05_STATUS=PARTIALLY_RESOLVED
D3A_06_STATUS=CLOSED_AT_DESIGN_LEVEL
D3A_07_STATUS=PARTIALLY_RESOLVED
D3A_08_STATUS=PARTIALLY_RESOLVED
D3A_09_STATUS=PARTIALLY_RESOLVED

NEW_CRITICAL_FINDINGS=0
NEW_HIGH_FINDINGS=1
NEW_MEDIUM_FINDINGS=3
NEW_LOW_FINDINGS=1

CONFIRMED_DESIGN_CONFLICTS=0
UNRESOLVED_DESIGN_AMBIGUITIES=5_NEW_OR_REFINED_CONTRACT_GAPS
IMPLEMENTATION_FEASIBILITY_GAPS=2_ORIGINAL_TLS_EXPORTER_AND_NATIVE_CLOCK_GAPS_REMAIN

C1_STATUS=OPEN
C2_STATUS=OPEN
C3_STATUS=OPEN

H1_STATUS=OPEN
H2_STATUS=OPEN
H3_STATUS=OPEN
H4_STATUS=OPEN

REVIEW_DECISION=REVIEW_BLOCKED
IMPLEMENTATION_READY=NO
NEXT_SAFE_ACTION=SEPARATELY_AUTHORIZED_DESIGN_ONLY_CORRECTIONS_FOR_D3C_01_TO_05

NATIVE_CONTROLLER_IMPLEMENTED=NO
VM_START_AUTHORIZED=NO
VM_CREATED=NO
VM_STARTED=NO
MYSQL_STARTED=NO
MIGRATIONS_EXECUTED=NO
FULL_PROOF_EXECUTED=NO
REAL_LOCAL_RUNTIME_VERIFIED=NO
```

بازبینی پایان یافت؛ هیچ فایل تغییر نکرد و implementation آغاز نشد.