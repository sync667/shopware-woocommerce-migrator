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
        Schema::create('shopware_woocommerce_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50)->index();
            $table->string('shopware_uuid', 32);
            $table->unsignedBigInteger('woocommerce_id');
            $table->timestamp('created_at')->useCurrent();

            // Unique constraint: one Shopware UUID maps to one WooCommerce ID per entity type
            $table->unique(['entity_type', 'shopware_uuid']);

            // Index for reverse lookups
            $table->index(['entity_type', 'woocommerce_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopware_woocommerce_id_map');
    }
};
