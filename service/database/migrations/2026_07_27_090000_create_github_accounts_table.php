<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-E: new table only — nothing here touches projects, servers, or
 * any existing table. Deliberately holds no access token or secret: a
 * GitHub App installation is authenticated per-request by signing a JWT
 * with the App's private key (config('services.github_app.private_key'),
 * env-only, never persisted) and exchanging it for a short-lived (1 hour)
 * installation access token — there is nothing long-lived to store here
 * beyond the public installation_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->string('account_login');
            $table->string('account_type')->comment('User|Organization, as reported by GitHub');
            $table->string('status')->default('connected')->comment('connected|disconnected');
            $table->string('connected_by')->comment('dashboard user email, mirrors approved_by/triggered_by pattern');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_accounts');
    }
};
