---

# گزارش اصلاح و بررسی یکپارچتی: SENTINEL — PHASE 2N-B3-M3-D4-B
## بازبینی پایه مشخصات و اصلاح برنامه اثبات امکان‌سنجی

---

### 1. ارزیابی اجرایی (Executive Assessment)

**وضعیت ورودی (D4-A Output):**
- SPEC_BASELINE_STATUS=CANDIDATE_DEFINED
- SPEC_VERSIONED_IN_REPOSITORY=NO
- SPEC_HASH_AVAILABLE=NO
- IMPLEMENTATION_READY=NO
- تمام ۶ FG: PLANNED_EXPERIMENT (هیچ‌کدام NOT_VERIFIED نیستند — در فاز D4-A به عنوان PLAN_DEFİNED تصنيف شده بودند)

**پاداش D3-E:** REVIEW_PASS_WITH_RISKS — طراحی منطقی در مرزهای اعتماد انتزاعی تأیید شده، اما **آمادگی پیاده‌سازی بومی تاسیس نشده**.

**محدودیت‌های چالش‌برانگیز در D4-A که نیاز به اصلاح دارند:**

| مورد D4-A | مشکل | اصلاح D4-B |
|-----------|------|------------|
| FG-01: ادعای "PHP Stream + OpenSSL" به عنوان گزینه پیشنهادی | نقض مرز اعتماد — PHP = UNTRUSTED CLIENT | باید **ARCHITECTURE_INCOMPATIBLE** برچسب‌گذاری شود؛ TLS termination باید در NATIVE CONTROLLER باشد |
| FG-01: ادعای TLS Exporter در PHP 8.2+ بدون اثبات | NOT_VERIFIED اما به عنوان API FEASIBILITY عرضه شده | باید به API EXPLORATION تنزل یابد؛ alternative native helper مورد ارزیابی |
| FG-03: ادعای SQLite WAL به عنوان PERSISTENCE_MECHANISM_CANDIDATE با "verified" implication | In-memory synthetic invariant ≠ native durability | باید صریحاً IN_MEMORY_SYNTHETIC_INVARIANT vs NATIVE_DURABILITY_REQUIREMENT تفکیک شود |
| FG-04: جایگزینی نامتقارن Named Pipes برای mTLS | D3-D candidate = LOOPBACK TCP + MUTUAL TLS | Named Pipes = ARCHITECTURAL ALTERNATIVE، نه ACCEPTED CONTRACT |
| FG-05: ادعای `WaitForSingleObject(job_handle)` اثبات تمیزکاری | Process tree exit ≠ job empty ≠ cleanup recorded ≠ reservation released | باید ۴ وضعیت متمایز تعریف و ordering الزامی مشخص شود |
| FG-05: PHP FFI به عنوان گزینه پیشنهادی | PHP FFI failure ≠ Model B invalidation | Kill criteria باید CANDIDATE_IMPLEMENTATION_FAILURE vs ARCHITECTURE_INVALIDATION تفکیک شود |
| FG-02: `hrtime()` به عنوان monotonic clock provider | Suspend/resume behavior در Windows نامشخص | باید جداسازی clock requirements از candidate capabilities انجام شود |
| FG-06: ادعای signature check مانع substitution | TOCTOU بین verification و execution | Handle-based یا equivalent evidence الزامی |

**نتیجه کلی:** پایه مشخصات D4-A **برای Gate 1 review-ready نیست** — حاوی ادعاهای طراحی که با مرز اعتماد Model B ناسازگارند، ادعاهای permanency بدون اثبات، و ترکیبیابی سطح‌های اثبات است.

---

### 2. اصلاحات مرز اعتماد (Trust-Boundary Corrections)

**مرز اعتماد Model B پذیرفته شده (از D3-D/D3-E):**

```
┌─────────────────────────────────────────────────────────────┐
│                    POLICY/CREDENTIAL AUTHORITY              │
│              (SEPARATELY PROTECTED TRUST SOURCE)            │
└─────────────────────────┬───────────────────────────────────┘
                          │ Policy/Credential Distribution
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                  NATIVE CONTROLLER = TRUSTED OWNER          │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐          │
│  │ TLS Term    │  │ Clock Auth  │  │ Journal     │          │
│  │ Enforcement │  │ Enforcement │  │ Commit      │          │
│  └─────────────┘  └─────────────┘  └─────────────┘          │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐          │
│  │ Worker      │  │ Resource    │  │ Cleanup     │          │
│  │ Containment │  │ Verification│  │ Verification│          │
│  └─────────────┘  └─────────────┘  └─────────────┘          │
└─────────────────────────┬───────────────────────────────────┘
                          │ Control Plane (IPC)
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                      PHP = UNTRUSTED CLIENT                 │
│  • Session state views (read-only)                          │
│  • Policy evaluation (pure function, no side effects)       │
│  • Protocol validation (syntax only)                        │
│  • Evidence collection (observation only)                   │
│  • NO: TLS termination, key management, clock authority     │
│  • NO: Journal commit, worker spawn, resource verification  │
│  • NO: Cleanup authorization                                │
└─────────────────────────────────────────────────────────────┘
                          │ Data Plane (TLS Channel)
                          ▼
┌─────────────────────────────────────────────────────────────┐
│              WORKER = RESTRICTED EXECUTION PRINCIPAL        │
│  • Job Object contained                                     │
│  • Restricted token                                         │
│  • No network, no children, limited resources               │
│  • Observes only, never decides                             │
└─────────────────────────────────────────────────────────────┘
```

**تصحیحات D4-A:**

