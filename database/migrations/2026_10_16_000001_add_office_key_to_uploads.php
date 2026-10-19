<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['uploads', 'user_uploads'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->uuid('office_key')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['uploads', 'user_uploads'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('office_key'));
        }
    }
};
