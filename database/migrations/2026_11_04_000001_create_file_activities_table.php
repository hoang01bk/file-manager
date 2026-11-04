<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_activities', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);
            $table->unsignedBigInteger('file_id');
            $table->string('event_key', 64);
            $table->string('content_hash', 64);
            $table->string('actor_key')->nullable();
            $table->string('actor_name');
            $table->unsignedBigInteger('file_size');
            $table->unsignedTinyInteger('save_status');
            $table->timestamp('created_at');
            $table->unique(['scope', 'file_id', 'event_key'], 'file_activity_event_unique');
            $table->index(['scope', 'file_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_activities');
    }
};
