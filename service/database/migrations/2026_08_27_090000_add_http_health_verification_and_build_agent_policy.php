<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_agents', function (Blueprint $table): void {
            $table->string('role', 32)->default('development')->after('status');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('authoritative_build_local_agent_id')->nullable()->after('server_id')
                ->constrained('local_agents')->nullOnDelete();
            $table->json('http_health_check')->nullable()->after('deployment_recipe');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->json('http_health_config')->nullable()->after('artifact_id');
            $table->string('execution_status', 32)->default('pending')->after('http_health_config');
            $table->timestamp('execution_finished_at')->nullable()->after('execution_status');
            $table->string('http_health_status', 32)->default('not_configured')->after('execution_finished_at');
            $table->timestamp('http_health_verified_at')->nullable()->after('http_health_status');
            $table->json('http_health_details')->nullable()->after('http_health_verified_at');
        });

        Schema::table('deployment_tasks', function (Blueprint $table): void {
            $table->string('failure_code')->nullable()->after('status');
            $table->text('failure_message')->nullable()->after('failure_code');
        });

        // Historical rows predate HTTP evidence. Preserve their execution
        // outcome but deliberately mark HTTP health as not_configured.
        DB::table('deployments')->where('status', 'succeeded')->update([
            'execution_status' => 'succeeded',
            'execution_finished_at' => DB::raw('finished_at'),
        ]);
        DB::table('deployments')->whereIn('status', ['failed', 'partial_success'])->update([
            'execution_status' => 'failed',
            'execution_finished_at' => DB::raw('finished_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('deployment_tasks', function (Blueprint $table): void {
            $table->dropColumn(['failure_code', 'failure_message']);
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn(['http_health_config', 'execution_status', 'execution_finished_at', 'http_health_status', 'http_health_verified_at', 'http_health_details']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('authoritative_build_local_agent_id');
            $table->dropColumn('http_health_check');
        });

        Schema::table('local_agents', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
