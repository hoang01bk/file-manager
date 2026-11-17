<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_folders', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('file_folders')->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->index(['scope', 'parent_id']);
        });
        // Root folders also need uniqueness when their parent_id is NULL.
        DB::statement("CREATE UNIQUE INDEX file_folders_name_unique ON file_folders (scope, COALESCE(parent_id, 0), (CASE WHEN scope = 'personal' THEN COALESCE(user_id, 0) ELSE 0 END), name)");
        foreach (['uploads', 'user_uploads'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('folder_id')->nullable()->constrained('file_folders')->nullOnDelete();
                $table->index('folder_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['uploads', 'user_uploads'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('folder_id'));
        }
        Schema::dropIfExists('file_folders');
    }
};
