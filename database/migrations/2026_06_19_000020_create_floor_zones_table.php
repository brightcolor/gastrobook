<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('color', 7)->default('#60a5fa');
            $table->unsignedTinyInteger('opacity')->default(25);  // 0–100
            $table->json('points');                               // [[x,y], ...]
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_zones');
    }
};
