<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileActivity extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['event_key', 'content_hash', 'actor_key'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
