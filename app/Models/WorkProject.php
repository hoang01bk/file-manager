<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkProject extends Model
{
    protected $fillable = ['name', 'description', 'owner_id'];
    protected function casts(): array { return ['owner_id' => 'integer']; }

    public function members()
    {
        return $this->belongsToMany(User::class, 'work_project_members');
    }

    public function items()
    {
        return $this->hasMany(WorkItem::class);
    }

    public function scopeForMember($query, int $userId)
    {
        return $query->whereHas('members', fn ($members) => $members->where('users.id', $userId));
    }
}
