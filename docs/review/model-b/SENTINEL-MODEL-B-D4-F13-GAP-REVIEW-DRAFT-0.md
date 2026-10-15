# SENTINEL Model B — Preserved Independent Gap-Review Draft

| Metadata | Value |
|---|---|
| Document ID | SENTINEL-MODEL-B-D4-F13-GAP-REVIEW |
| Document Version | DRAFT-0 |
| Preservation Phase | MODEL_B_D4_F14_VERSIONED_GAP_REVIEW_PACKAGE_PRESERVATION |
| Source Phase | MODEL_B_D4_F13_INDEPENDENT_GAP_REVIEW_PACKAGE_PREPARATION |
| Document Status | DRAFT_FOR_GAP_REVIEW |
| Approval Status | NOT_APPROVED |
| Independent Review | NOT_PERFORMED |
| Historical Provenance | PENDING |
| Historical Source Classification | RECOVERED_CONVERSATION_COPY / PROVENANCE_PENDING |
| Implementation Authorization | NO |
| Gate 1 | NOT_APPROVED |
| Creation Date | 2026-10-13 (Asia/Tehran; client local date) |
| Repository HEAD | 796e5dd824c159fcbe048c879e732982925f478c |
| D3-E Original Availability | NO — baseline finding, not a new historical search |

## Preservation boundary

This file preserves the complete D4-F13 conversation report below. Its historical statements, references, proposals, unknowns, and machine-readable statuses belong to the D4-F13 source phase. In particular, statements such as CHAT_ONLY, FILES_CREATED=0, and no document written to disk describe that earlier phase, not this D4-F14 preservation action.

D4-F14 adds this metadata and durable Markdown preservation only. It does not approve a contract, establish independent acceptance, authorize implementation, verify historical authorship, or close any finding. The future independent reviewer template remains blank. Recovered historical reports retain PROVENANCE_PENDING. The file's own digest is reported externally and is not embedded here.

## Complete preserved D4-F13 source report

**بستهٔ پیشنهادی review مستقلِ شکاف‌ها در همین گفتگو آماده شد؛ وضعیت آن `DRAFT_FOR_GAP_REVIEW` است.** hash منابع با baseline مطابقت دارد. پنج findingِ D3-C به اصلاحات D3-D نگاشت شدند و هر ۹ شکاف ثبت شد؛ هیچ finding، شکاف، اصلاح یا معماری در این فاز پذیرفته نشد.

**A. خلاصهٔ اجرایی**

این بسته برای بازبینی **شکاف‌های قرارداد و شواهد** است. نتیجهٔ D4-F12 دربارهٔ فقدان D3-E اصلی، baseline این فاز باقی ماند؛ جست‌وجوی تاریخی گستردهٔ تازه انجام نشد.

تفکیک شواهد در سراسر بسته:

| برچسب | معنا |
|---|---|
| `DIRECT_HISTORICAL_EVIDENCE` | statement صریح در نسخهٔ تاریخی بازیابی‌شده؛ اصالت تاریخی آن هنوز pending است |
| `DERIVED_REPORT` | تحلیل‌های F9–F12 و گزارش‌های محلی مشتق‌شده |
| `SYNTHETIC_IMPLEMENTATION` | رفتار کد fixture؛ فاقد authority قراردادی |
| `INFERENCE` | تفسیر، پرسش review، معیار پیشنهادی یا طرح شواهد این بسته |
| `MISSING_EVIDENCE` | تعریف، پذیرش یا proof موجود نیست |

**تمام پرسش‌ها، معیارهای acceptance، نقش‌های پیشنهادی و برنامه‌های evidence در این پاسخ `INFERENCE` هستند؛ الزام تازهٔ تصویب‌شده نیستند.** statements تاریخی و کمبودها در کنار آن‌ها جدا مشخص شده‌اند.

**B. ایمنی workspace**

دستورهای applicable از `C:\laragon\www\AGENTS.md` و اسناد حاکمیتی ارجاع‌شده خوانده شدند. ریشهٔ Git:

```text
C:\laragon\www\deployment-system
HEAD=796e5dd824c159fcbe048c879e732982925f478c
```

مقایسهٔ ماشینی وضعیت پیش و پس از بررسی:

```text
STATUS_UNCHANGED=YES
TRACKED_MODIFICATIONS=7
UNTRACKED_ENTRIES=28
STASHES=0
```

**عدد ۲۷ برای untracked در گزارش‌های قبلی اصلاح می‌شود؛ شمارش دقیق ۲۸ است.** فهرست این نوبت قبل و بعد یکسان بود؛ این تصحیح به معنی ایجاد فایل تازه نیست.

تغییرات tracked موجود، سه `agent.php`، دو AgentJob controller و مدل‌های Agent/LocalAgent بودند. تمام untrackedها، شامل `_reference/`، اسناد، synthetic code، آزمون‌های موجود و proof tooling حفظ شدند.

هیچ فایل، تنظیمات، قرارداد، branch یا stash تغییر نکرد. هیچ تست، build، Artisan، migration، database، network، native، service، production، commit، push یا deploy انجام نشد. بسته روی disk نوشته نشد.

**C. موجودی شواهد تاریخی**

هر منبع پیش از استفاده hash شد و متن کامل آن خوانده شد. hash نهایی نیز یکسان بود.

