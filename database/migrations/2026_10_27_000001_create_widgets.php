<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('site_id')->unique();
            $table->unsignedBigInteger('channel_id')->unique();
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('published_version')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            foreach (['site_id' => 'sites', 'channel_id' => 'channels'] as $column => $target) {
                $table->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($target)->restrictOnDelete();
            }
        });
        Schema::create('widget_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('widget_id');
            $table->unsignedBigInteger('published_by');
            $table->unsignedInteger('version');
            $table->json('configuration');
            $table->timestamps();
            $table->unique(['widget_id', 'version']);
            $table->foreign(['organization_id', 'widget_id'])->references(['organization_id', 'id'])->on('widgets')->restrictOnDelete();
            $table->foreign(['organization_id', 'published_by'])->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_versions');
        Schema::dropIfExists('widgets');
    }
};
