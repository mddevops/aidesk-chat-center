<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('status', 20)->default('offline');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedInteger('capacity')->default(5);
            $table->json('schedule')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('scope_key', 64);
            $table->string('strategy', 24);
            $table->json('operator_ids');
            $table->unsignedBigInteger('fallback_user_id')->nullable();
            $table->unsignedBigInteger('last_assigned_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['channel_id', 'scope_key']);
            foreach (['channel_id' => 'channels', 'department_id' => 'departments', 'fallback_user_id' => 'users', 'last_assigned_id' => 'users'] as $column => $target) {
                $table->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($target)->restrictOnDelete();
            }
        });
        Schema::table('conversation_activities', fn (Blueprint $table) => $table->unsignedBigInteger('actor_id')->nullable()->change());
    }

    public function down(): void
    {
        // Null system actors cannot be converted back without altering existing audit data.
        Schema::dropIfExists('routing_rules');
        Schema::dropIfExists('operator_states');
    }
};