| شناسهٔ ارجاع | مسیر دقیق | بایت / خطوط | شناسهٔ داخلی |
|---|---|---:|---|
| D2 | [SENTINEL_D2_Report.md](/C:/laragon/www/deployment-system/_reference/recovered-history/SENTINEL_D2_Report.md:1) | 32012 / 377 | `MODEL_B_TRUST_IDENTITY_PROTOCOL_DESIGN_2` |
| B | [SENTINEL_D3B_Report.md](/C:/laragon/www/deployment-system/_reference/recovered-history/SENTINEL_D3B_Report.md:1) | 36781 / 492 | `MODEL_B_SECURITY_CONTRACT_REMEDIATION_D3_B` |
| C | [SENTINEL_D3C_Original_Conversation_Report.md](/C:/laragon/www/deployment-system/_reference/recovered-history/SENTINEL_D3C_Original_Conversation_Report.md:1) | 21713 / 292 | `MODEL_B_SECURITY_CONTRACT_REVIEW_D3_C` |
| D | [SENTINEL_D3D_Report.md](/C:/laragon/www/deployment-system/_reference/recovered-history/SENTINEL_D3D_Report.md:1) | 46375 / 518 | `MODEL_B_CONSOLIDATED_CONTRACT_CORRECTION_D3_D` |
| F | [SENTINEL_D4B_Report.md](/C:/laragon/www/deployment-system/_reference/recovered-history/SENTINEL_D4B_Report.md:3) | 44944 / 516 | `MODEL_B_BASELINE_INTEGRITY_REVIEW_D4_B` |
| DUP | [SENTINEL_D3C_Report.md](/C:/laragon/www/deployment-system/_reference/recovered-history/SENTINEL_D3C_Report.md:480) | 46375 / 518 | شناسه و محتوای D3-D |

در ادامه، ارجاع‌هایی مانند «C:۵۶–۷۴» به **همان مسیر دقیق جدول بالا** و بازهٔ خطوط آن اشاره دارند.

```text
D2 SHA256:
A162CCB9136EAAB558C29EF8A31DB65EE936E89C1A20A4B163C2A911794B321A

B SHA256:
4A58C427F17233DAC136BFBC2D5E715FE6E21DD315BCFBC12A8B3EDB87E0AAAF

C SHA256:
02B50F68FBB27AFD0D0E14E896D03A927C4A3C22213E7891C8407C2E564AF55E

D و DUP SHA256:
1E20579F6C833794971B61B025FA68FA9D2D4FAD5D0488DDCFB1FFC283180228

F SHA256:
62984AAB4A1E15A3506A31AA6CA9A560205AC5D04C567B4DFAB6FC55A79CED81
```

طبقه‌بندی تمام نسخه‌ها:

```text
RECOVERED_CONVERSATION_COPY
PROVENANCE_PENDING
```

DUP شاهد مستقل نیست. C با تصمیم `REVIEW_BLOCKED`، B را بررسی کرده است؛ C:۷،۲۷۸. D review مستقل اصلاحات بعدی را می‌خواهد؛ D:۴۷۷. F نتیجهٔ D3-E را نقل می‌کند، ولی record اصلی و hash binding آن را نمی‌دهد؛ F:۱۷،۳۷۸.

F9–F12 در گفتگو و اسناد recovery-gap در `docs/spec` و `docs/review`، `DERIVED_REPORT` هستند. نام «acceptance» یا hash ثابت، reviewer identity را ثابت نمی‌کند.

**D. ماتریس disposition پنج findingِ D3-C**

تمام findingها و corrections این جدول `DIRECT_HISTORICAL_EVIDENCE` هستند؛ ارزیابی disposition از F11 حفظ شده است.

| Finding / شدت | requirement اصلی C | correctionِ D | disposition و review class |
|---|---|---|---|
| D3C-01 / HIGH | equality/conflict، lease مطلق، restart، monotonic snapshot state؛ C:۱۳،۵۶–۷۴ | canonical equality، conflict latch، HWM و expiry/commit؛ D:۱۳–۸۷ | `PARTIALLY_RESOLVED_AT_DESIGN_LEVEL`؛ `PARTIALLY_SPECIFIED` |
| D3C-02 / MEDIUM | category/diagnostic/correlation/admitted/state/close برای تمام failureها؛ C:۱۴،۱۰۸–۱۲۲ | C0/CH/CR، enums و failure matrix؛ D:۸۹–۱۹۲ | `RESOLVED_AT_HISTORICAL_DESIGN_LEVEL`؛ `REQUIRES_INDEPENDENT_ACCEPTANCE` |
| D3C-03 / MEDIUM | schema یکپارچه، publication fields، transcript/projection؛ C:۱۵،۱۶۴–۱۶۸ | نه پیام و types، generation/digest، transcript و ACTIVE projection؛ D:۱۹۴–۳۰۱ | `RESOLVED_AT_HISTORICAL_DESIGN_LEVEL` در دامنهٔ schema؛ `REQUIRES_INDEPENDENT_ACCEPTANCE` |
| D3C-04 / MEDIUM | serialized writer hand-off، fence، مرز in-flight و bounded I/O؛ C:۱۶،۸۵–۹۷ | permit، shared fence، atomic-entry requirement و partial-write behavior؛ D:۳۰۳–۳۶۰ | `PARTIALLY_RESOLVED_AT_DESIGN_LEVEL`؛ `REQUIRES_NATIVE_FEASIBILITY` |
| D3C-05 / LOW | arrival-state پیش از dequeue؛ frame دوم زودرس بعداً معتبر نشود؛ C:۱۷،۱۹۹–۲۱۰ | first-plaintext-byte، slot/epoch و coalesced ordering؛ D:۳۶۲–۴۰۵ | `RESOLVED_AT_HISTORICAL_DESIGN_LEVEL`؛ `REQUIRES_INDEPENDENT_ACCEPTANCE` |

