<?php

namespace App\Models\Concerns;

use App\Models\Upload;
use Illuminate\Database\Eloquent\Builder;

trait HasFileEditors
{
    public function canEditOffice(?int $userId): bool
    {
        // Shared files remain viewable to guests, but editing requires an account.
        if ($this instanceof Upload && $userId === null) {
            return false;
        }
        if ($userId !== null && $this->user_id !== null && (int) $this->user_id === $userId) {
            return true;
        }
        if ($this->allowsAnyAccountToEdit()) {
            return true;
        }

        return $userId !== null && $this->editors()->whereKey($userId)->exists();
    }

    public function allowsAnyAccountToEdit(): bool
    {
        return (bool) $this->all_users_can_edit
            || ($this instanceof Upload && $this->user_id === null && ! $this->view_only);
    }

    public function scopeWithEditorAccess(Builder $query, ?int $userId): void
    {
        $query->withExists(['editors as is_editor' => fn ($editors) => $editors->where('users.id', $userId ?? 0)]);
    }
}
