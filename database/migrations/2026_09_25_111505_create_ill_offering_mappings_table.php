<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureLocalConnection();

        Schema::create('ill_offering_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('base_plan_id', 50);
            $table->string('base_plan_name', 255);
            $table->string('addon_id', 50);
            $table->string('addon_name', 255);
            $table->timestamps();
            $table->unique(['base_plan_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        $this->ensureLocalConnection();

        Schema::dropIfExists('ill_offering_mappings');
    }

    private function ensureLocalConnection(): void
    {
        if (DB::getDefaultConnection() === 'catalog') {
            throw new LogicException('ILL mappings must use the middleware database.');
        }
    }
};