**معیارهای پیشنهادی review و evidence لازم برای disposition آینده:**

| Finding | پرسش review | معیار عینی پیشنهادی | کمبود و وابستگی |
|---|---|---|---|
| 01 | آیا equality، rollback و lease همهٔ ورودی‌ها/restartها را بدون ambiguity پوشش می‌دهند؟ | تمام حالت‌های generation کمتر/برابر/بیشتر disposition یکتا داشته باشند؛ replay expiry را تمدید نکند؛ snapshot coherence با schema کامل قابل بررسی باشد | G-03/G-04؛ clock و durable HWM، FG-02/03؛ reviewer روی hash D |
| 02 | آیا هر failure به correlation واقعی و category/diagnostic مجاز متصل است؟ | coverage matrix کامل با eligibility و state واقعی؛ mismatch/unknown journal پاسخ ساختگی نسازند؛ admitted به tuple دقیق وصل باشد | mapping conformance و vectors؛ G-09، FG-03/04؛ پذیرش مستقل |
| 03 | آیا schemaهای نهایی واقعاً جای برداشت‌های پراکندهٔ D2/B را گرفته‌اند؟ | تمام fields/types/phases/unknown-field rules و transcript binding یکپارچه باشند؛ SESSION پس از commit؛ projection از synthetic constructor جدا باشد | canonical grammar؛ non-reuseِ C:۱۶۸ تحت G-06؛ parser/native proof |
| 04 | آیا invalidation و transport-entry ترتیب enforceable دارند؟ | نقطهٔ hand-off/in-flight، permit، shared serialization، would-block و partial-abort صریح؛ primitive bounded با شواهد race/ordering قابل اثبات باشد | انتخاب primitive و bounds؛ G-07، FG-04؛ counters کافی نیست |
| 05 | آیا arrival غیرمجاز با تأخیر queue/dequeue پنهان نمی‌شود؟ | state/epoch پیش از enqueue ثبت شود؛ دو frame یک read، late dequeue و backpressure تصمیم یکتا دهند؛ slot فقط طبق completion باز شود | adapter conformance، aggregate resource evidence؛ G-09، FG-04/05 |

بستهٔ closure آینده برای هر finding باید شامل disposition امضاشده/قابل‌انتساب بازبین، exact source/package hash، vectors مربوط و تفکیک **design acceptance از native proof** باشد. هیچ closure در این فاز صادر نشد.

**E. ثبت شکاف‌های G-01 تا G-09**

تمام gaps بازند. مسئول‌ها و closure criteria زیر **پیشنهادی** هستند.

| Gap / دسته | impact، شاهد تاریخی و سؤال دقیق | evidence/تصمیم لازم، مسئول و dependencies | معیار closure پیشنهادی / سطح |
|---|---|---|---|
| **G-01 — منبع و پذیرش مفقود** | پذیرش بی‌منشأ ممکن است design text را authority جلوه دهد. F:۱۷ claim دارد؛ F:۳۷۸ اصل artifact ندارد. چه کسی کدام bytesِ D را پذیرفته است؟ | D3-E اصلی، reviewer/independence، scope، hash، findings/risks/conditions؛ custodian و reviewer مستقل؛ dependency تمام acceptance claims | record قابل‌انتساب و exact binding، یا review جدید با تاریخ/هویت جدید؛ native لازم نیست؛ review مستقل لازم |
| **G-02 — تعریف inherited A1/A2** | اختلاف timeout یا digest inventory اختیار متفاوت می‌سازد. D2:۲۲۰؛ B:۱۹۲،۲۹۹–۳۰۶؛ D:۴۲۹–۴۳۰. اصل formula و نام/typeهای ۱۲ field چیست؟ | اصل invariantها؛ در فقدان آن، تصمیم تازهٔ جداگانه و صریح؛ مالک قرارداد؛ G-01/G-04 | inventory/formula/type/exclusion کامل و مستقل بررسی شود؛ closure تعریف ممکن است design-level باشد؛ enforcement native بعداً |
| **G-03 — Policy/snapshot schema ناقص** | mix-and-match، rollback یا digest متفاوت. B:۲۸۸ «fields موجود»؛ C:۷۴ schemas لازم. exact objects چیستند؟ | Policy، credential-state، epoch vector و registry schemas، version/coherence rules؛ authority/contract owner؛ G-02/G-04 | همهٔ fields، nested types، bounds و relations قابل بازسازی یکتا باشند؛ design closure ممکن؛ FG-03/06 جدا |
| **G-04 — canonical grammar/vectors ناقص** | bytes برابر از parsed-equivalence تضمین نمی‌شود. D2:۱۲۶؛ B:۲۶۶–۳۱۰؛ D:۴۶. canonical byte sequence دقیق چگونه تعیین می‌شود؟ | grammar تاریخی یا تصمیم صریح مستقل، golden vectors با provenance؛ protocol/crypto owner؛ G-03 | تمام checklist بخش H و expected bytes/digests مشخص، review مستقل؛ تعریف design-level، conformance آینده |
| **G-05 — scope PHP/TLS/IPC** | client capability با authority اشتباه شود. B:۴۷؛ F:۱۲۸. PHP direct TLS client است یا IPC-only، و binding را چه کسی تولید می‌کند؟ | architecture-owner disposition، boundary/dataflow و independent review؛ G-01/G-04/G-07 | نقش هر component و key/exporter/context ownership بدون تناقض تعیین و acceptance ثبت شود؛ design ممکن؛ FG-01 لازم |
| **G-06 — non-reuse contract ناقص** | ID reuse/collision sessionها را混ط می‌کند. C:۱۶۸؛ B:۳۳۳–۳۳۹. scope، retention و collision/restart behavior چیست؟ | contract دقیق بدون الزام storage دلخواه؛ controller/protocol owner؛ G-01/G-03/G-07 | سناریوهای بخش K disposition یکتا و enforceable داشته باشند؛ design ممکن؛ native/conformance آینده |
| **G-07 — clock و bounded entry** | expiry/fence guarantee بدون primitive معتبر. B:۳۷۵–۳۷۷؛ D:۳۵۸،۴۷۵. provider/adjustment و check-plus-entry چگونه bounded می‌شوند؟ | clock policy، bounds و primitive semantics؛ clock/transport/security owners؛ G-05/G-06 | assumptions/units/failure rules مستقل review؛ سپس FG-02/04 proof؛ closure کامل صرف design نیست |
| **G-08 — proof native** | crash/replay/orphan/TOCTOU هنوز ممکن است. C:۷۴،۹۷،۱۳۹؛ F:۴۰۶–۴۰۹. چه evidence invariantها را در OS واقعی ثابت می‌کند؟ | journal/containment/cleanup/identity evidence تحت مجوز جدا؛ reviewers native مستقل؛ G-03/G-07 | package قابل‌تکرار evidence، failure injection و reviewer dispositions؛ native لازم؛ closure design-only ممکن نیست |
| **G-09 — conformance implementation** | schema طراحی با parser اجراشده یکی فرض شود. D:۷،۱۹۶،۱۹۲. implementation آینده دقیقاً چه چیز را enforce می‌کند؟ | schema/version binding، مثبت/منفی/race vectors و validation اجرایی؛ implementation owner در فاز مجاز؛ G-01 تا G-08 حسب scope | evidence conformance برای نسخهٔ implementation مشخص؛ design-only کافی نیست؛ اجرا اکنون ممنوع |

