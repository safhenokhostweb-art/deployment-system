# Sentinel deployment verification flow

**Implementation state:** Review candidate; not an architecture approval  
**Model B provenance:** `PROVENANCE_PENDING`  
**Gate 1:** `GATE1_APPROVED=NO`

This change adds an authenticated, per-deployment evidence collection and review flow to the existing deployment detail page. It is separate from Model B contracts, synthetic fixtures, and Gate 1. It does not approve a deployment, mutate a target, or change deployment status.

## Evidence shown

The operator can request a bounded fresh collection and review its timestamp, evidence SHA-256, verdict (`PASS`, `FAIL`, `INCONCLUSIVE`, or `STALE`), per-check results, and reasons. The view distinguishes Hub-expected release/commit/checksum values from artifact-byte checksum, signed Server Agent runtime snapshot, and direct independent health-probe observations. Collection results are stored in `sentinel_verification_runs`, linked to the exact deployment and requesting user where available.

The collection path reads the exact linked Release and Deployment, re-hashes the private artifact stream, accepts runtime evidence only from the Server Agent attached to that deployment's server, and performs an HTTP GET only when an explicit destination policy is present. The probe is disabled by default: its configured host, scheme, and port allowlists are empty, and project-level health URLs do not create approval. When enabled by a separately approved policy, every A/AAAA answer must be public, redirects are disabled, and the cURL transport pins the connection to the selected validated address with proxy use disabled. Connection and total-request timeouts remain 2 and 5 seconds. This does not define the production egress allowlist. Missing or mismatched evidence fails closed. A Local Agent snapshot is not treated as deployed runtime evidence. No LocalProof adapter is used by the live service bindings.

Runtime path evidence must now equal the relative string `releases/{deploymentId}` exactly in both the collector and verifier. Missing/malformed deployment IDs, absolute paths, traversal, additional components, alternate prefixes, and lookalike suffixes fail closed. Completed runs are protected from ordinary Eloquent model update/delete operations; this is an application-layer guard only. Database-level tamper resistance and retention/cascade policy remain unresolved governance matters. A collection adapter exception produces a persisted `UNKNOWN` result when the primary database is available. If persistence fails, the request reports that no durable result is available; no secondary audit store is claimed.

The evidence document remains `sentinel.v1`; the Model B A1, A2, POL-01 and D3-E records remain unresolved. This feature does not define or alter those contracts.

## Verification performed

PHP syntax checks passed for all PHP files edited for the security remediation. The focused and related Sentinel regression selection passed: **60 tests, 471 assertions**, covering offline destination-policy and address rejection, DNS pinning seam, release-path exactness in collector and verifier, persistence failure and collector exception behavior, status presentation, model-level update/delete guards, deterministic latest-run ordering, and existing runtime snapshot/reader regressions. Probe resolver and transport test doubles were used; no DNS lookup or HTTP request was performed by tests. No target health endpoint, production service, agent operation, deployment command, or LocalProof workflow was executed.

The new database migration was exercised by the test suite's isolated test database. It was not run against the local Hub database.

## Review points

- Confirm the runtime snapshot producer and the configured health endpoint are appropriate for each deployment environment.
- Review evidence retention and access policy for `sentinel_verification_runs`.
- An authorized owner must define and approve the production health-probe destinations, schemes, ports, and egress policy before any allowlist is configured or the probe is enabled. DNS address screening and transport pinning are defense in depth, not a substitute for that policy.
- Run history is not tamper-proof: ordinary model mutation is blocked, but query-builder/direct SQL operations and the existing deployment cascade can bypass/remove records. Retention and stronger audit-storage guarantees need separate governance decisions.
- This implementation does not satisfy Model B provenance or Gate 1 requirements.

```text
MODEL_B_PROVENANCE=PROVENANCE_PENDING
GATE1_APPROVED=NO
DEPLOYMENT_STATUS_MUTATED=NO
PROOF_OR_PRODUCTION_RUN_EXECUTED=NO
PRODUCTION_HEALTH_PROBE_ENABLED=NO
DESTINATION_EGRESS_POLICY_APPROVED=NO
DB_LEVEL_TAMPER_RESISTANCE=NOT_ESTABLISHED
```
