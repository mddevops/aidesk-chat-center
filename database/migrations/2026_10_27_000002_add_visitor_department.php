<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreign(['organization_id', 'department_id'])->references(['organization_id', 'id'])->on('departments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'department_id']);
            $table->dropColumn('department_id');
        });
    }
};