هیچ علت مفقود با چند gap تازه تکثیر نشد؛ dependent dossiers به همین register ارجاع دارند.

**F. پروندهٔ POL-01**

**DIRECT_HISTORICAL_EVIDENCE:** D2:۲۳۱، B:۲۸۸–۲۹۷ و C:۱۵۲–۱۵۶:

```text
policy_digest =
SHA256(
    canonical_bytes(
        typed_policy_without_policy_digest
    )
)
```

- خود fieldِ digest هنگام hashing حذف می‌شود.
- typed registry/map با object و allowlist با array بازنمایی می‌شود؛ B:۲۶۶–۲۷۸.
- manifest، policy digest را به publication coherent متصل و با signature پوشش می‌دهد؛ B:۲۹۵–۲۹۷.
- Grant Digest/A2 مستقل است؛ B:۲۹۹–۳۰۶.
- Manifest Digest روی unsigned manifest است؛ D:۱۳–۱۸.

**MISSING_EVIDENCE:** inventory کامل Policy Object، nested schemas، canonical edge grammar، digest vectors و version-bound acceptance.

**signature question:** D2:۲۳۴ direct policy signing را مطرح می‌کند؛ B:۲۹۷ direct signature تازه را لازم نمی‌داند؛ C:۱۵۳–۱۵۶ manifest coverage را در سطح طراحی می‌پذیرد. بازبین آینده باید scope/version این تغییر را ثبت کند؛ این فاز آن را تصویب نمی‌کند.

**پرسش و معیار پیشنهادی:** آیا دقیقاً یک typed object boundary با همهٔ fields و dependencyها قابل بازسازی است و خروجی canonical/hash برای هر vector یکتاست؟ evidence باید provenanceِ schema و expected bytes/hash داشته باشد؛ خروجی synthetic جای آن را نگیرد.

```text
POL01_STATUS=OPEN
```

non-circular بودن candidate formula تاریخی روشن است؛ completenessِ normative Policy Object هنوز تأسیس نشده. domain separation تازه‌ای اضافه نشد.

**G. پروندهٔ A1/A2**

**A1 — DIRECT_HISTORICAL_EVIDENCE:** B:۱۹۲،۲۱۱؛ C:۱۰۳–۱۰۶؛ D:۱۳۰–۱۳۱،۴۲۹:

- over-effective-limit رد می‌شود؛
- requested timeout بی‌صدا clamp نمی‌شود؛
- cleanup budget predicate جدا از upper execution limit است.

**MISSING_EVIDENCE:** فرمول اصلی effective timeout، units/ownership همهٔ deadlineها و رابطهٔ دقیق worker/observation/cleanup/transport.

فرمول `min(grant.max_timeout_ms, policy.worker_timeout_ms, 5000)` در [synthetic:۱۸۷](/C:/laragon/www/deployment-system/service/app/Sentinel/ModelB/Synthetic/PolicySnapshot.php:187) هست؛ به authority تاریخی تبدیل نشد.

**A2 — inventory محفوظ:**

```text
allowed_operations
allowed_root_ids
allowed_service_ids
application_id
authorization_epoch
credential_epoch
credential_id
max_concurrent_requests
max_timeout_ms
revoked
valid_from
valid_until
```

**DIRECT_HISTORICAL_EVIDENCE:** D2:۲۲۰؛ B:۲۹۹–۳۰۶؛ C:۱۵۴؛ D:۴۳۰، تعداد ۱۲، effective grant و SHA256 canonical relationship را حفظ می‌کنند.

**MISSING_EVIDENCE:** provenance اصلی نام‌ها/typeها، دقیق optional/excluded fields، canonical inventory و golden vectors.

