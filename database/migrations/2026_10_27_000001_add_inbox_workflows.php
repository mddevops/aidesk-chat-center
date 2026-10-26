<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_events', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->nullable()->change();
            $table->unsignedBigInteger('aggregate_version')->default(0);
        });
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('title', 100);
            $table->text('body');
            $table->timestamps();
            $table->unique(['organization_id', 'title']);
        });
        Schema::create('conversation_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('type', 40);
            $table->json('changes');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('version');
            $table->timestamps();
            $table->foreign(['organization_id', 'conversation_id'])->references(['organization_id', 'id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_id'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_activities');
        Schema::dropIfExists('quick_replies');
        // Keep nullable message_id: control outbox rows must remain valid on a non-destructive rollback.
        Schema::table('chat_events', fn (Blueprint $table) => $table->dropColumn('aggregate_version'));
    }
};