| گزینه D4-A | کلاس‌بندی D4-B | دلیل |
|------------|----------------|------|
| PHP Stream TLS termination | **ARCHITECTURE_INCOMPATIBLE** | TLS termination باید در NATIVE CONTROLLER باشد |
| PHP FFI برای Job Object | **ARCHITECTURE_INCOMPATIBLE** (اگر enforcement در PHP باشد) | Containment enforcement باید در NATIVE CONTROLLER/HELPER باشد |
| PHP `hrtime()` برای clock authority | **ARCHITECTURE_INCOMPATIBLE** (اگر clock authority در PHP باشد) | Clock authority باید در NATIVE CONTROLLER باشد |
| PHP FFI برای Authenticode | **ARCHITECTURE_INCOMPATIBLE** (اگر verification در PHP باشد) | Binary verification باید در NATIVE CONTROLLER/HELPER باشد |
| Native Helper (Rust/Go/C++) برای TLS/Clock/Journal/Containment/Identity | **ACCEPTED CONTRACT** | Helper در control plane NATIVE CONTROLLER اجرا می‌شود |
| Native Helper در data plane (transport) | **ARCHITECTURAL ALTERNATIVE** | فقط اگر TLS channel binding حفظ شود |

**قاعده کلی:** هر گزینه‌ای که **enforcementهای امنیتی** (TLS termination، clock authority، journal commit، containment، resource verification، cleanup verification) را به PHP یا PHP FFI واگذار کند، **ARCHITECTURE_INCOMPATIBLE** است — مگر اینکه یک **security review جداگانه** equivalence را تاسیس کند.

**PHP FFI failure ≠ Model B invalidation.** اگر PHP FFI path شکست خورد، native helper implementation path باقی می‌ماند. فقط اگر **تمام مسیرهای مجاز در مرز اعتماد** شکست بخورند، معماری باطل می‌شود.

---

### 3. هم‌سازگاری انتقال (Transport Coherence) — FG-01 / FG-04

**D3-D Candidate (ACCEPTED CONTRACT — PROVISIONAL):**
```
LOOPBACK TCP + MUTUAL TLS 1.3
├── Identity Authentication: X.509 mutual auth (Controller Cert + Client Cert)
├── Channel Binding: TLS Exporter (RFC 5705/8446) → session binding material
├── Credential Binding: Client Cert SAN/CN → credential_id mapping
├── Session Isolation: TLS session per SessionState.controller_instance_id
├── Replay Protection: TLS sequence numbers + application-level request_id/sequence
├── Trust Boundary: TLS termination in NATIVE CONTROLLER (not PHP)
├── PHP Interoperability: PHP speaks TLS via native controller IPC (not direct)
└── Contract Changes: None — this IS the D3-D contract
```

**Alternativeهای بررسی شده در D4-A (و التصنيف D4-B):**

| Alternative | Identity Auth | Channel Binding | Credential Binding | Session Isolation | Replay Protection | Trust Boundary | PHP Interop | Contract Changes | کلاس‌بندی D4-B |
|-------------|---------------|-----------------|-------------------|-------------------|-------------------|----------------|-------------|------------------|----------------|
| **Named Pipes** | Impersonation / Custom auth | N/A (no TLS) | Custom (pipe name/ACL) | Pipe instance per session | Application-level only | **VIOLATED** — no TLS channel binding | PHP named pipe client | **Major** — wire schema change | ARCHITECTURAL ALTERNATIVE |
| **Native TLS Helper** (data plane) | X.509 mTLS | TLS Exporter ✓ | Cert → credential_id ✓ | Helper manages sessions | TLS seq + app-level | **PRESERVED** — helper = native controller component | PHP → helper IPC | **Minor** — IPC protocol | ARCHITECTURAL ALTERNATIVE |
| **Native Transport Adapter** (control plane only) | X.509 mTLS | TLS Exporter ✓ | Cert → credential_id ✓ | Controller manages | TLS seq + app-level | **PRESERVED** — adapter in native controller | PHP → controller IPC | **None** — D3-D contract intact | ACCEPTED CONTRACT (if feasible) |
| **Loopback TCP + mTLS** (D3-D) | X.509 mTLS ✓ | TLS Exporter ✓ | Cert → credential_id ✓ | TLS session ✓ | TLS seq + app-level ✓ | **PRESERVED** ✓ | PHP → controller IPC ✓ | **None** ✓ | **ACCEPTED CONTRACT (PROVISIONAL)** |

**نکات کلیدی اصلاح D4-B:**

1. **Named Pipes جایگزین mTLS نیستند** — channel binding، credential binding، و replay protection از سطح TLS به سطح application منتقل می‌شوند، trust boundary نقض می‌شود. فقط به عنوان **ARCHITECTURAL ALTERNATIVE** با contract changes major ثبت می‌شوند.

2. **TLS Exporter availability ادعا نشده** — در D4-A به عنوان "PHP 8.2+ openssl_export_keying_material()" ذکر شده بود. **هیچ شاهدی وجود ندارد**. باید در FG-01 evidence matrix به عنوان **MISSING EVIDENCE** با API EXPLORATION برچسب‌گذاری شود.

3. **PHP interoperability** برای D3-D candidate: PHP **تنها از طریق IPC با native controller** TLS می‌گوید. PHP مستقیم socket TLS باز نمی‌کند. این در D4-A مبهم بود.

4. **Contract changes:** D3-D wire schema (FrameDecoder + CanonicalJsonCodec + ProtocolValidator) **تغییر نمی‌کند** برای D3-D candidate. Named Pipes نیاز به wire schema تغییر دارد.

---

### 4. اصلاحات ماندگاری (Durability Corrections) — FG-03

**تفکیک صریح سطح‌های ادعا (D4-A ادغام کرده بود):**

