<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table): void {
            $table->string('source_workspace_path', 1000)->nullable()->after('local_path');
            $table->string('production_path', 1000)->nullable()->after('source_workspace_path');
        });

        DB::table('local_project_mappings')
            ->join('projects', 'projects.id', '=', 'local_project_mappings.project_id')
            ->select('local_project_mappings.id', 'local_project_mappings.local_path', 'projects.project_key')
            ->orderBy('local_project_mappings.id')
            ->each(function (object $mapping): void {
                $path = (string) $mapping->local_path;
                $productionLike = $this->isProductionLike($path);
                $safeKey = preg_replace('/[^a-z0-9._-]+/i', '-', (string) $mapping->project_key) ?: 'project';

                DB::table('local_project_mappings')->where('id', $mapping->id)->update([
                    'source_workspace_path' => $productionLike
                        ? '/opt/deployment-agent/workspaces/' . trim($safeKey, '-')
                        : $path,
                    'production_path' => $productionLike ? $path : null,
                ]);
            });

        // These production applications are migrated as one unit: once their
        // source mappings are isolated, deployment must fail closed unless an
        // exact-commit artifact exists. This prevents legacy server-side Git
        // fallback while preserving the existing artifact rollback flow.
        DB::table('projects')
            ->whereIn('project_key', ['deployment-system', 'saas-platform', 'customer-site'])
            ->update(['requires_complete_artifact' => true]);
    }

    public function down(): void
    {
        Schema::table('local_project_mappings', function (Blueprint $table): void {
            $table->dropColumn(['source_workspace_path', 'production_path']);
        });
    }

    private function isProductionLike(string $path): bool
    {
        $path = strtolower(rtrim(str_replace('\\', '/', trim($path)), '/'));

        return in_array($path, ['/var/www', '/srv/www'], true)
            || str_starts_with($path, '/var/www/')
            || str_starts_with($path, '/srv/www/')
            || preg_match('#^/home/[^/]+/(?:public|public_html)(?:/|$)#', $path) === 1
            || preg_match('#(?:^|/)(?:releases?|current)(?:/|$)#', $path) === 1
            || preg_match('#^[a-z]:/inetpub/wwwroot(?:/|$)#', $path) === 1;
    }
};
