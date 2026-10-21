<?php

namespace App\Models;

use App\Models\Concerns\HasFileEditors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Upload extends Model
{
    use HasFileEditors;

    protected $hidden = ['office_key'];

    protected $fillable = ['user_id', 'file_name', 'file_path', 'file_size', 'expired_at', 'view_only', 'all_users_can_edit'];

    protected function casts(): array
    {
        return [
            'expired_at' => 'datetime',
            'view_only' => 'boolean',
            'all_users_can_edit' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'upload_editors', 'upload_id', 'user_id');
    }
}
