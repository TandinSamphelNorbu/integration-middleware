<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->uuid('request_id')->unique();

            $table->string('method', 10);
            $table->string('path');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('requested_at');

            $table->timestamps();

            $table->index('requested_at');
            $table->index('status_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
