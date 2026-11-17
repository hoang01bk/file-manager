<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileFolder extends Model
{
    protected $fillable = ['scope', 'user_id', 'parent_id', 'name'];
}
