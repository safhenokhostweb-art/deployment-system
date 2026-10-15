# ADR Draft: Sentinel Scope and Boundaries

**Status:** `PROPOSED_FOR_REVIEW` — not an accepted ADR  
**Decision owner:** `OWNER_NOT_IDENTIFIED` in the current local governance record  
**Gate 1:** `GATE1_APPROVED=NO`  
**Model B provenance:** `PROVENANCE_PENDING`

This document proposes a boundary contract for review. It does not approve Gate 1, establish Model B provenance, select an owner, or authorize implementation.

## Context

The deployment-system architecture describes the deployment service and agent as independent of `saas-platform` and applications being deployed. It also states that deployment-system makes no calls back into those applications (`docs/ARCHITECTURE.md`, §§1 and 8). Sentinel must preserve this separation.

Local Sentinel governance records still mark the decision owner as unidentified, A1/A2/POL-01 as provenance-pending, and D3-E acceptance as unavailable in the bounded local review (`docs/review/SENTINEL-GOVERNANCE-STATUS.md`). The sandbox files and their non-normative headers do not resolve those evidence gaps.

## Proposed decision

Sentinel validation should be a deployment-system-owned, read-only consumer of explicit, versioned evidence artifacts. It should not inspect or execute code inside `saas-platform` or `customer-site`, and it should not call either application's runtime, database, filesystem, or deployment APIs to derive a validation result.

Cross-environment comparison should occur only after each participating project independently exports a blueprint/evidence bundle through a documented producer contract. Sentinel should accept the bundle through a replaceable input adapter, validate its envelope and provenance, then compare it with a separately identified expected blueprint. An unavailable, malformed, untrusted, or version-incompatible artifact must produce an `INDETERMINATE`/blocked result with a reason; it must not be treated as a match or silently replaced by synthetic fixtures.

Sentinel recommendations should be declarative review output only. A recommendation may identify the affected artifact path or entity, observed and expected values, evidence references, and a suggested operator action. Sentinel must not generate executable patches by default, write into either application repository, mutate deployment configuration, or apply a recommendation. Any future patch proposal/apply workflow requires a separate reviewed decision, explicit authorization, preview, and auditable approval boundary.

The service may support adapters/plugins only behind a stable, versioned input interface. Adapters must be explicitly configured and allowlisted, receive only the artifact data required for validation, and must not expand Sentinel's authority to access application runtimes or write to project repositories.

## Proposed artifact boundary contract

The following is a proposed minimum envelope for review, not an existing or approved schema:

| Field | Purpose |
|---|---|
| `contract_id` and `contract_version` | Identify the artifact format and permit explicit compatibility checks. |
| `project_id` and `environment_id` | Identify the producing project and environment without relying on local paths. |
| `source_revision` | Bind the artifact to an immutable source revision, such as a full repository commit identifier. |
| `blueprint_id`, `blueprint_version`, `blueprint_digest` | Identify the expected blueprint and bind the result to exact blueprint bytes. |
| `observed_state` | Carry bounded, structured observations needed for comparison; it must not contain secrets. |
| `producer_identity` and `attestation` | Carry producer/authenticity evidence, subject to an as-yet-unapproved trust and verification policy. |

The format, required/optional fields, canonical encoding, digest algorithm, attestation mechanism, key lifecycle, freshness rules, size limits, and compatibility behavior remain open decisions. Until those rules are approved, an artifact must not be represented as authenticated or authoritative merely because it has this envelope.

## SSOT blueprint sources and ownership

No authoritative SSOT blueprint file or authorized blueprint owner was identified in the local documentation inspected for this draft. The deployment-system architecture document describes deployment boundaries and data flow; it does not define `saas-platform` or `customer-site` entity blueprints. Sentinel Model B recovery-gap documents explicitly state that normative sources are missing.

The proposal is that each application-owning project identify its own canonical blueprint source and publish an immutable, versioned export for Sentinel. The exact source paths, owners, publication process, and authority relation to each project's code/schema are **not established** and must be recorded by the respective project owners before implementation. Candidate artifact names or paths must not be treated as current sources or created as a substitute for owner approval.

| Blueprint | Current authoritative source | Required before acceptance |
|---|---|---|
| `saas-platform` entity/schema blueprint | `NOT_IDENTIFIED` in inspected local evidence | Named canonical source path, accountable owner, versioning and export rule, and approval/provenance record. |
| `customer-site` entity/schema blueprint | `NOT_IDENTIFIED` in inspected local evidence | Named canonical source path, accountable owner, versioning and export rule, and approval/provenance record. |
| Sentinel artifact contract and validator semantics | `NOT_APPROVED` | Reviewed schema, trust policy, comparison semantics, result vocabulary, and compatibility policy. |

## Boundary rules proposed for adoption

1. Sentinel core remains in the deployment-system boundary and depends only on its own interfaces and validated artifact values.
2. No direct cross-project runtime calls, database queries, source-tree crawling, code inclusion, or implicit filesystem access are permitted.
3. Artifact acquisition is explicit, read-only, bounded, and performed by a configured adapter. The adapter returns bytes and source metadata; the validator does not acquire credentials or network authority itself.
4. Every diagnostic is traceable to the exact expected-blueprint and observed-artifact versions/digests once digest rules are approved. Missing evidence yields a non-success result.
5. Entity/schema discrepancies produce structured findings and human-review recommendations. They do not mutate either project.
6. Synthetic Model B fixtures may support isolated tests only. They cannot serve as production blueprints, provenance, trust roots, or acceptance evidence.
7. Validation outputs and failures are auditable and exclude secrets and unrestricted source content.
8. Adding an application-specific adapter, trust mechanism, or write/patch capability requires explicit review and must preserve the independence rule in `docs/ARCHITECTURE.md`.

## Consequences

This approach keeps the deployment service independent while permitting later cross-environment comparison over explicit evidence. It also makes the owning projects responsible for declaring their own SSOT sources and evidence-production rules. The trade-off is that no useful authoritative comparison can run until those sources, artifact trust rules, and version semantics are approved.

## Open decisions required before implementation

- Identify authorized architecture/contract owners and their decision channel.
- Name the canonical SSOT blueprint source in each project and establish provenance/versioning.
- Approve the artifact schema, authenticity/trust mechanism, freshness and compatibility rules.
- Define discrepancy taxonomy, deterministic comparison semantics, and structured recommendation schema.
- Decide whether patch generation is ever in scope; patch application is excluded from this proposal.
- Determine how this proposal relates to Sentinel Model B A1/A2/POL-01 and D3-E. Those items remain pending and cannot be closed by this ADR draft.

## Review disposition

```text
ADR_STATUS=PROPOSED_FOR_REVIEW
OWNER_IDENTIFIED=NO
SSOT_BLUEPRINT_SOURCES_IDENTIFIED=NO
MODEL_B_PROVENANCE=PROVENANCE_PENDING
GATE1_APPROVED=NO
IMPLEMENTATION_AUTHORIZED=NO
```

Acceptance requires an identified, authorized reviewer to record the accepted revision/hash, review scope, decision, conditions, and approval status in the canonical decision record. This draft alone is not that approval.