| سطح | ادعا D4-A | واقعیت D4-B | دسته‌بندی D4-B |
|-----|-----------|-------------|----------------|
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Atomic admission commit" (line 302-316) | یک آبجکت در حافظه PHP در یک function call — atomic در سطح PHP runtime، نه durable | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Durable request consumption" (accepted_request_ids) | آرایه در حافظه — restart = loss | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Reservation persistence" (reservations array) | در حافظه — restart = loss | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Publication commit" (policyCandidate line 393) | assignment در حافظه — restart = loss | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Torn-write detection" (SessionState constructor validation) | Validation در زمان construction در حافظه — torn write در disk اثبات نشده | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Crash recovery" (reconcileFixtureCleanup) | Function call در حافظه — crash recovery واقعی نیست | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Journal corruption" (FrameDecoder poison state) | Parser state در حافظه — journal corruption روی disk اثبات نشده | ✅ VALID برای synthetic model |
| **IN_MEMORY_SYNTHETIC_INVARIANT** | "Reconciliation" (reconcileCandidate) | Logic در حافظه — durable reconciliation نیست | ✅ VALID برای synthetic model |

| سطح | ادعا D4-A | واقعیت D4-B | دسته‌بندی D4-B |
|-----|-----------|-------------|----------------|
| **NATIVE_DURABILITY_REQUIREMENT** | SQLite WAL / PostgreSQL / Custom log | **هیچ‌کدام VERIFIED نیستند** — فقط CANDIDATE | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Atomic admission durable commit | **REQUIREMENT** — باید در native controller/journal تاسیس شود | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Durable request consumption | **REQUIREMENT** — accepted_request_ids باید crash-survive کند | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Reservation persistence | **REQUIREMENT** — reservations باید crash-survive کند | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Publication commit durability | **REQUIREMENT** — policy replacement باید durable باشد | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Torn-write detection on disk | **REQUIREMENT** — checksum/CRC در journal entries | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Crash recovery with state reconstruction | **REQUIREMENT** — WAL replay یا equivalent | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Journal corruption detection/recovery | **REQUIREMENT** — checksum + repair/truncate | ❌ D4-A ادغام کرده بود |
| **NATIVE_DURABILITY_REQUIREMENT** | Durable reconciliation | **REQUIREMENT** — cleanup state باید durable record شود | ❌ D4-A ادغام کرده بود |

**ادعاهای صریح D4-B:**

> ❌ **یک муتاسیون در حافظه (in-memory mutation) اثبات atomic durable commit نیست.**
> 
> ❌ **Validation آبجکت (object validation) اثبات torn-write detection روی disk نیست.**
> 
> ❌ **FrameDecoder poison state اثبات journal corruption recovery نیست.**
> 
> ❌ **SQLite WAL، PostgreSQL، یا هر مکانیزم دیگری VERIFIED نشده — همه PERSISTENCE_MECHANISM_CANDIDATE هستند.**

**شواهد MINIMAL برای تاسیس ماندگاری (EVIDENCE_REQUIRED):**

| Requirement | Minimal Future Proof |
|-------------|---------------------|
| Atomic admission durable commit | Kill -9 mid-admit → restart → journal replay → accepted_request_ids matches reservations exactly |
| Durable request consumption | Crash after admit → restart → request_id في accepted_request_ids، replay rejected |
| Reservation persistence | Crash with outstanding reservation → restart → reservation visible، admission blocked |
| Publication commit durability | Policy replace → crash → restart → new policy active، old sessions INVALIDATED |
| Torn-write detection | Partial write to journal file (dd truncate) → restart → corruption detected، truncated entry ignored |
| Crash recovery | Kill -9 at random points → restart → state consistent با synthetic invariants |
| Journal corruption | Bit-flip في journal entry → restart → checksum mismatch → entry rejected، state recovered from prior valid entry |
| Durable reconciliation | Crash with CLEANUP_UNCERTAIN → restart → blocked state preserved، manual reconciliation required |

**PERSISTENCE_MECHANISM_CANDIDATEها (بدون ترجیح):**
- SQLite WAL mode (PRAGMA journal_mode=WAL, synchronous=FULL)
- PostgreSQL/MySQL with synchronous_commit=on
- Custom append-only log + checksum + index (native helper)
- LMDB / RocksDB (embedded KV dengan durability)

**هیچ‌کدام انتخاب نهایی نشده‌اند.** Selection باید پس از ISOLATED PROOF STAGE انجام شود.

---

### 5. اصلاحات اثبات تمیزکاری (Cleanup Proof Corrections) — FG-05

**مرز اعتماد:** Cleanup verification **باید در NATIVE CONTROLLER** انجام شود. **هیچ پاسخ client/worker authoritative نیست.**

**چهار وضعیت متمایز (D4-A ادغام کرده بود):**

| وضعیت | تعریف | اثبات مورد نیاز | منبع اعتبار |
|-------|--------|----------------|-------------|
| **PROCESS_EXITED** | پروسه اصلی worker (PID) exited | `GetExitCodeProcess` ≠ STILL_ACTIVE | Native controller observation |
| **JOB_EMPTY_CONFIRMED** | Job Object خالی است (هیچ processی assign نشده) | `QueryInformationJobObject` → `BasicAndIoAccounting` → `ActiveProcesses == 0` | Native controller query |
| **CLEANUP_DURABLY_RECORDED** | وضعیت تمیزکاری در journal durable запиس شده | Journal entry: `cleanup_confirmed=true` با checksum، crash-survive | Journal durability (FG-03) |
| **RESERVATION_RELEASED** | رزرو credential در journal释放 شده | Journal entry: `reservation_released=true` + session state updated | Journal durability (FG-03) |

**ترتیب الزامی (Required Ordering):**
```
1. Worker process terminates (PROCESS_EXITED)
       ↓
2. Job Object confirms empty (JOB_EMPTY_CONFIRMED) —_WAIT_UNTIL_TRUE
       ↓
3. Native controller writes cleanup_confirmed to journal (CLEANUP_DURABLY_RECORDED)
       ↓
4. Native controller releases reservation in journal (RESERVATION_RELEASED)
       ↓
5. Controller acknowledges cleanup complete to admission coordinator
```

