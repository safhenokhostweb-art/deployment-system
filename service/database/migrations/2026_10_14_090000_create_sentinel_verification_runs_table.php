<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentinel_verification_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('verdict', 16);
            $table->char('evidence_hash', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->json('checks');
            $table->json('reasons');
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->index(['deployment_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentinel_verification_runs');
    }
};
