<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\UserUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FilePermissionService
{
    public function update(Upload|UserUpload $file, int $ownerId, array $editorIds, ?bool $viewOnly, bool $allUsersCanEdit = false): void
    {
        $transfer = null;
        try {
            DB::transaction(function () use ($file, $ownerId, $editorIds, $viewOnly, $allUsersCanEdit, &$transfer) {
                $file = $file->newQuery()->lockForUpdate()->findOrFail($file->id);
                abort_unless($file->user_id !== null && (int) $file->user_id === $ownerId, 403);
                abort_if($file->expired_at && $file->expired_at->isPast(), 410, 'File đã hết hạn.');
                $source = $file instanceof Upload && $file->view_only ? 'local' : 'public';
                abort_unless(Storage::disk($source)->exists($file->file_path), 404, 'Không tìm thấy file.');

                if ($file instanceof Upload && $file->view_only !== $viewOnly) {
                    $target = $viewOnly ? 'local' : 'public';
                    abort_if(Storage::disk($target)->exists($file->file_path), 409, 'File đang có bản sao khác. Vui lòng kiểm tra storage trước khi đổi quyền.');
                    $transfer = ['source' => $source, 'target' => $target, 'path' => $file->file_path];
                    $this->copy($source, $target, $file->file_path);
                    $file->view_only = $viewOnly;
                }
                $file->all_users_can_edit = $allUsersCanEdit;
                $file->editors()->sync($allUsersCanEdit ? [] : array_diff($editorIds, [$ownerId]));
                $file->save();
                if ($transfer && ! Storage::disk($source)->delete($file->file_path)) {
                    throw new RuntimeException('Cannot remove the previous storage copy.');
                }
            });
        } catch (Throwable $exception) {
            // Restore the original storage location if the database update or move failed.
            // Keep the remaining copy if recovery itself fails.
            if ($transfer) {
                try {
                    if (! Storage::disk($transfer['source'])->exists($transfer['path'])
                        && Storage::disk($transfer['target'])->exists($transfer['path'])) {
                        $this->copy($transfer['target'], $transfer['source'], $transfer['path']);
                    }
                    if (Storage::disk($transfer['source'])->exists($transfer['path'])) {
                        Storage::disk($transfer['target'])->delete($transfer['path']);
                    }
                } catch (Throwable $recoveryError) {
                    logger()->error('File permission storage recovery failed.', ['id' => $file->id, 'type' => $file->getTable()]);
                }
            }
            throw $exception;
        }
    }

    private function copy(string $source, string $target, string $path): void
    {
        $stream = Storage::disk($source)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot read the original file.');
        }
        try {
            if (! Storage::disk($target)->put($path, $stream)) {
                throw new RuntimeException('Cannot write the file to the target storage.');
            }
        } finally {
            fclose($stream);
        }
    }
}