**Step 2 باید blocking باشد** — controller باید `JOB_EMPTY_CONFIRMED` را **پیش از** journal commit صبر کند. `WaitForSingleObject(job_handle, INFINITE)` **کافی نیست** — فقط process exit را wait می‌کند، نه child processes. باید `ActiveProcesses == 0` را poll کند.

**شواهد مورد نیاز (EVIDENCE_REQUIRED) — تفصیلی:**

| Requirement | Minimal Isolated Proof | Trust Boundary |
|-------------|------------------------|----------------|
| Restricted worker token | Create restricted token (remove SeDebugPrivilege, SeImpersonatePrivilege, etc.) → verify `GetTokenInformation(TokenPrivileges)` | Native helper/controller |
| Job assignment before execution | Assign process to job **before** `ResumeThread` / first instruction → verify `IsProcessInJob` | Native helper/controller |
| Child process containment | Spawn child from worker → verify child auto-assigned to job (JOB_OBJECT_LIMIT_BREAKAWAY_OK=FALSE) | Native helper/controller |
| Breakaway restrictions | Attempt `CreateProcess` with `CREATE_BREAKAWAY_FROM_JOB` → verify fails | Native helper/controller |
| Handle inheritance | Create pipe with `bInheritHandles=FALSE` → worker spawns child → child cannot access pipe | Native helper/controller |
| Job lifetime | Job handle held by controller → controller crash → job auto-closes (KILL_ON_JOB_CLOSE) → all processes terminated | Native controller |
| Controller crash behavior | Kill controller process → verify job processes terminated via KILL_ON_JOB_CLOSE | Native controller |
| Process count verification | `QueryInformationJobObject(JobObjectBasicAndIoAccounting)` → `ActiveProcesses` tracking | Native controller |
| Completion-port notifications | `CreateIoCompletionPort(job_handle, ...)` → `JOB_OBJECT_MSG_END_OF_JOB_TIME` / `JOB_OBJECT_MSG_EXIT_PROCESS` | Native controller |
| Termination confirmation | `TerminateJobObject` → wait for `JOB_OBJECT_MSG_END_OF_JOB_TIME` → verify all exited | Native controller |
| Durable cleanup recording | Journal write `cleanup_confirmed` → crash → restart → entry present | FG-03 journal |
| Reservation release | Journal write `reservation_released` → crash → restart → reservation gone، new admission allowed | FG-03 journal |

**PHP FFI / Native Helper Review:**

| Approach | Trust Boundary | Feasibility | D4-B Classification |
|----------|----------------|-------------|---------------------|
| PHP FFI → Kernel32 Job Object APIs | **VIOLATED** — enforcement در PHP process | Medium (FFI crash risk) | ARCHITECTURE_INCOMPATIBLE |
| Native Helper (Rust/Go/C++) → Job Object APIs | **PRESERVED** — helper = native controller component | High | ACCEPTED CONTRACT (PREFERRED) |
| Native Controller process → Job Object APIs | **PRESERVED** — controller is trusted | Highest | ACCEPTED CONTRACT (IDEAL) |
| PowerShell/WMI wrapper | **VIOLATED** — enforcement خارج از trusted path | Low | ARCHITECTURE_INCOMPATIBLE |

** FG-05 باید بدون نیاز به PHP FFI قابل اثبات باشد.** Native controller یا native helper path باید مستقل از PHP FFI کار کند.

---

### 6. اصلاح معیارهای Kill (Kill Criteria Correction)

**تفکیک سه سطح (D4-A ادغام کرده بود):**

| سطح | تعریف | مثال | acción |
|------|--------|------|--------|
| **CANDIDATE_IMPLEMENTATION_FAILURE** | یک implementation path خاص شکست خورد، اما alternative در مرز اعتماد وجود دارد | PHP FFI برای Job Object crash می‌کند، اما Native Helper (Rust) کار می‌کند | **TRY ALTERNATIVE** — Model B ادامه دارد |
| **REMEDIABLE_FEASIBILITY_FAILURE** | یک feasibility gap وجود دارد که با engineering effort قابل حل است | TLS Exporter در PHP OpenSSL موجود نیست، اما SChannel در native helper دارد | **INVESTIGATE/MITIGATE** — Model B ادامه دارد با mitigation |
| **ARCHITECTURE_INVALIDATION** | اثبات می‌شود که **هیچ طراحی مجاز در مرز اعتماد** نمی‌تواند یک invariant الزامی را برآورده کند | Job Object containment در Windows fundamentally broken برای threat model | **KILL MODEL B** — arquitectura باطل |

**معیارهای Kill د4-A اصلاح شده:**

| Kill Criterion (D4-A) | کلاس‌بندی D4-B | توضیح |
|----------------------|----------------|-------|
| Job Object containment کار نمی‌کند | ARCHITECTURE_INVALIDATION | **فقط اگر** native helper/controller path هم شکست بخورد |
| Child process restriction bypassable | ARCHITECTURE_INVALIDATION | **فقط اگر** تمام mitigationها (breakaway restrictions, handle control, job nesting) شکست بخورند |
| Controller crash → worker orphan | ARCHITECTURE_INVALIDATION | **فقط اگر** KILL_ON_JOB_CLOSE + completion port notification هر دو fail کنند |
| Cleanup verification race condition | REMEDIABLE_FEASIBILITY_FAILURE | می‌توان با ordering constraints (step 2 blocking) حل کرد |
| Monotonic clock reset on suspend | REMEDIABLE_FEASIBILITY_FAILURE | می‌توان با external time source (NTP/HTTPS) + persisted last_wall_time mitigate کرد |
| TLS 1.3 mTLS + exporter در PHP/OpenSSL کار نمی‌کند | CANDIDATE_IMPLEMENTATION_FAILURE | Native helper با SChannel/BoringSSL path وجود دارد |
| SQLite WAL atomic admission fail تحت load | CANDIDATE_IMPLEMENTATION_FAILURE | PostgreSQL/MySQL/Custom log alternatives وجود دارند |
| Named pipe message fragmentation | CANDIDATE_IMPLEMENTATION_FAILURE | D3-D candidate (TCP+mTLS) alternative است |

