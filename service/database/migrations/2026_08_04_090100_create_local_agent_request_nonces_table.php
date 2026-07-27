<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: replay protection for the Local Agent's own signed request
 * channel. Deliberately a separate table from `agent_request_nonces`
 * (which is keyed by `server_id`, not applicable here) rather than
 * widening that table's meaning — mirrors the "new capability -> new,
 * independent table" precedent set by discovery_jobs/operation_jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_agent_request_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_agent_id')->constrained('local_agents')->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['local_agent_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_agent_request_nonces');
    }
};
