<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add smart update columns to migration_runs
        Schema::table('migration_runs', function (Blueprint $table) {
            $table->timestamp('last_sync_at')->nullable()->after('status');
            $table->string('sync_mode', 20)->default('full')->after('last_sync_at');
            $table->string('conflict_strategy', 20)->default('shopware_wins')->after('sync_mode');
        });

        // Add smart update columns to migration_entities
        Schema::table('migration_entities', function (Blueprint $table) {
            $table->timestamp('shopware_updated_at')->nullable()->after('error_message');
            $table->timestamp('woo_updated_at')->nullable()->after('shopware_updated_at');
            $table->timestamp('last_synced_at')->nullable()->after('woo_updated_at');
            $table->string('sync_status', 20)->nullable()->after('last_synced_at');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migration_runs', function (Blueprint $table) {
            $table->dropColumn(['last_sync_at', 'sync_mode', 'conflict_strategy']);
        });

        Schema::table('migration_entities', function (Blueprint $table) {
            $table->dropIndex(['sync_status']);
            $table->dropColumn(['shopware_updated_at', 'woo_updated_at', 'last_synced_at', 'sync_status']);
        });
    }
};