`policy_digest`، `policy_version` و `cleanup_budget_ms` اضافه نشدند. grant digest با policy-bound digest تازه‌ای ادغام نشد.

**review سؤال:** آیا تعریف اصلی A1/A2 recover شده، یا owner باید یک تصمیم جدید را با نام و تاریخ جدید تصویب کند؟ این دو مسیر نباید به‌عنوان recovery تاریخی یکسان ثبت شوند.

**H. پروندهٔ canonical bytes**

| مورد | وضعیت | شاهد / نیاز review |
|---|---|---|
| UTF-8 | `PARTIALLY_SPECIFIED` | D:۱۳–۱۸ برای manifest؛ encoding boundaryها کامل نیست |
| BOM | `MISSING` | تعریف تاریخی صریح پیدا نشده |
| invalid UTF-8 | `MISSING` | rejection grammar تاریخی لازم |
| Unicode normalization | `MISSING` | عدم/نوع normalization مشخص نیست |
| duplicate keys | `EXPLICITLY_SPECIFIED` | D2:۱۲۶؛ رد |
| ASCII key ordering | `EXPLICITLY_SPECIFIED` | D2:۱۲۶؛ general string grammar جدا |
| string escaping | `MISSING` | escape choices و invalid sequences لازم |
| integer encoding | `PARTIALLY_SPECIFIED` | D2:۱۲۷ bounds/type؛ byte grammar کامل inherited |
| decimal-string encoding | `EXPLICITLY_SPECIFIED` | D2:۱۷۸، D:۲۰۵–۲۰۶؛ uint64/no leading zero |
| boolean | `EXPLICITLY_SPECIFIED` | B:۲۷۵؛ D:۲۰۸؛ بدون coercion |
| null | `PARTIALLY_SPECIFIED` | B:۲۴۰ dependent service rules؛ general policy ناقص |
| object/list distinction | `EXPLICITLY_SPECIFIED` | B:۲۶۶–۲۷۸، C:۱۴۸–۱۵۰ |
| unknown-field rejection | `PARTIALLY_SPECIFIED` | schemas موجود بسته‌اند؛ inherited schemas کامل نیستند |
| optional-field omission | `PARTIALLY_SPECIFIED` | listed fields required؛ قواعد عمومی omission ارائه نشده |
| numeric bounds | `EXPLICITLY_SPECIFIED` | D2:۱۲۷،۱۷۸؛ D:۲۰۴–۲۰۶ |
| depth bounds | `EXPLICITLY_SPECIFIED` | D2:۱۲۵؛ depth۶ |
| size bounds | `PARTIALLY_SPECIFIED` | B:۲۸۲–۲۸۶؛ C:۱۵۸ full policy با digest؛ aggregate scopes جدا |
| canonical byte equality | `EXPLICITLY_SPECIFIED` | D:۳۹–۴۶؛ bytes برابر، نه صرف parsed equality |

**معیار پیشنهادی review:** هر item ناقص باید تعریف version-bound و positive/negative golden vectors داشته باشد. vectors از synthetic به‌عنوان authoritative تولید نشوند؛ استاندارد ثالثی به‌طور ضمنی انتخاب نشود.

**I. پروندهٔ manifest/signature**

روابط محفوظ، B:۸۵–۱۰۱ و D:۱۳–۱۸:

```text
M = exact canonical UTF-8 bytes of unsigned manifest
manifest_digest = lowercase_hex(SHA256(M))

signed input =
ASCII("SENTINEL-MODELB-AUTHORITY-PUBLICATION-V1\n")
|| canonical_json(manifest)
```

| checklist | disposition |
|---|---|
| manifest inventory/type/required fields | `HISTORICALLY_SPECIFIED`؛ B:۶۳–۸۳ |
| envelope: manifest + signature hex | `HISTORICALLY_SPECIFIED`؛ B:۸۵–۹۲ |
| authority/key/profile | schema explicit؛ B:۶۷،۷۹–۸۰؛ provenance پذیرش pending |
| protected key allowlist | `HISTORICALLY_SPECIFIED`؛ B:۱۰۱ |
| generation positivity | B:۸۳؛ comparison تکمیل در D:۳۴–۴۴ |
| validity interval | D:۶۴–۶۹ |
| policy/credential/registry digests | fields مشخص؛ snapshot byte schemas ناقص؛ G-03/G-04 |
| verification ordering | B:۱۰۳–۱۱۲ |
| rollback/HWM | D:۲۰–۵۷ |
| algorithm | Ed25519 **candidate**؛ B:۱۰۱؛ implementation تصویب نشده |
| canonical reproducibility | `PARTIALLY_SPECIFIED`؛ G-04 |
| golden bytes/signature vectors | `MISSING_EVIDENCE`؛ B:۱۳۲،۳۱۰ درخواست evidence |

signature و manifest_digest داخل M نیستند. هیچ field، domain prefix یا signing input تغییر نکرد.

**J. رکورد پیشنهادی تصمیم trust boundary**

```text
DECISION_RECORD_STATUS=PROPOSED_UNDECIDED
QUESTION=نقش مصوب PHP در transportِ Model B چیست؟
DECISION_OWNER=تعیین‌نشده؛ نقش پیشنهادی: مالک معماری و امنیت پروتکل
DECISION=خالی
APPROVAL=خالی
```

**شواهد مستقیم:**

- D2:۲۵–۴۳ PHP client و Controller owner را جدا می‌کند.
- B:۴۷ PHP/Controller exporter participation را فرض اثبات‌نشده می‌داند.
- C:۳۶–۴۷ محل API و interoperability را باز نگه می‌دارد.
- F:۶۱–۹۲ و۱۲۸ PHP را untrusted و IPC-only معرفی می‌کند.

