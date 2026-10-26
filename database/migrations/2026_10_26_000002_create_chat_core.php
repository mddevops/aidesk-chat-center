<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'users'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unique(['organization_id', 'id']));
        }
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->uuid('public_id')->unique();
            $table->string('name', 150);
            $table->string('kind', 32)->default('website');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'site_id', 'kind']);
            $table->foreign(['organization_id', 'site_id'])->references(['organization_id', 'id'])->on('sites')->restrictOnDelete();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 20);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'phone']);
        });
        Schema::create('visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->uuid('public_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('consent_version', 50)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->unsignedBigInteger('last_read_sequence')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'channel_id'])->references(['organization_id', 'id'])->on('channels')->restrictOnDelete();
            $table->foreign(['organization_id', 'contact_id'])->references(['organization_id', 'id'])->on('contacts')->restrictOnDelete();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('visitor_session_id')->unique();
            $table->unsignedBigInteger('contact_id');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('last_public_sequence')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status', 'last_message_at']);
            foreach (['channel_id' => 'channels', 'visitor_session_id' => 'visitor_sessions', 'contact_id' => 'contacts', 'assigned_user_id' => 'users', 'department_id' => 'departments'] as $column => $target) {
                $table->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($target)->restrictOnDelete();
            }
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sequence');
            $table->string('sender_key', 100);
            $table->string('sender_type', 20);
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('kind', 20)->default('message');
            $table->uuid('client_message_id');
            $table->char('payload_hash', 64);
            $table->text('body');
            $table->string('delivery_status', 20)->default('stored');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['conversation_id', 'sequence']);
            $table->unique(['conversation_id', 'sender_key', 'client_message_id'], 'messages_idempotency_unique');
            $table->foreign(['organization_id', 'conversation_id'])->references(['organization_id', 'id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['organization_id', 'operator_id'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('visitor_session_id')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('name', 150);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            foreach (['visitor_session_id' => 'visitor_sessions', 'operator_id' => 'users', 'message_id' => 'messages'] as $column => $target) {
                $table->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($target)->restrictOnDelete();
            }
        });
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('last_read_sequence')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
            $table->foreign(['organization_id', 'conversation_id'])->references(['organization_id', 'id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('chat_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 60);
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'event_type']);
            $table->index(['published_at', 'available_at']);
            $table->foreign(['organization_id', 'conversation_id'])->references(['organization_id', 'id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['organization_id', 'message_id'])->references(['organization_id', 'id'])->on('messages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['chat_events', 'conversation_reads', 'attachments', 'messages', 'conversations', 'visitor_sessions', 'contacts', 'channels'] as $name) {
            Schema::dropIfExists($name);
        }
        foreach (['sites', 'users'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropUnique(['organization_id', 'id']));
        }
    }
};
