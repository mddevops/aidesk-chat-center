<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('Europe/Moscow');
            $table->timestamps();
        });
        foreach (['showrooms', 'departments'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->timestamps();
                $table->unique(['organization_id', 'id']);
                $table->unique(['organization_id', 'name']);
            });
        }
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('showroom_id');
            $table->string('name');
            $table->string('origin');
            $table->timestamps();
            $table->foreign(['organization_id', 'showroom_id'])->references(['organization_id', 'id'])->on('showrooms')->restrictOnDelete();
            $table->unique(['organization_id', 'origin']);
        });
        Schema::table('users', function (Blueprint $table) {
            // Nullable for pre-existing users: login is denied until provisioned.
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('role')->default('operator');
            $table->boolean('is_active')->default(true);
            $table->foreign(['organization_id', 'department_id'])->references(['organization_id', 'id'])->on('departments')->restrictOnDelete();
            $table->index(['organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'department_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id', 'role']);
            $table->dropColumn(['organization_id', 'department_id', 'role', 'is_active']);
        });
        Schema::dropIfExists('sites');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('showrooms');
        Schema::dropIfExists('organizations');
    }
};