**تفسیرهای قابل review، بدون انتخاب:**

1. participationِ B مربوط به TLS client سمت PHP است؛ enforcement همچنان Controller-owned.
2. F مسیر مستقیم PHP را محدود کرده و native component/IPC را جای آن گذاشته؛ پذیرش refinement لازم است.
3. scopeهای دو سند متفاوت‌اند؛ component boundary و binding delivery باید این تفاوت را نشان دهند.

**پیامد امنیتی:** مالک key/exporter/context، authentication سمت‌ها، trustِ IPC و failure behavior ممکن است تغییر کند. ordinary TLS client behavior معادل authoritative enforcement نیست.

**evidence لازم:** owner disposition، diagram/dataflow، exact scope، credential/key access matrix، reviewer مستقل و interoperability proofِ FG-01. هیچ معماری انتخاب نشد.

**K. پروندهٔ session-ID non-reuse**

**DIRECT_HISTORICAL_EVIDENCE:** C:۱۶۸، ID برای session دیگر در همان instance reuse نشود، حتی پس از terminal شدن.

| پرسش review | شاهد و کمبود |
|---|---|
| ID را چه کسی تولید می‌کند؟ | Controller؛ D2:۱۶۵، B:۳۳۳ |
| collision چگونه بدون صدور session دوم حل می‌شود؟ | contract اجرایی کامل مفقود |
| پس از terminal، چه tracking/retention لازم است؟ | requirement C روشن؛ strategy انتخاب نشده |
| restart چه scope جدیدی می‌سازد؟ | instance تازه و invalidation؛ D2:۲۲۲، D:۷۹ |
| reconnect session را احیا می‌کند؟ | ممنوع؛ B:۳۳۹ |
| duplicate AUTH_CONFIRM؟ | close، بدون SESSION دوم؛ B:۳۳۷ |
| lost SESSION؟ | reissuance همان committed session ممنوع؛ B:۳۳۷، C:۱۶۸ |
| activation commit کجاست؟ | پیش از SESSION enqueue؛ B:۳۳۰–۳۳۷، D:۲۴۴،۲۹۹ |
| invalidation چگونه retention را حفظ می‌کند؟ | منع reactivation روشن؛ tracking proof مفقود |

**معیار پیشنهادی:** هر سناریو disposition یکتا داشته باشد و uniqueness scope و failure behavior تعریف شوند. storage mechanism تجویز نشد. رفتار `isset` در synthetic proof اجرای native نیست.

**L. پروندهٔ publication/recovery**

| checklist | statement تاریخی / disposition |
|---|---|
| برابر generation + bytes برابر | idempotent؛ بدون lease extension؛ D:۳۹ |
| برابر generation + bytes متفاوت | conflict/latch؛ D:۴۰ |
| generation پایین‌تر | reject؛ D:۳۸ |
| generation بالاتر | verified coherent candidate، نه activation خودکار؛ D:۴۱–۴۳ |
| absolute expiry | D:۶۴–۶۹؛ replay deadline نمی‌سازد |
| HWM | D:۲۰–۳۲؛ protected durable state requirement |
| credential epochs/versions | D:۵۰–۵۷؛ rollback و same-version substitution ممنوع |
| registry identity/generation | D:۵۴–۵۶؛ schema کامل G-03 |
| durable commit | D:۷۱–۷۷؛ native proof G-08/FG-03 |
| restart/reconciliation | D:۷۹؛ session قبلی احیا نمی‌شود |
| conflict latch | D:۴۰،۴۴؛ reconciliation معتبر لازم |
| freshness | D:۸۳–۸۵؛ trusted clock/delivery dependency |
| revocation freshness | C:۳۹،۷۲؛ criterion کامل و proof لازم |

**معیار پیشنهادی:** snapshot inventories و comparisons complete باشند؛ review design و crash evidence جدا disposition بگیرند. logical transition، native durable guarantee محسوب نشود.

**M. طرح شواهد FG-01 تا FG-06**

این بخش **طرح پیشنهادی برای آینده** است؛ هیچ experiment مجاز یا اجراشده اعلام نمی‌شود.

**شرایط ایمنی مشترک:** مجوز مستقل پیش از اجرا؛ محیط Windows هدفِ version-pinned و جدا از production؛ داده، certificate و key آزمایشی؛ دسترسی محدود و bounded؛ artifacts عاری از secrets؛ fault injection فقط در محیط disposable؛ هیچ اثر بر workspace فعلی.

