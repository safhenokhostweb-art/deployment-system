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
        abort_if($mapping->working_tree_dirty, 409, 'source workspace عامل رسمی dirty است؛ ساخت Artifact مسدود شد.');

        return $mapping;
    }
}
