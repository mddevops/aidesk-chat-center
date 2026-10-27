<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_sessions', fn (Blueprint $table) => $table->unsignedBigInteger('last_delivered_sequence')->default(0));
    }

    public function down(): void
    {
        Schema::table('visitor_sessions', fn (Blueprint $table) => $table->dropColumn('last_delivered_sequence'));
    }
};
