<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique()->comment('Independent identity; may be seeded from a Server public_id for a future migrated agent, never auto-derived.');
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->string('status')->default('pending_approval')->comment('pending_approval|approved|disabled');
            $table->string('name')->nullable()->comment('Filled in by the agent at registration time');
            $table->string('agent_version')->nullable();
            $table->string('os')->nullable();
            $table->string('architecture')->nullable();
            $table->string('ip_address', 45)->nullable()->comment('Captured server-side from the request, never client-supplied');
            $table->json('capabilities')->nullable();
            $table->string('agent_token_hash')->nullable()->comment('Null until approval — a pending agent holds no working credential');
            $table->text('signing_secret')->nullable()->comment('Laravel-encrypted at rest via cast; null until approval');
            $table->text('registration_token')->nullable()->comment('Laravel-encrypted (reversible — used as an HMAC key, not compared as a hash); cleared after first successful use');
            $table->timestamp('registration_token_expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
        });

        // Purely informational/audit traceability — which rows came from an
        // agent-authenticated call versus the legacy server-authenticated
        // path. The existing unique(server_id, nonce) constraint already
        // provides correct replay protection for BOTH paths unmodified,
        // since server_id is always populated in either case (an agent
        // resolves to its own server_id); this column is not load-bearing
        // for that guarantee, so no existing constraint needs to change.
        Schema::table('agent_request_nonces', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('server_id')->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_request_nonces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });

        Schema::dropIfExists('agents');
    }
};
