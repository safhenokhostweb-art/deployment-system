<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_request_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['server_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_request_nonces');
    }
};