**استنتاج:** **FG-05 Kill Criteria فقط در سطح ARCHITECTURE_INVALIDATION معتبرند** — یعنی اثبات شود که **هیچ مسیر native controller/helper** نمی‌تواند containment/cleanup را در Windows تاسیس کند. فعلاً **هیچ اثباتی وجود ندارد** — فقط PLANNED_EXPERIMENT.

---

### 7. اصلاحات ساعت (Clock Corrections) — FG-02

**ساعت‌های مورد نیاز (Clock Requirements) vs Candidate Providerها:**

| Requirement | Description | Mandatory? | Candidate Providerها |
|-------------|-------------|------------|---------------------|
| **MONOTONIC_TIME** | Non-decreasing، non-adjustable، برای deadline/expiry | YES | `QueryPerformanceCounter`، `GetTickCount64`، `hrtime()` |
| **WALL_CLOCK_TRUST** | قابل اعتماد برای policy validity، cert expiry، manifest lease | YES | `GetSystemTimePreciseAsFileTime`، NTP-verified، hardware RTC |
| **SUSPEND_RESUME_DETECTION** | Detect VM sleep/hibernate → monotonic pause handling | YES | `WM_POWERBROADCAST`، `QueryUnbiasedInterruptTime` |
| **ROLLOVER/OVERFLOW_SAFE** | 64-bit monotonic، checked arithmetic برای deadline | YES | 64-bit counters، checked_add |
| **ROLLBACK_DETECTION** | Wall clock backward detection → invalidate sessions | YES | Persisted last_wall_time + comparison at startup |
| **RESTART_SEMANTICS** | Controller restart → new instance_id → all sessions invalidated | YES | Instance_id generation + journal persistence |
| **ABSOLUTE_MANIFEST_VALIDITY** | receipt_ttl_ms، worker_timeout_ms بر اساس monotonic | YES | Monotonic deadlines فقط |
| **CERT_EXPIRY_CHECK** | TLS cert validity بر اساس wall clock | YES | Wall clock trust required |

**تصحیحات D4-A:**

| ادعای D4-A | اصلاح D4-B |
|-----------|------------|
| `hrtime()` به عنوان monotonic provider کافی است | `hrtime()` در Windows از `QueryPerformanceCounter` backed است — **اما** suspend/resume behavior نامشخص. `QueryUnbiasedInterruptTime` برای suspend-aware monotonic نیاز است. |
| Wall clock rollback detection با persisted last_wall_time | **نکته مهم:** persisted value خود باید **tamper-evident** باشد (journal checksum + signature). فقط file timestamp کفایت نمی‌کند. |
| Monotonic time ≠ trustworthy wall time | **صریحاً تفکیک شده:** Monotonic برای deadline/expiry، Wall برای policy/cert validity. هر دو باید قابل اعتماد باشند. |
| Certificate expiry check | TLS cert validation بر اساس **controller's trusted wall clock** — نه PHP wall clock. |
| Deadline overflow | `admitted_at_monotonic (u64) + timeout_ms (max 5000) < 2^64` — overflow impossible در عمر جلسه (60s). اما **checked arithmetic** الزامی است. |

**Candidate Provider Assessment (بدون اجرا):**

| Provider | Monotonic | Wall Trust | Suspend/Resume | Rollback Detect | Trust Boundary |
|----------|-----------|------------|----------------|-----------------|----------------|
| PHP `hrtime()` | ✓ (QPC) | ✗ (PHP process) | ? (Unknown) | ✗ (No persist) | **VIOLATED** — clock authority در PHP |
| PHP `microtime()` | ✗ | ✗ (PHP process) | ✗ | ✗ | **VIOLATED** |
| Native Helper `QPC` + `GetSystemTimePreciseAsFileTime` | ✓ | ✓ (if helper trusted) | ✓ (`QueryUnbiasedInterruptTime`) | ✓ (persist in journal) | **PRESERVED** |
| Native Controller (same) | ✓ | ✓ | ✓ | ✓ | **PRESERVED** |
| External NTP/HTTPS time source | N/A | ✓ (if authenticated) | N/A | ✓ (cross-check) | **SUPPLEMENTARY** |

**FG-02 باید native helper/controller path را برای clock authority تایید کند.** PHP-only path **ARCHITECTURE_INCOMPATIBLE**.

---

### 8. اصلاحات هویت منبع (Resource Identity Corrections) — FG-06

**D4-A ادعا:** "Signature check alone prevents binary substitution" — **غلط.**

**TOCTOU Reality:**
```
Time T0: Verify binary signature → VALID
Time T1: Attacker replaces binary (same path، different content)
Time T2: Execute binary → MALICIOUS CODE RUNS
```

**Required Evidence برای Prevention:**

| Mechanism | How It Prevents TOCTOU | Trust Boundary |
|-----------|------------------------|----------------|
| **Handle-based execution** | `CreateProcess` با handle به verified binary (not path) — `PROC_THREAD_ATTRIBUTE_HANDLE_LIST` | Native controller/helper |
| **File ID / Object ID binding** | Verify `FILE_ID_128` at execution matches verified ID | Native controller/helper |
| **Job Object + handle inheritance control** | Worker spawned with verified binary handle، no path resolution | Native controller/helper |
| **AppLocker / WDAC policy** | Kernel-enforced execution policy by hash/publisher | OS enforcement (highest) |
| **Immutable filesystem (readonly mount)** | Binary cannot be replaced at T1 | OS enforcement |

