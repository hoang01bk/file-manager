<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkNotification extends Model
{
    protected $fillable = ['recipient_id', 'work_item_update_id', 'read_at'];

    protected function casts(): array { return ['read_at' => 'datetime']; }

    public function activity() { return $this->belongsTo(WorkItemUpdate::class, 'work_item_update_id'); }

    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where('recipient_id', $userId)
            ->whereHas('activity.item.project', fn ($project) => $project->forMember($userId));
    }
}
