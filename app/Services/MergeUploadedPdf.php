<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\UserUpload;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MergeUploadedPdf
{
    public function __construct(private PdfMerger $merger, private UploadQuota $quota) {}

    public function create(string $scope, int $id, ?int $creatorId, string $name, array $additions): Upload|UserUpload
    {
        $temporary = null;
        $written = null;
        try {
            return DB::transaction(function () use ($scope, $id, $creatorId, $name, $additions, &$temporary, &$written) {
                $this->quota->lock($creatorId);
                $source = ($scope === 'personal' ? UserUpload::query() : Upload::query())->lockForUpdate()->findOrFail($id);
                abort_unless($source->canEditOffice($creatorId), 403, 'Bạn không có quyền ghép file này.');
                abort_if($source->expired_at && $source->expired_at->isPast(), 410, 'File đã hết hạn.');
                abort_unless(strtolower(pathinfo($source->file_name, PATHINFO_EXTENSION)) === 'pdf', 415, 'Chỉ hỗ trợ ghép file PDF.');
                $diskName = $source instanceof Upload && $source->view_only ? 'local' : 'public';
                $disk = Storage::disk($diskName);
                abort_unless($disk->exists($source->file_path), 404, 'Không tìm thấy file gốc.');

                $temporary = tempnam(storage_path('app'), 'pdf-merge-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create temporary PDF.');
                }
                $this->merger->merge([
                    $disk->path($source->file_path),
                    ...array_map(fn ($file) => $file->getRealPath(), $additions),
                ], $temporary);
                $size = filesize($temporary);
                if ($size > config('upload_limits.max_file_size')) {
                    throw ValidationException::withMessages(['pdfs' => 'PDF sau khi ghép không được vượt quá 100 MB.']);
                }
                $this->quota->assertFits($creatorId, $size);

                $name = trim($name);
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                    $name .= '.pdf';
                }
                $directory = $scope === 'personal' ? 'user-uploads/'.$creatorId : 'uploads';
                $path = $directory.'/'.Str::uuid().'.pdf';
                $written = [$diskName, $path];
                if (! $disk->putFileAs($directory, new File($temporary), basename($path))) {
                    throw new RuntimeException('Cannot store merged PDF.');
                }
                $copy = $source->newInstance([
                    'user_id' => $creatorId,
                    'file_name' => $name,
                    'file_path' => $path,
                    'file_size' => $size,
                    'expired_at' => $source->expired_at,
                    'all_users_can_edit' => $source->all_users_can_edit,
                    ...($source instanceof Upload ? ['view_only' => $source->view_only] : []),
                ]);
                $copy->save();
                // Preserve the original readers/editors, including its owner when different.
                $editorIds = $source->editors()->pluck('users.id')->all();
                if ($source->user_id !== null) {
                    $editorIds[] = $source->user_id;
                }
                $copy->editors()->sync($copy->all_users_can_edit ? [] : array_diff(array_unique($editorIds), [$creatorId]));

                return $copy;
            });
        } catch (Throwable $exception) {
            if ($written) {
                Storage::disk($written[0])->delete($written[1]);
            }
            throw $exception;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