**Signature check alone = NOT SUFFICIENT.** باید **execution-time identity binding** داشته باشد.

**Resource Identity Requirements (D4-B):**

| Identity | Windows Mechanism | Verification Point | TOCTOU Resistance |
|----------|-------------------|-------------------|-------------------|
| **Volume Identity** | Volume GUID (`GetVolumeNameForVolumeMountPoint`) | Policy root_registry binding | Stable across remount |
| **File Identity** | `FILE_ID_128` (NTFS/ReFS) / `FILE_OBJECT_ID` | At admission + at execution | Handle-based = resistant |
| **Reparse Points** | `FILE_ATTRIBUTE_REPARSE_POINT` + `FSCTL_GET_REPARSE_POINT` | At path resolution (controller-side) | Detect symlink/junction/mount |
| **Service Identity** | Service SID + binary path + checksum | At deployment registration + pre-execution | Handle-based or SCM query |
| **Binary Identity** | Authenticode signature + `FILE_ID_128` + catalog (WHQL) | At registration + at execution (handle-based) | Execution-time binding required |

**FG-06 Evidence Matrix Correction:** همه entries در D4-A به NOT_VERIFIED تنزل می‌یابند. **هیچ‌کدام PLANNED_EXPERIMENT نیستند** — اولویت پایین‌تر از FG-05/FG-02/FG-01/FG-03/FG-04.

---

### 9. کامل بودن پایه مشخصات (Baseline Completeness)

**SENTINEL-MODEL-B-CONTRACT-V1-CANDIDATE — Gap Analysis:**

| Component | D3-D Contract | D4-A Proposal | D4-B Status | Gap |
|-----------|---------------|---------------|-------------|-----|
| TLS Identity & Channel Binding | ✓ Defined | ✓ Included | **PROVISIONAL** — interop unverified | TLS Exporter evidence missing |
| Authority Publication | ✓ Defined | ✓ Included | **ACCEPTED** — PolicySnapshot model | None |
| Canonical Signing/Digest Rules | ✓ Defined | ✓ Included | **ACCEPTED** — CanonicalJsonCodec + scopeDigest | None |
| Handshake & Session Lifecycle | ✓ Defined | ✓ Included | **ACCEPTED** — SessionAdmissionCoordinator | None |
| Complete Wire Schema | ✓ Defined | ✓ Included | **ACCEPTED** — ProtocolValidator + FrameDecoder + CanonicalJsonCodec | None |
| ERROR Correlation | ✓ Defined | ✓ Included | **ACCEPTED** — reason_code/diagnostic | None |
| Observation Validation | ✓ Defined | ✓ Included | **ACCEPTED** — AuthorizationEvaluator | None |
| Admission & Replay | ✓ Defined | ✓ Included | **ACCEPTED** — admitCandidate + sequence/request_id | None |
| Result Acceptance | ✓ Defined | ✓ Included | **ACCEPTED** — completeCandidate | None |
| Fenced Send Gate | ✓ Defined | ✓ Included | **ACCEPTED** — concurrency counters | None |
| Invalidation | ✓ Defined | ✓ Included | **ACCEPTED** — terminal() + invalidateExpiredGrant | None |
| Reservation Release | ✓ Defined | ✓ Included | **ACCEPTED** — completeCandidate + reconcileCandidate | None |
| Trusted Clock Assumptions | ✓ Defined | ✓ Included | **PROVISIONAL** — native provider unverified | FG-02 evidence missing |
| Framing & Arrival Rules | ✓ Defined | ✓ Included | **ACCEPTED** — FrameDecoder + CanonicalJsonCodec | None |

**Missing Normative Content (D3-D Specification Unavailable):**

> **D3-D consolidated contract و D3-E independent acceptance به عنوان versioned repository artifacts در دسترس نیستند.** تنها به عنوان ارجاع در task instructions وجود دارند. این بدان معناست که:
> 1. **Exact normative text** frozen نشده
> 2. **Document hash** محاسبه نشده
> 3. **Repository versioning** انجام نشده
> 4. **Baseline incomplete** تا زمانی که normative content freeze شود

**Versioning Requirements برای Baseline Freeze:**
1. D3-D contract به عنوان `docs/spec/SENTINEL-MODEL-B-CONTRACT-V1.md` commit شود
2. SHA-256 hash محاسبه و در baseline record شود
3. D3-E review record به عنوان `docs/review/D3-E-ACCEPTANCE.md` commit شود
4. Baseline identifier: `SENTINEL-MODEL-B-CONTRACT-V1-<SHA256_PREFIX>`
5. Change-control: RFC/ADR process برای هر تغییر

**Change-Control Procedure:**
1. Proposal → RFC → Review → Approval → Commit → Hash Update
2. Breaking changes → Major version (V2)
3. Non-breaking additions → Minor version (V1.1)
4. Clarifications/fixes → Patch version (V1.0.1)
5. تمام تغییرات در `docs/spec/CHANGELOG.md` ثبت شوند

---

### 10. ماتریس شواهد اصلاح شده (Corrected Evidence Matrix)

