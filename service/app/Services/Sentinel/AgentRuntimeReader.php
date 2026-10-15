<?php

namespace App\Services\Sentinel;

/**
 * READ-ONLY source of runtime truth: resolves which agent is serving
 * the target, where the active `current` symlink points, which runtime
 * marker the active release carries and which commit is live.
 *
 * Phase-2 BLOCKER: the existing `PublishJob::ACTION_STATUS_CHECK`
 * reports only local-workspace git state (`head_sha`, `branch`,
 * `changed_files`, `working_tree_clean`). No existing action, report
 * field or API returns the active release path, the `current` symlink
 * target, a runtime marker or the deployed commit/checksum. Until such
 * a report exists (a new action/field — deliberately NOT created in
 * this phase), no real adapter can be implemented and only fakes can
 * feed this interface. Returning null / throwing
 * SentinelSourceUnavailableException maps to UNKNOWN.
 */
interface AgentRuntimeReader
{
    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelRuntimeSnapshot;
}
