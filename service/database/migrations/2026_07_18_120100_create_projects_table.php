<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('name');
            $table->string('project_key')->unique()->comment('Matches a key in the target agent local config.php');
            $table->string('repository')->comment('owner/name, matches GitHub payload full_name');
            $table->string('branch')->default('main');
            $table->string('environment')->default('production');
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->json('pipeline')->comment('Ordered list of {name, command} steps, {placeholders} substituted by the agent');
            $table->boolean('auto_deploy')->default(false)->comment('If true, matching pushes skip manual approval');
            $table->text('github_webhook_secret')->comment('Laravel-encrypted at rest via cast');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