| FG | هدف امنیتی | وابستگی قرارداد | Primitive بومی کاندید | شاهد فعلی | شاهد گمشده | Minimal Isolated Future Proof | معیار موفقیت | معیار شکست | Alternativeهای مجاز | معیار باطل‌سازی معماری | گیت مجاز |
|----|-----------|----------------|---------------------|-----------|------------|-------------------------------|--------------|--------------|-------------------|---------------------|----------|
| **FG-01** | TLS 1.3 mTLS، channel binding، credential binding، key rotation | D3-D: TLS identity، channel binding، credential binding، handshake | **Native Controller: SChannel/BoringSSL** (PHP only via IPC) | DESIGN_ACCEPTED (abstract) | TLS Exporter derivation، cert selection logic، OCSP freshness، key rotation demo | Native helper: mutual auth + exporter derive + cert mapping | Exporter material matches، cert→credential_id binding verified، rotation seamless | TLS 1.3 fail، exporter unavailable در native path | Native TLS helper (data plane) — ARCHITECTURAL ALTERNATIVE | **NO** — native path exists | GATE 2 |
| **FG-02** | Monotonic clock، wall clock trust، suspend/resume، rollback detection، restart semantics، deadline overflow | D3-D: monotonic expiry، wall policy validity، instance_id restart | **Native Controller/Helper: QPC + GetSystemTimePreciseAsFileTime + QueryUnbiasedInterruptTime** | DESIGN_ACCEPTED (assumption) | Suspend/resume monotonic behavior، wall clock tamper-evidence، restart instance_id generation | Native helper: QPC monotonic across suspend، wall clock + persisted last_wall_time signed in journal | Monotonic never backward، rollback detected + invalidate، restart → new instance_id | QPC reset on suspend، wall clock tamper undetectable | External authenticated time source (supplementary) | **NO** — native path exists | GATE 2 |
| **FG-03** | Atomic admission، durable consumption، reservation persistence، publication commit، torn-write detection، crash recovery، corruption detection، durable reconciliation | D3-D: admission، consumption، reservation، publication، invalidation، release | **Native Controller: SQLite WAL / Custom Append-Only Log / LMDB** (journal in native process) | IN_MEMORY_SYNTHETIC_INVARIANT only | **ALL NATIVE_DURABILITY_REQUIREMENTS** | Native journal: kill -9 mid-admit → restart → state consistent، reservations match، policy version durable | Crash recovery exact match synthetic invariants، torn write detected، corruption detected/recovered | State corruption، reservation leak، replay accepted، policy downgrade | PostgreSQL/MySQL (if embedded not feasible) | **YES** — if NO persistence mechanism survives crash with invariants | GATE 3 |
| **FG-04** | Bounded send، session/publication fencing، atomic check+write، partial write، would-block، connection loss، controller restart، send suppression، writer ordering | D3-D: wire schema، framing، admission، session binding، policy binding، concurrency limits | **Native Controller: IOCP + Custom Framing / Named Pipes (message mode) / Loopback TCP+mTLS** | IN_MEMORY_SYNTHETIC_INVARIANT (FrameDecoder) | Message boundaries تحت load، partial write handling، connection loss latency، restart fencing | Native transport: 16KB frames intact، partial handled، loss <100ms، restart → pending writes rejected | All frames delivered in order، fencing enforced، suppression on CLEANUP_UNCERTAIN | Fragmentation، reordering، loss >1s، fencing bypass | Loopback TCP+mTLS (D3-D) — ACCEPTED CONTRACT | **NO** — multiple transport paths | GATE 3 |
| **FG-05** | Restricted worker، Job Object containment، child containment، breakaway restrictions، handle inheritance، resource limits، termination، cleanup verification، controller crash behavior، orphan prevention، durable cleanup recording، reservation release | D3-D: resource_limits، cleanup_budget_ms، reservation release، controller restart behavior | **Native Controller/Helper: Job Objects + Restricted Tokens + Completion Ports** (NO PHP FFI for enforcement) | IN_MEMORY_SYNTHETIC_INVARIANT (fixture limits only) | **ALL — NOT_VERIFIED** | Native launcher: restricted token + job assign pre-exec → child spawn blocked → breakaway fails → handles not inherited → controller crash kills job → job empty confirmed → journal cleanup_confirmed + reservation_released | 4-state ordering verified، no orphans، cleanup durable، reservation released | Any orphan، limit bypass، cleanup not durable، reservation leak | **NONE** — THIS IS CRITICAL PATH | **YES** — if NO native controller/helper path achieves 4-state ordering | GATE 2 (HIGHEST PRIORITY) |
| **FG-06** | Volume identity، file identity، reparse handling، TOCTOU resistance، service identity، binary signature، execution-time binding | D3-D: resource_id in policy، root_registry/service_registry identity_digest | **Native Controller/Helper: Volume GUID + FILE_ID_128 + Authenticode + AppLocker/WDAC + Handle-based execution** | DESIGN_ACCEPTED (policy resource_id only) | Volume GUID stability، File ID rename stability، reparse detection، handle-based execution binding، signature verification at execution | Native identity service: volume GUID stable، file ID stable across rename، reparse detected، handle-based exec with verified binary | All identities stable، reparse rejected، TOCTOU prevented، signature verified at exec | ID instability، reparse missed، TOCTOU window، signature bypass | AppLocker/WDAC (OS enforcement) as supplementary | **NO** — mitigations exist | GATE 4 |

---

### 11. تصمیم گیت ۱ (Gate 1 Decision)

**ارزیابی آمادگی Gate 1:**

| معیار | وضعیت | توضیح |
|------|-------|-------|
| Candidate baseline identifier تعریف شده | ✅ | SENTINEL-MODEL-B-CONTRACT-V1-CANDIDATE |
| Exact specification components listed | ✅ | 14 component در جدول بالا |
| Provenance documented | ⚠️ | D3-D/D3-E **not versioned artifacts** — explicitly noted |
| Accepted design invariants listed | ✅ | 8 invariants در بخش 1 گزارش D4-A |
| Trust boundary preserved | ❌ **D4-A VIOLATIONS CORRECTED** | PHP FFI pathsmarked ARCHITECTURE_INCOMPATIBLE |
| Explicit exclusions documented | ✅ | 8 exclusions در D4-A بخش 6 |
| Open feasibility dependencies listed | ✅ | 6 FG با NOT_VERIFIED |
| Versioning requirements defined | ✅ | در بخش 9 بالا |
| Canonical document requirements | ❌ **MISSING** | D3-D contract not versioned |
| Review/approval requirements | ✅ | Gate 1-6 defined |
| Change-control procedure | ✅ | در بخش 9 بالا |

