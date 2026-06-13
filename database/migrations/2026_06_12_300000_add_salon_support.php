<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('type')->default('restaurant')->after('slug');
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('bio')->nullable();
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('staff_member_service', function (Blueprint $table) {
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['staff_member_id', 'service_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete()->after('event_id');
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete()->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_member_id');
            $table->dropConstrainedForeignId('service_id');
        });
        Schema::dropIfExists('staff_member_service');
        Schema::dropIfExists('staff_members');
        Schema::dropIfExists('services');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
