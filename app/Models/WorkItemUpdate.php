<?php

namespace App\Models;

use App\Services\WorkNotifications;
use Illuminate\Database\Eloquent\Model;

class WorkItemUpdate extends Model
{
    protected $fillable = ['work_item_id', 'author_id', 'body', 'changes'];
    protected function casts(): array { return ['changes' => 'array']; }
    public function author() { return $this->belongsTo(User::class, 'author_id')->select(['id', 'name']); }
    public function item() { return $this->belongsTo(WorkItem::class, 'work_item_id'); }

    protected static function booted(): void
    {
        // The notification rows share the parent task transaction, so rollback cancels both.
        static::created(fn (self $activity) => app(WorkNotifications::class)->record($activity));
    }
}
