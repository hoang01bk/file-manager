<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['uploads', 'user_uploads'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->boolean('all_users_can_edit')->default(false));
        }
    }

    public function down(): void
    {
        foreach (['uploads', 'user_uploads'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('all_users_can_edit'));
        }
    }
};
