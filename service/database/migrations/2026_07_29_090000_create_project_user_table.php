<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-G: Resource-level authorization foundation. New table only —
 * nothing existing is touched. A Project with zero rows here (every
 * Project that exists before this phase) is simply invisible to non-admin
 * roles — Admin bypasses this table entirely (ProjectPolicy::before), so
 * "legacy projects still manageable" holds for the one role every
 * existing test actually uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('project_role')->nullable()->comment('optional, e.g. "Maintainer" — informational only, not consulted by any Gate/Policy this phase');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
