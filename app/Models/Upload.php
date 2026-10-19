<?php

namespace App\Models;

use App\Models\Concerns\HasFileEditors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Upload extends Model
{
    use HasFileEditors;

    protected $hidden = ['office_key'];

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

    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'upload_editors', 'upload_id', 'user_id');
    }
}
