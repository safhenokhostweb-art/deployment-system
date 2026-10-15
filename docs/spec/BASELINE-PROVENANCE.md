# BASELINE PROVENANCE TABLE — RECOVERY GAP REPORT

**STATUS: AUTHORITATIVE SOURCES MISSING — PROVENANCE CANNOT BE ESTABLISHED**

## 1. Provenance Methodology (Per Task §9)

For each normative section, the following labels must be recorded:
- **EXACT_SOURCE_RECOVERED** — Exact text recovered from authoritative source
- **SOURCE_MISSING** — Authoritative source document not found
- **DESIGN_ACCEPTED** — Design accepted in review (D3-E)
- **IMPLEMENTATION_UNVERIFIED** — Implementation exists but not verified against normative text
- **NATIVE_EVIDENCE_EXISTS** — Native controller evidence exists

## 2. Normative Section Provenance Status

| Normative Section | Source Document | Source Section | Review Status | Exact Text Recovered | Implementation Exists | Native Evidence | Provenance Label |
|-------------------|-----------------|----------------|---------------|----------------------|----------------------|-----------------|------------------|
| Trust boundaries | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Transport identity | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| TLS channel binding | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Authority publication | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Manifest signatures | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Canonical serialization | D3-D | — | — | **NO** | Synthetic (CanonicalJsonCodec) | No | **SOURCE_MISSING** |
| Policy and grant digests | D3-D | — | — | **NO** | Synthetic (PolicySnapshot) | No | **SOURCE_MISSING** |
| Handshake lifecycle | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Session activation | D3-D | — | — | **NO** | Synthetic (SessionAdmissionCoordinator) | No | **SOURCE_MISSING** |
| Admission | D3-D | — | — | **NO** | Synthetic (SessionAdmissionCoordinator) | No | **SOURCE_MISSING** |
| Replay prevention | D3-D | — | — | **NO** | Synthetic (ProtocolValidator) | No | **SOURCE_MISSING** |
| ERROR correlation | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Observation validation | D3-D | — | — | **NO** | Synthetic (AuthorizationEvaluator) | No | **SOURCE_MISSING** |
| Result acceptance | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Fenced send gate | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Invalidation | D3-D | — | — | **NO** | Synthetic (SessionAdmissionCoordinator) | No | **SOURCE_MISSING** |
| Worker containment assumptions | D3-D | — | — | **NO** | Synthetic (PolicySnapshot limits) | No | **SOURCE_MISSING** |
| Independent cleanup | D3-D | — | — | **NO** | Synthetic only | No | **SOURCE_MISSING** |
| Reservation release | D3-D | — | — | **NO** | Synthetic (SessionAdmissionCoordinator) | No | **SOURCE_MISSING** |
| Trusted clock assumptions | D3-D | — | — | **NO** | Synthetic (monotonic/wall time) | No | **SOURCE_MISSING** |
| Framing and arrival | D3-D | — | — | **NO** | Synthetic (FrameDecoder) | No | **SOURCE_MISSING** |
| Resource identity assumptions | D3-D | — | — | **NO** | Synthetic (PolicySnapshot registries) | No | **SOURCE_MISSING** |

## 3. Source Document Availability Summary

| Source Document | Status | Recovery Possible |
|-----------------|--------|-------------------|
| D3-D Consolidated Contract | **NOT FOUND** | **NO** |
| D3-E Independent Review | **NOT FOUND** | **NO** |
| D4-B Baseline Corrections | **NOT FOUND** | **NO** |
| Synthetic Model B Implementation | **FOUND** | **IMPLEMENTATION COMPARISON ONLY** (per task prohibition) |

## 4. Implementation vs. Normative Distinction

The synthetic Model B implementation in `service/app/Sentinel/ModelB/Synthetic/` exists but:

1. **Explicitly labeled as synthetic/fixture** in every file header
2. **Prohibited as normative source** per task: *"Do not derive missing normative requirements from synthetic code."*
3. **Not verified against any normative text** — no normative text exists to verify against
4. **No native controller implementation exists** — the task notes `NATIVE_CONTROLLER_IMPLEMENTED=NO`

## 5. Provenance Conclusion

**NO PROVENANCE CAN BE ESTABLISHED FOR ANY NORMATIVE SECTION.**

All 21 required normative sections are labeled **SOURCE_MISSING** because the primary authoritative source (D3-D) does not exist in the repository.

The synthetic implementation provides **IMPLEMENTATION_UNVERIFIED** candidates only, which cannot be elevated to normative status.

---

**DOCUMENT CLASSIFICATION**: RECOVERY_GAP_REPORT
**PROVENANCE_COMPLETE**: NO
**ALL_SECTIONS_SOURCE_MISSING**: YES
**GATE1_READINESS**: BLOCKED