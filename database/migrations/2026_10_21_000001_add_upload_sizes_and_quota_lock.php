<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->nullable();
        });

        Schema::create('upload_quota_locks', function (Blueprint $table) {
            $table->string('subject')->primary();
        });
        DB::table('upload_quota_locks')->insert(['subject' => 'guest']);

        // Existing files need their real sizes before a quota can be enforced.
        DB::table('uploads')->orderBy('id')->chunkById(100, function ($uploads) {
            foreach ($uploads as $upload) {
                $disk = Storage::disk($upload->view_only ? 'local' : 'public');
                DB::table('uploads')->where('id', $upload->id)->update([
                    'file_size' => $disk->exists($upload->file_path) ? $disk->size($upload->file_path) : 0,
                ]);
            }
        });

        DB::table('user_uploads')->whereNull('file_size')->orderBy('id')->chunkById(100, function ($uploads) {
            $disk = Storage::disk('public');
            foreach ($uploads as $upload) {
                DB::table('user_uploads')->where('id', $upload->id)->update([
                    'file_size' => $disk->exists($upload->file_path) ? $disk->size($upload->file_path) : 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_quota_locks');
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn('file_size');
        });
    }
};
