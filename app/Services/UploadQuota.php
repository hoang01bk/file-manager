<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UploadQuota
{
    public function limit(?int $ownerId): int
    {
        return (int) config($ownerId === null ? 'upload_limits.guest_quota' : 'upload_limits.user_quota');
    }

    public function used(?int $ownerId): int
    {
        return (int) Upload::where('user_id', $ownerId)->sum('file_size')
            + ($ownerId === null ? 0 : (int) UserUpload::where('user_id', $ownerId)->sum('file_size'));
    }

    public function status(?int $ownerId): array
    {
        return ['used' => $this->used($ownerId), 'limit' => $this->limit($ownerId), 'guest' => $ownerId === null];
    }

    // Call inside a transaction before reading usage and writing a file.
    public function lock(?int $ownerId): void
    {
        if ($ownerId === null) {
            DB::table('upload_quota_locks')->where('subject', 'guest')->lockForUpdate()->first();
        } else {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
        }
    }

    public function assertFits(?int $ownerId, int $newSize, int $oldSize = 0): void
    {
        if ($newSize <= $oldSize) {
            return;
        }

        if ($this->used($ownerId) - $oldSize + $newSize > $this->limit($ownerId)) {
            throw ValidationException::withMessages([
                'file' => 'Đã vượt quá giới hạn dung lượng lưu trữ. Hãy xóa bớt file rồi thử lại.',
            ]);
        }
    }
}
