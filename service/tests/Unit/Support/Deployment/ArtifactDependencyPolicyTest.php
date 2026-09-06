<?php

namespace Tests\Unit\Support\Deployment;

use App\Support\Deployment\ArtifactDependencyPolicy;
use Tests\TestCase;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256 — "Dependency
 * contract must pin every Composer path repository exactly once"):
 * customer-site's committed composer.json declares 7 Composer path
 * repositories, but the Hub's trusted allowlist only ever declared 6 —
 * missing modules-extension-sdk. These tests pin the corrected shape and
 * the new duplicate-destination guard added alongside it (see
 * local-agent/tests/run_pinned_dependencies.php for the consumer-side
 * mismatch-diagnostics proof).
 */
class ArtifactDependencyPolicyTest extends TestCase
{
    public function test_customer_site_contract_contains_all_seven_paths_including_extension_sdk(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);

        $contract = ArtifactDependencyPolicy::contract('customer-site');

        $this->assertSame(2, $contract['version']);
        $this->assertTrue($contract['required']);
        $this->assertCount(7, $contract['dependencies']);

        $destinations = array_column($contract['dependencies'], 'destination_path');
        $this->assertContains('../saas-platform/packages/saas-platform/modules/extension-sdk', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/tickets', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/forms', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/crm', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/seo-advanced', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/core-utils', $destinations);
        $this->assertContains('../saas-platform/packages/saas-platform/modules/theme-runtime', $destinations);

        $extensionSdk = collect($contract['dependencies'])->firstWhere('destination_path', '../saas-platform/packages/saas-platform/modules/extension-sdk');
        $this->assertSame('saas-platform/modules-extension-sdk', $extensionSdk['package_name']);
        $this->assertSame('packages/saas-platform/modules/extension-sdk', $extensionSdk['source_path']);
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

    public function test_contract_fails_closed_on_a_duplicate_destination_path(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site', [
            'required' => true,
            'dependencies' => [
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'commit_sha' => str_repeat('a', 40), 'package_name' => 'saas-platform/a', 'source_path' => 'packages/a', 'destination_path' => '../saas-platform/packages/a'],
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'commit_sha' => str_repeat('a', 40), 'package_name' => 'saas-platform/b', 'source_path' => 'packages/b', 'destination_path' => '../saas-platform/packages/a'],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate artifact dependency policy destination path');

        ArtifactDependencyPolicy::contract('customer-site');
    }

    public function test_allowlist_fails_closed_on_a_duplicate_destination_path_too(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site', [
            'required' => true,
            'dependencies' => [
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'commit_sha' => str_repeat('a', 40), 'package_name' => 'saas-platform/a', 'source_path' => 'packages/a', 'destination_path' => '../saas-platform/packages/a'],
                ['project_id' => 'saas-platform', 'repository_mapping' => 'saas-platform', 'branch' => 'main', 'commit_sha' => str_repeat('a', 40), 'package_name' => 'saas-platform/b', 'source_path' => 'packages/b', 'destination_path' => '../saas-platform/packages/a'],
            ],
        ]);

        $this->expectException(\LogicException::class);
        ArtifactDependencyPolicy::allowlist('customer-site');
    }

    public function test_contract_returns_empty_when_pinning_is_not_required(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);

        $this->assertSame([], ArtifactDependencyPolicy::contract('customer-site'));
    }
}