| FG | claim و precondition | محیط/observables موردنیاز | pass/fail پیشنهادی، reviewer و dependency |
|---|---|---|---|
| **01** | mTLS/exporter/cert binding صحیح؛ profile و scope PHP/native ابتدا مشخص شود. B:۱۳–۴۸، C:۳۶–۵۰ | endpointهای آزمایشی مطابق مسیر مصوب؛ exporter bytes agreement، cert→credential/epoch mapping، failure/rotation records | pass: دو طرف binding یکسان، identity مبهم/stale رد، epoch قبلی invalidate؛ fail: fallback، mapping دلخواه، mismatch accepted. reviewer TLS/PKI مستقل؛ G-05/G-04 |
| **02** | زمان معتبر و expiry با rollback/suspend/restart دور زده نشود. B:۳۴۶–۳۸۲ | Windows/provider ثبت‌شده؛ raw counter/conversion، suspend/discontinuity/rollback و instance logs | pass: checked arithmetic، failure محافظه‌کارانه، بدون expiry extension؛ fail: wrap، reset bypass، rollback undetected. reviewer clock/OS؛ G-07 |
| **03** | admission/consumption/reservations/publication crash-safe. D:۷۱–۷۹، F:۱۵۱–۱۸۲ | storage آزمایشیِ بعداً انتخاب‌شده؛ pre/post durable records، crash/torn-write/corruption recovery traces | pass: consumed replay رد، HWM محفوظ، reservation/blocked state معتبر؛ fail: redispatch، rollback، leak یا publication جزئی. reviewer persistence/security؛ G-03/G-07 |
| **04** | invalidation با bytes-entry fence مشترک و bounded ordering دارد. D:۳۳۹–۴۰۵ | native backendِ بعداً بررسی‌شده؛ owner/writer ordering، offsets، would-block، invalidation، coalesced ingress و restart traces | pass: قبل entry suppress، پس partial abort/close، بدون resend، early arrival پنهان نشود؛ fail: check/write race، unbounded wait، invalid frame acceptance. reviewer concurrency/transport؛ G-05/G-07 |
| **05** | containment و cleanup معتبر پیش از release. F:۲۰۲–۲۴۸ | launcher/worker آزمایشی، principals محدود؛ token/job/child/handle evidence، crash/termination و چهار commit-state trace | pass: no escape/orphan، job empty، cleanup durable سپس release؛ fail: ack-only release، breakaway، orphan یا release زودهنگام. reviewer Windows security؛ G-07/G-08 و FG-03 |
| **06** | resource/binary واقعی با registry و point-of-use binding منطبق است. C:۱۳۹، F:۳۳۹–۳۴۹ | resources آزمایشی کنترل‌شده؛ rename/reparse/substitution، handle/file/volume/service و execution identity records | pass: هدف واقعی منطبق یا fail closed؛ signature-alone کافی نباشد؛ fail: path substitution یا identity mismatch accepted. reviewer Windows/resource security؛ G-03/G-08 |

قابلیت API، نتیجهٔ آزمون و کفایت threat model باید جدا review شوند. شکست یک implementation path به‌تنهایی architecture invalidation نیست؛ F:۲۵۴–۲۷۵.

**N. گراف وابستگی review**

```text
هویت محلی و hash منابع
        |
        v
provenance تاریخی / اصل acceptance           [G-01]
        |
        v
کامل‌بودن تعریف‌های normative                [G-02..G-04, G-06]
        |
        v
تصمیم‌های scope و assumptions                [G-05, G-07]
        |
        v
review مستقل طراحی با exact hash binding
        |                         \
        v                          \ پس از مجوز جداگانه
ارزیابی مستندات Gate 1              v
(در این فاز تأیید نمی‌شود)       شواهد native feasibility [FG-01..FG-06]
                                   |
                                   v
                        بازخورد به ارزیابی gateها/risks
                                   |
                                   v
                    conformance implementation آینده [G-09]
```

این گراف dependency شواهد است، **دستور اجرا یا معیار تازهٔ Gate 1 نیست**. F:۴۰۴–۴۰۹ آزمایش‌ها را به gateهای آینده نسبت می‌دهد؛ F:۴۳۳–۴۵۳ Gate 1 را پیش از آن‌ها blocked ثبت می‌کند. proofهای native را به‌صورت خودسرانه prerequisite تازهٔ Gate 1 نکرده‌ایم.

بررسی provenance، canonical inventory و schema gaps می‌تواند موازی انجام شود. review جزئیِ gaps پیش از completeness ممکن است؛ acceptance نهاییِ unresolved definitions ممکن نیست. FG-05 durability به FG-03 وابسته است؛ transport/clock/resource evidence نیز حسب scope تصمیم‌ها dependency دارد. هیچ parallel experiment اکنون مجاز نیست.

**O. manifest پیشنهادی بستهٔ review**

```text
Package identifier پیشنهادی:
SENTINEL-MODEL-B-D4-F13-GAP-REVIEW

Package version پیشنهادی:
DRAFT-0

Package status:
DRAFT_FOR_GAP_REVIEW

Storage:
CHAT_ONLY

Authority:
NONE_NEW

Approval signatures:
NONE
```

اجزای منطقی بستهٔ آینده:

| جزء | محتوای لازم |
|---|---|
| source inventory/hash manifest | بخش C؛ پنج منبع مستقل محتوایی و duplicate جدا |
| provenance register | recovered copy، custodian/author/reviewer evidence و missing D3-E |
| D3-C dispositions | finding matrix و historical severities |
| D3-D correction mapping | exact corrections، acceptance pending |
| gap register | G-01 تا G-09، dependencies و closure evidence |
| POL-01 dossier | formula محفوظ و Policy/schema gaps |
| A1/A2 dossier | behavior محفوظ، inventory protection و missing provenance |
| canonical dossier | checklist و golden-vector gaps |
| manifest/signature dossier | schema، signed input و verification dependencies |
| trust decision record | سؤال PHP/TLS/IPC، بدون انتخاب |
| session-ID dossier | scope/retention/collision/issuance questions |
| publication/recovery dossier | equality/HWM/expiry/commit/restart |
| FG evidence plan | claims، observables و future safety authorization |
| reviewer form | فرم خالی بخش P |
| open-risk register | بخش Q |
| derived context | F9–F12 با برچسب DERIVED_REPORT؛ authority تازه ندارند |
| change/approval trace آینده | هر تغییر یا disposition با نسخه، hash، owner و reviewer |

هیچ file، package digest، versioned artifact یا approval signature واقعی ساخته نشد.

**P. فرم خالی بازبین مستقل**

