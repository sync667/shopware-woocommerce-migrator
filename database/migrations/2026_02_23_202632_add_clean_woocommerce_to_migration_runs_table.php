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
        Schema::table('migration_runs', function (Blueprint $table) {
            $table->boolean('clean_woocommerce')->default(false)->after('is_dry_run');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migration_runs', function (Blueprint $table) {
            $table->dropColumn('clean_woocommerce');
        });
    }
};
