<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['upload_editors' => 'uploads', 'user_upload_editors' => 'user_uploads'] as $pivot => $uploads) {
            Schema::create($pivot, function (Blueprint $table) use ($uploads) {
                $table->foreignId('upload_id')->constrained($uploads)->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->primary(['upload_id', 'user_id']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_upload_editors');
        Schema::dropIfExists('upload_editors');
    }
};