فرم زیر فقط template است؛ هیچ reviewer یا decision در آن ثبت نشده است.

```text
Reviewer identity:
Reviewer role:
Independence declaration:
Review date:

Reviewed package identifier:
Reviewed package version:
Exact source hashes:
Exact package/artifact identity:

Review scope:
Excluded scope:

Finding dispositions:
  D3C-01:
  D3C-02:
  D3C-03:
  D3C-04:
  D3C-05:

Gap dispositions:
  G-01:
  G-02:
  G-03:
  G-04:
  G-05:
  G-06:
  G-07:
  G-08:
  G-09:

Accepted risks:
Rejected assumptions:
Conditions:
Required follow-up evidence:
Unresolved findings:

Final review decision:
Reviewer attribution / approval record:
```

گزینه‌های مجاز **برای تصمیم آیندهٔ reviewer**:

```text
REVIEW_PASS
REVIEW_PASS_WITH_RISKS
REVIEW_BLOCKED
NOT_REVIEWED
```

هیچ گزینه‌ای انتخاب نشده است. design acceptance، feasibility acceptance و Gate approval باید scopes جدا داشته باشند.

**Q. ریسک‌های باز**

این register **تفسیر تحلیلی** از gaps است؛ accepted risks یا severity تازهٔ تاریخی نیست.

| ریسک | ریشه / اثر |
|---|---|
| R-01: پذیرش بی‌منشأ | G-01؛ claim یا filename به‌جای independent acceptance استفاده شود |
| R-02: اختلاف scope/timeout | G-02؛ فرمول synthetic یا inventory بدون provenance normative فرض شود |
| R-03: اختلاف canonical bytes | G-03/G-04؛ digest/signature روی objectهای متفاوت ساخته شود |
| R-04: confused authority | G-05؛ PHP client capability با controller authority ادغام شود |
| R-05: session identity reuse | G-06؛ terminal/reconnect/collision به session دیگری attach شود |
| R-06: expiry/send race | G-07؛ clock adjustment یا gapِ check/entry، دادهٔ نامعتبر منتشر کند |
| R-07: crash/orphan/TOCTOU | G-08؛ release، consumption یا identity بدون proof بومی معتبر فرض شود |
| R-08: false conformance | G-09؛ complete design schema با parser/native implementation verified یکی شمرده شود |

accepted-risk owner و reviewer disposition برای تمام ردیف‌ها خالی است. شش FG همچنان `NOT_VERIFIED` هستند.

**R. کوچک‌ترین اقدام امن بعدی**

**مالک معماری، نقش reviewer مستقل و scopeِ review جدید را تعیین کند و منابع مفقود G-01 تا G-04 را برای همین hash baseline فراهم کند.** سپس بازبین می‌تواند این draft را برای gap review بررسی و dispositions صریح ثبت کند.

اگر اصل D3-E بازیابی نشود، record آینده باید **review جدید** باشد، نه بازسازی پذیرش تاریخی. پیش از هر experiment، implementation یا contract change، authorization و scope جدا لازم است. در این فاز چنین مجوزی صادر نشد.

**S. وضعیت نهایی**

```text
PHASE=MODEL_B_D4_F13_INDEPENDENT_GAP_REVIEW_PACKAGE_PREPARATION

HISTORICAL_SOURCES_VERIFIED=YES_LOCAL_PATH_PHASE_AND_EXPECTED_HASH_IDENTITY_ONLY
SOURCE_PROVENANCE_VERIFIED=NO

D3C_FINDINGS_MAPPED=5
D3D_CORRECTIONS_ACCEPTED=NO_ACCEPTANCE_NOT_ESTABLISHED

GAPS_REGISTERED=9
GAPS_CLOSED=0

POL01_STATUS=OPEN
A1_STATUS=PARTIALLY_SPECIFIED_ORIGINAL_EFFECTIVE_FORMULA_MISSING
A2_STATUS=TWELVE_FIELDS_PRESERVED_ORIGINAL_INVENTORY_PROVENANCE_PENDING
CANONICAL_BYTES_STATUS=PARTIALLY_SPECIFIED_GOLDEN_VECTORS_MISSING
TRUST_BOUNDARY_STATUS=REQUIRES_ARCHITECTURAL_DECISION_ON_PHP_TLS_IPC_SCOPE

REVIEW_PACKAGE_STATUS=DRAFT_FOR_GAP_REVIEW
REVIEW_PACKAGE_FILES_CREATED=0

INDEPENDENT_REVIEW_PERFORMED=NO
INDEPENDENT_ACCEPTANCE_ESTABLISHED=NO

FG01_STATUS=NOT_VERIFIED
FG02_STATUS=NOT_VERIFIED
FG03_STATUS=NOT_VERIFIED
FG04_STATUS=NOT_VERIFIED
FG05_STATUS=NOT_VERIFIED
FG06_STATUS=NOT_VERIFIED

GATE1_APPROVED=NO
IMPLEMENTATION_READY=NO

FILES_CREATED=0
FILES_MODIFIED=0
TESTS_EXECUTED=NO
DATABASE_WRITES=NO
NATIVE_EXECUTION=NO

COMMIT_EXECUTED=NO
PUSH_EXECUTED=NO
DEPLOY_EXECUTED=NO

NEXT_SAFE_ACTION=ASSIGN_INDEPENDENT_REVIEWER_AND_SCOPE_SUPPLY_MISSING_PROVENANCE_AND_CONTRACT_DEFINITIONS_THEN_REVIEW_THIS_HASH_BOUND_DRAFT_WITHOUT_IMPLEMENTATION

STOP.
```

