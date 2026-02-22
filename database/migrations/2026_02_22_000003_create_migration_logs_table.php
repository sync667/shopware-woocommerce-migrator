<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->string('entity_type')->nullable();
            $table->string('shopware_id')->nullable();
            $table->enum('level', ['debug', 'info', 'warning', 'error'])->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_logs');
    }
};
