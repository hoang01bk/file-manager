<?php

namespace App\Models\Concerns;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasFileEditors
{
    public function canEditOffice(?int $userId): bool
    {
        if ($userId !== null && $this->user_id !== null && (int) $this->user_id === $userId) {
            return true;
        }
        if ($this instanceof Upload && $this->user_id === null && ! $this->view_only) {
            return true;
        }

        return $userId !== null && ($this->all_users_can_edit
            ? User::whereKey($userId)->exists()
            : $this->editors()->whereKey($userId)->exists());
    }

    public function scopeWithEditorAccess(Builder $query, ?int $userId): void
    {
        $query->withExists(['editors as is_editor' => fn ($editors) => $editors->where('users.id', $userId ?? 0)]);
    }
}