**Gate 1 Readiness Decision:**

> **GATE1_BLOCKED_MISSING_NORMATIVE_SPEC**

**دلیل:** D3-D consolidated contract و D3-E independent acceptance **به عنوان مصونت‌های مخزن نسخه‌گذاری‌شده (versioned repository artifacts) در دسترس نیستند**. بدون normative content frozen شده، baseline نمی‌تواند immutable شود. این یک **blocker سخت** است — نه warning.

**اقدام مورد نیاز پیش از Gate 1:**
1. D3-D contract به عنوان `docs/spec/SENTINEL-MODEL-B-CONTRACT-V1.md` commit شود
2. D3-E review record به عنوان `docs/review/D3-E-ACCEPTANCE.md` commit شود
3. SHA-256 hash محاسبه و baseline identifier finalize شود
4. این گزارش D4-B به عنوان review artifact ضمیمه شود

---

### 12. اقدام ایمن پیشنهادی بعدی (Next Safe Action)

```
NEXT_SAFE_ACTION = REQUEST_GATE1_PREPARATION:
  1. COMMIT D3-D CONTRACT AS VERSIONED ARTIFACT
  2. COMMIT D3-E ACCEPTANCE RECORD
  3. COMPUTE BASELINE HASH
  4. SCHEDULE GATE 1 REVIEW WITH OWNER/ARCHITECT
  5. UPON GATE 1 APPROVAL → PREPARE FG-05 FEASIBILITY TEST PLAN (CRITICAL PATH)
```

**FG-05 Feasibility Test Plan Preparation (Parallelizable during Gate 1 review):**
- Native helper skeleton (Rust recommended) با Job Object APIs
- IPC protocol spec بین PHP controller و native helper
- 8 test cases از Minimal Isolated Proof در بخش 5
- Failure injection scenarios
- Isolated test environment spec (Windows 10/11 VM، no production deps)

---

### 13. وضعیت نهایی (Final Status)

```
PHASE=MODEL_B_BASELINE_INTEGRITY_REVIEW_D4_B

FILES_CREATED=0
FILES_MODIFIED=0

TRUST_BOUNDARY_STATUS=CORRECTED_PHP_FFI_PATHS_MARKED_INCOMPATIBLE
TRANSPORT_COHERENCE_STATUS=D3_D_CANDIDATE_PRESERVED_NAMED_PIPES_CLASSIFIED_ALTERNATIVE
DURABILITY_CLAIMS_STATUS=IN_MEMORY_SYNTHETIC_VS_NATIVE_DURABILITY_SEPARATED
CLEANUP_PROOF_STATUS=FOUR_STATE_ORDERING_DEFINED_NO_CLIENT_AUTHORITY
CLOCK_ASSUMPTIONS_STATUS=MONOTONIC_VS_WALL_SEPARATED_NATIVE_AUTHORITY_REQUIRED
RESOURCE_IDENTITY_STATUS=SIGNATURE_ALONE_INSUFFICIENT_HANDLE_BASED_REQUIRED
KILL_CRITERIA_STATUS=THREE_TIER_CLASSIFICATION_DEFINED

SPEC_BASELINE_STATUS=CANDIDATE_DEFINED_INCOMPLETE_NORMATIVE_SPEC_MISSING
SPEC_VERSIONED_IN_REPOSITORY=NO
SPEC_HASH_AVAILABLE=NO

GATE1_READINESS=GATE1_BLOCKED_MISSING_NORMATIVE_SPEC
GATE1_APPROVED=NO
GATE2_APPROVED=NO
GATE3_APPROVED=NO
GATE4_APPROVED=NO
GATE5_APPROVED=NO
GATE6_APPROVED=NO

FG01_STATUS=PLANNED_EXPERIMENT_NATIVE_PATH_REQUIRED
FG02_STATUS=PLANNED_EXPERIMENT_NATIVE_CLOCK_AUTHORITY_REQUIRED
FG03_STATUS=PLANNED_EXPERIMENT_PERSISTENCE_MECHANISM_UNSELECTED
FG04_STATUS=PLANNED_EXPERIMENT_TRANSPORT_D3_D_CANDIDATE_PROVISIONAL
FG05_STATUS=PLANNED_EXPERIMENT_CRITICAL_PATH_NATIVE_CONTROLLER_HELPER_REQUIRED
FG06_STATUS=PLANNED_EXPERIMENT_LOWER_PRIORITY_HANDLE_BASED_EXECUTION_REQUIRED

IMPLEMENTATION_READY=NO
NEXT_SAFE_ACTION=REQUEST_GATE1_PREPARATION_COMMIT_D3_D_D3_E_ARTIFACTS

NATIVE_CONTROLLER_IMPLEMENTED=NO
VM_START_AUTHORIZED=NO
VM_CREATED=NO
VM_STARTED=NO
MYSQL_STARTED=NO
MIGRATIONS_EXECUTED=NO
FULL_PROOF_EXECUTED=NO
REAL_LOCAL_RUNTIME_VERIFIED=NO
```

---

**پایان گزارش D4-B.**  
هیچ فایلی ایجاد یا تغییر نیافته است. هیچ کد اجرا نشده است. فقط بازبینی و اصلاح طراحی و برنامه‌ریزی انجام شده است.