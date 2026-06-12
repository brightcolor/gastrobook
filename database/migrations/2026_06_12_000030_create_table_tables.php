<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_outdoor')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('online_bookable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('plan_width')->default(1000);  // logical floor-plan units
            $table->unsignedSmallInteger('plan_height')->default(700);
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('name'); // table number / name
            $table->unsignedTinyInteger('min_capacity')->default(1);
            $table->unsignedTinyInteger('max_capacity')->default(4);
            $table->unsignedTinyInteger('preferred_capacity')->nullable();
            $table->unsignedTinyInteger('extra_capacity')->default(0); // squeeze seats
            $table->boolean('is_active')->default(true);
            $table->boolean('online_bookable')->default(true);
            $table->boolean('joinable')->default(true);
            $table->unsignedInteger('priority')->default(100); // lower = assigned first
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('outdoor')->default(false);
            $table->boolean('accessible')->default(false);
            $table->boolean('high_chair_possible')->default(false);
            $table->foreignId('backup_table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->text('note')->nullable();
            // Floor plan geometry
            $table->unsignedSmallInteger('pos_x')->default(0);
            $table->unsignedSmallInteger('pos_y')->default(0);
            $table->unsignedSmallInteger('width')->default(80);
            $table->unsignedSmallInteger('height')->default(80);
            $table->smallInteger('rotation')->default(0);
            $table->string('shape')->default('rect'); // rect, round
            $table->timestamps();
            $table->softDeletes();
            $table->index(['location_id', 'is_active']);
        });

        Schema::create('table_combinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('min_capacity');
            $table->unsignedTinyInteger('max_capacity');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('online_bookable')->default(true);
            $table->timestamps();
        });

        Schema::create('table_combination_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_combination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->unique(['table_combination_id', 'restaurant_table_id'], 'combo_table_unique');
        });

        Schema::create('table_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['restaurant_table_id', 'starts_at', 'ends_at'], 'table_block_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_blocks');
        Schema::dropIfExists('table_combination_items');
        Schema::dropIfExists('table_combinations');
        Schema::dropIfExists('restaurant_tables');
        Schema::dropIfExists('rooms');
    }
};
