<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('step_name');
            $table->text('command');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('timed_out')->default(false);
            $table->timestamps();

            $table->unique(['deployment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_log_entries');
    }
};
