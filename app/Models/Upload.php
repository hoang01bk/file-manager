<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Upload extends Model
{
    protected $fillable = ['user_id', 'file_name', 'file_path', 'expired_at', 'view_only'];

    protected function casts(): array
    {
        return [
            'expired_at' => 'datetime',
            'view_only' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
