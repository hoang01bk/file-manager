<?php

namespace App\Models;

use App\Models\Concerns\HasFileEditors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserUpload extends Model
{
    use HasFileEditors;

    protected $hidden = ['office_key'];

    protected $fillable = ['user_id', 'file_name', 'file_path', 'file_size', 'expired_at'];

    protected $casts = [
        'expired_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_upload_editors', 'upload_id', 'user_id');
    }
}
