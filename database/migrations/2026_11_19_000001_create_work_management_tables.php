<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('work_project_members', function (Blueprint $table) {
            $table->foreignId('work_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['work_project_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('todo');
            $table->string('priority', 16)->default('normal');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['work_project_id', 'status']);
            $table->index(['work_project_id', 'assignee_id']);
        });
        Schema::create('work_item_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['work_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_updates');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('work_project_members');
        Schema::dropIfExists('work_projects');
    }
};
