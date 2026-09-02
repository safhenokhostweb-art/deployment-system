<?php

namespace App\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Support\Deployment\SourceWorkspacePath;

final class AuthoritativeBuildAgentResolver
{
    public function resolve(Project $project, ?string $branch = null): LocalProjectMapping
    {
        abort_if(
            $project->authoritative_build_local_agent_id === null,
            409,
            "عامل رسمی ساخت production برای پروژه '{$project->project_key}' تنظیم نشده است (no authoritative production build Agent).",
        );

        $agent = LocalAgent::query()->find($project->authoritative_build_local_agent_id);
        abort_if($agent === null, 409, 'عامل رسمی تنظیم‌شده دیگر وجود ندارد (authoritative production build Agent missing).');
        abort_unless($agent->canBuildProductionArtifacts(), 409, 'عامل رسمی باید تأییدشده و دارای production_build role باشد.');
        abort_unless($agent->isOnline(), 409, 'عامل رسمی ساخت production آفلاین (offline) است؛ ساخت Artifact مسدود شد و fallback انجام نمی‌شود.');

        $minimumVersion = (string) config('deployment.min_local_agent_version_for_artifact');
        abort_unless(
            is_string($agent->agent_version) && version_compare($agent->agent_version, $minimumVersion, '>='),
            409,
            "نسخه عامل رسمی باید {$minimumVersion} یا جدیدتر باشد (authoritative Agent version incompatible).",
        );
        abort_unless(
            ($agent->capabilities['git'] ?? false) === true
                && ($agent->capabilities['laravel_release_manifest'] ?? false) === true,
            409,
            'عامل رسمی قابلیت‌های لازم git و laravel_release_manifest را گزارش نکرده است.',
        );

        $assignedArtifactMinimum = (string) config('deployment.min_local_agent_version_for_assigned_artifact');
        abort_unless(
            $agent->supportsAssignedArtifactUploads(),
            409,
            "Assigned artifact builds require Local Agent {$assignedArtifactMinimum} or newer with assigned_artifact_publish_job_id capability; upgrade required before artifact work begins.",
        );

        $mapping = LocalProjectMapping::query()
            ->where('local_agent_id', $agent->id)
            ->where('project_id', $project->id)
            ->with(['localAgent', 'project'])
            ->first();
        abort_if($mapping === null, 409, 'عامل رسمی به این پروژه mapping نشده است.');
        abort_if($branch !== null && $mapping->effectiveBranch() !== $branch, 409, 'شاخه mapping عامل رسمی با branch درخواست‌شده یکسان نیست.');
        $reportedSource = is_string($mapping->source_workspace_path) && trim($mapping->source_workspace_path) !== ''
            ? trim($mapping->source_workspace_path)
            : trim((string) $mapping->local_path);
        abort_if(
            $reportedSource === '' || SourceWorkspacePath::isProductionLike($reportedSource),
            409,
            'مسیر source workspace عامل رسمی خالی یا production-like است و قابل استفاده نیست.',
        );
        abort_if($mapping->reported_at === null || $mapping->reported_at->lt(now()->subSeconds((int) config('deployment.artifact_workspace_report_max_age_seconds'))), 409, 'گزارش workspace عامل رسمی stale است؛ پیش از ساخت منتظر project sync بمانید.');
        abort_if($mapping->local_head_sha === null || $mapping->reported_branch !== $mapping->effectiveBranch(), 409, 'عامل رسمی HEAD معتبر برای branch تنظیم‌شده گزارش نکرده است.');

        // Root-cause fix: an exact-commit Artifact build (WorkspaceSourcePreparer::
        // prepareRemoteCommit(), the only path agent.php ever builds an artifact
        // through) never reads this workspace's working-tree content — it fetches
        // the production branch and checks the requested commit out inside an
        // isolated BuildWorkspace. Working-tree dirtiness here is unrelated local
        // WIP, not build input, so it must never block resolving the authoritative
        // Agent. It remains enforced separately, and correctly, wherever a build
        // DOES read live working-tree content (see PublishJobService's
        // no-source-change publish evidence checks).
        return $mapping;
    }

    /**
     * Root-cause fix (release-orchestration bootstrap deadlock): tells
     * automatic Release orchestration apart "this Agent needs its
     * exact-commit self-upgrade" from every other reason resolve() can
     * 409 (unconfigured, missing, wrong role, offline, missing
     * capabilities) — those must stay silent-and-blocked exactly as
     * before; only a version-only shortfall is something orchestration
     * can act on itself, by queuing the very upgrade that clears it.
     * Returns null unless the Agent is otherwise fully healthy and
     * eligible and version is the ONLY thing standing between it and
     * supportsAssignedArtifactUploads().
     */
    public function agentPendingVersionUpgradeOnly(Project $project): ?LocalAgent
    {
        if ($project->authoritative_build_local_agent_id === null) {
            return null;
        }

        $agent = LocalAgent::query()->find($project->authoritative_build_local_agent_id);
        if ($agent === null || !$agent->canBuildProductionArtifacts() || !$agent->isOnline()) {
            return null;
        }

        $minimumVersion = (string) config('deployment.min_local_agent_version_for_artifact');
        if (!is_string($agent->agent_version) || version_compare($agent->agent_version, $minimumVersion, '<')) {
            return null;
        }

        if (($agent->capabilities['git'] ?? false) !== true || ($agent->capabilities['laravel_release_manifest'] ?? false) !== true) {
            return null;
        }

        return $agent->supportsAssignedArtifactUploads() ? null : $agent;
    }
}
