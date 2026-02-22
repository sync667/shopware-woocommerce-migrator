<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('shopware_id');
            $table->unsignedBigInteger('woo_id')->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['migration_id', 'entity_type', 'shopware_id'], 'entity_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_entities');
    }
};
