<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: Local Development Publisher Agent. New table only — nothing
 * existing is touched. Deliberately independent of `agents` (which
 * requires a `server_id`, `restrictOnDelete()`'d — a Local Agent runs on
 * a developer's own machine, not a registered deployment Server, so
 * there is no server row to point at). Same registration/approval
 * lifecycle shape as `agents` (pending_approval -> approved -> disabled)
 * for consistency, but a fully separate identity space.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_agents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('status')->default('pending_approval')->comment('pending_approval|approved|disabled');
            $table->string('name')->nullable()->comment('Filled in by the agent at registration time');
            $table->string('hostname')->nullable();
            $table->string('operating_system')->nullable()->comment('windows|linux|macos, self-reported at registration');
            $table->json('capabilities')->nullable();
            $table->string('agent_token_hash')->nullable()->comment('Null until approval — a pending agent holds no working credential');
            $table->text('signing_secret')->nullable()->comment('Laravel-encrypted at rest via cast; null until approval');
            $table->text('registration_token')->nullable()->comment('Laravel-encrypted (reversible — used as an HMAC key, not compared as a hash); cleared after first successful use');
            $table->timestamp('registration_token_expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_agents');
    }
};
