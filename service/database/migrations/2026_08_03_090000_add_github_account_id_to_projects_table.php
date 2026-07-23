<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4-L Task 2: purely additive tracking column — without it, there
 * is no reliable way to tell whether a Project's GitHub webhook was
 * already configured through the Hub (the Wizard's `github_account_id`
 * field was only ever used transiently at creation time, never
 * persisted), so the new GitHub Setup Wizard would have no way to show
 * accurate Connected/Not status or avoid re-configuring the same
 * webhook twice. Nullable and nullOnDelete — every existing Project
 * simply has this as null (exactly its current, real state: created
 * before this column existed, webhook configured manually or not at
 * all), and disconnecting/removing a GitHubAccount later never breaks
 * the Project row it's referenced from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('github_account_id')->nullable()->after('server_id')->constrained('github_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_account_id');
        });
    }
};
