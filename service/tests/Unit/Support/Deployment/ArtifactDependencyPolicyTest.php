<?php

namespace Tests\Unit\Support\Deployment;

use App\Support\Deployment\ArtifactDependencyPolicy;
use Tests\TestCase;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256 — "Dependency
 * contract must pin every Composer path repository exactly once"):
 * customer-site's committed composer.json declares 7 Composer path
 * repositories, but the Hub's trusted allowlist only ever declared 6 —
 * missing modules-extension-sdk. These tests pin the corrected shape, the
 * new duplicate-destination guard added alongside it, and the
 * template/resolveContract split that keeps this class an
 * authorization-only allowlist (see
 * App\Services\Publish\ArtifactDependencySnapshotService for where the
 * exact commit actually gets resolved and frozen — see
 * local-agent/tests/run_pinned_dependencies.php for the consumer-side
 * mismatch-diagnostics proof).
 */
class ArtifactDependencyPolicyTest extends TestCase
{
    public function test_customer_site_template_contains_all_seven_paths_including_extension_sdk(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);

        $template = ArtifactDependencyPolicy::template('customer-site');

        $this->assertSame(2, $template['version']);
        $this->assertTrue($template['required']);
        $this->assertCount(7, $template['dependencies']);

        $destinations = array_column($template['dependencies'], 'destination_path');
        $this->assertContains('../saas-platform/packages/saas-platform/modules/extension-sdk', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/tickets', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/forms', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/crm', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/seo-advanced', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/core-utils', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/theme-runtime', $destinations);

        $extensionSdk = collect($template['dependencies'])->firstWhere('destination_path', '../saas-platform/packages/saas-platform/modules/extension-sdk');
        $this->assertSame('saas-platform/modules-extension-sdk', $extensionSdk['package_name']);
        $this->assertSame('packages/saas-platform/modules/extension-sdk', $extensionSdk['source_path']);
    }

    public function test_template_never_carries_a_commit_sha(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);

        $template = ArtifactDependencyPolicy::template('customer-site');

        $this->assertCount(7, $template['dependencies']);
        foreach ($template['dependencies'] as $dependency) {
            $this->assertArrayNotHasKey('commit_sha', $dependency);
        }
    }

    public function test_allowlist_never_leaks_commit_sha(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);

        $allowlist = ArtifactDependencyPolicy::allowlist('customer-site');

        $this->assertCount(7, $allowlist);
        foreach ($allowlist as $dependency) {
            $this->assertArrayNotHasKey('commit_sha', $dependency);
        }
    }

    public function test_resolve_contract_stamps_the_given_commit_onto_every_dependency(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);
        $sha = str_repeat('a', 40);

        $contract = ArtifactDependencyPolicy::resolveContract('customer-site', $sha);

        $this->assertSame(2, $contract['version']);
        $this->assertTrue($contract['required']);
        $this->assertCount(7, $contract['dependencies']);
        foreach ($contract['dependencies'] as $dependency) {
            $this->assertSame($sha, $dependency['commit_sha']);
        }
    }

    public function test_resolve_contract_rejects_a_malformed_commit_sha(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('full lowercase Git SHA');

        ArtifactDependencyPolicy::resolveContract('customer-site', 'not-a-sha');
    }

    public function test_resolve_contract_returns_empty_when_pinning_is_not_required(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);

        $this->assertSame([], ArtifactDependencyPolicy::resolveContract('customer-site', str_repeat('a', 40)));
    }

    public function test_template_fails_closed_on_a_duplicate_destination_path(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site', [
            'required' => true,
            'dependencies' => [
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'package_name' => 'saas-platform/a', 'source_path' => 'packages/a', 'destination_path' => '../saas-platform/packages/a'],
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'package_name' => 'saas-platform/b', 'source_path' => 'packages/b', 'destination_path' => '../saas-platform/packages/a'],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate artifact dependency policy destination path');

        ArtifactDependencyPolicy::template('customer-site');
    }

    public function test_allowlist_fails_closed_on_a_duplicate_destination_path_too(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site', [
            'required' => true,
            'dependencies' => [
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'package_name' => 'saas-platform/a', 'source_path' => 'packages/a', 'destination_path' => '../saas-platform/packages/a'],
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'package_name' => 'saas-platform/b', 'source_path' => 'packages/b', 'destination_path' => '../saas-platform/packages/a'],
            ],
        ]);

        $this->expectException(\LogicException::class);
        ArtifactDependencyPolicy::allowlist('customer-site');
    }

    public function test_template_returns_empty_when_pinning_is_not_required(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);

        $template = ArtifactDependencyPolicy::template('customer-site');

        $this->assertFalse($template['required']);
        $this->assertSame([], $template['dependencies']);
    }
}
