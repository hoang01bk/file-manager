<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkItem extends Model
{
    public const STATUSES = ['todo', 'doing', 'blocked', 'done'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = ['work_project_id', 'creator_id', 'assignee_id', 'title', 'description', 'status', 'priority', 'due_date'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'version' => 'integer', 'work_project_id' => 'integer', 'creator_id' => 'integer', 'assignee_id' => 'integer'];
    }

    public function project() { return $this->belongsTo(WorkProject::class, 'work_project_id'); }
    public function creator() { return $this->belongsTo(User::class, 'creator_id')->select(['id', 'name', 'employee_code']); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id')->select(['id', 'name', 'employee_code']); }
    public function updates() { return $this->hasMany(WorkItemUpdate::class); }
}
