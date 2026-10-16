<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserUpload extends Model
{
    protected $hidden = ['office_key'];

    protected $fillable = ['user_id', 'file_name', 'file_path', 'file_size', 'expired_at'];

    protected $casts = [
        'expired_at' => 'datetime',
    ];
}
