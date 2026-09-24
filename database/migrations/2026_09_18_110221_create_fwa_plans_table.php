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
        Schema::create('fwa_plans', function (Blueprint $table) {
            $table->id();

            // Original leasedlineofferings_prepaid.id
            $table->unsignedBigInteger('source_id')->unique();

            // IDs required by CRM/CBS
            $table->unsignedBigInteger('crm_id')->nullable();
            $table->unsignedBigInteger('cbs_id')->nullable();

            // Clean plan information
            $table->string('plan_name');
            $table->enum('plan_type', ['4G', '5G']);
            $table->decimal('amount', 10, 2);

            // Details from leasedlineofferingsdtls
            $table->string('data_cap')->nullable();
            $table->string('max_speed')->nullable();
            $table->string('default_speed')->nullable();

            $table->boolean('status')->default(true);

            // When this record was last successfully synced
            $table->timestamp('source_synced_at')->nullable();

            $table->timestamps();

            $table->index(['plan_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fwa_plans');
    }
};
