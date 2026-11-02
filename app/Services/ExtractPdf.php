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

class ExtractPdf
{
    public function __construct(private PdfExtractor $extractor, private UploadQuota $quota) {}

    public function sources(string $scope, ?int $userId, string $search): array
    {
        $this->assertScope($scope, $userId);
        $query = $scope === 'personal' ? UserUpload::query() : Upload::query();

        return $query->whereLike('file_name', '%.pdf')
            ->where(fn ($query) => $query->whereNull('expired_at')->orWhere('expired_at', '>', now()))
            ->where(function ($query) use ($scope, $userId) {
                $query->where('all_users_can_edit', true);
                if ($userId !== null) {
                    $query->orWhere('user_id', $userId)
                        ->orWhereHas('editors', fn ($editors) => $editors->where('users.id', $userId));
                }
                if ($scope === 'shared') {
                    $query->orWhere(fn ($guests) => $guests->whereNull('user_id')->where('view_only', false));
                }
            })
            ->when($search !== '', fn ($query) => $query->whereLike('file_name', '%'.$search.'%'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)
            ->get(['id', 'file_name', 'file_size'])->toArray();
    }

    public function info(string $scope, int $id, ?int $userId): array
    {
        $file = $this->source($scope, $id, $userId);

        return ['id' => $file->id, 'name' => $file->file_name, 'page_count' => $this->extractor->pageCount(Storage::disk($this->disk($file))->path($file->file_path)), 'selection_limit' => config('pdf_merge.max_selected_pages')];
    }

    public function assertScope(string $scope, ?int $userId): void
    {
        abort_unless(in_array($scope, ['shared', 'personal'], true), 404);
        abort_if($scope === 'personal' && $userId === null, 403);
    }

    public function previewPath(string $scope, int $id, ?int $userId): string
    {
        $file = $this->source($scope, $id, $userId);

        return Storage::disk($this->disk($file))->path($file->file_path);
    }

    public function create(string $scope, ?int $creatorId, array $input): Upload|UserUpload
    {
        $this->assertScope($scope, $creatorId);
        $temporary = null;
        $written = null;
        try {
            return DB::transaction(function () use ($scope, $creatorId, $input, &$temporary, &$written) {
                $this->quota->lock($creatorId);
                $source = isset($input['source_id']) ? $this->source($scope, (int) $input['source_id'], $creatorId, true) : null;
                $diskName = $source ? $this->disk($source) : 'public';
                $disk = Storage::disk($diskName);
                $sourcePath = $source ? $disk->path($source->file_path) : $input['file']->getRealPath();
                $temporary = tempnam(storage_path('app'), 'pdf-extract-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create temporary PDF.');
                }
                $this->extractor->extract($sourcePath, $input['pages'], $temporary);
                $size = filesize($temporary);
                if ($size > config('upload_limits.max_file_size')) {
                    throw ValidationException::withMessages(['file' => 'PDF mới không được vượt quá 100 MB.']);
                }
                $this->quota->assertFits($creatorId, $size);
                abort_if($source?->expired_at && $source->expired_at->isPast(), 410, 'File gốc đã hết hạn.');

                $name = trim($input['name']);
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                    $name .= '.pdf';
                }
                $directory = $scope === 'personal' ? 'user-uploads/'.$creatorId : 'uploads';
                $path = $directory.'/'.Str::uuid().'.pdf';
                $written = [$diskName, $path];
                if (! $disk->putFileAs($directory, new File($temporary), basename($path))) {
                    throw new RuntimeException('Cannot store extracted PDF.');
                }
                $attributes = [
                    'user_id' => $creatorId, 'file_name' => $name, 'file_path' => $path, 'file_size' => $size,
                    'expired_at' => $source ? $source->expired_at : ($scope === 'shared' ? now()->addMinutes((int) $input['ttl_minutes']) : null),
                    'all_users_can_edit' => $source?->all_users_can_edit ?? false,
                    ...($scope === 'shared' ? ['view_only' => $source?->view_only ?? false] : []),
                ];
                $copy = $scope === 'personal' ? UserUpload::create($attributes) : Upload::create($attributes);
                if ($source && ! $copy->all_users_can_edit) {
                    $editorIds = $source->editors()->pluck('users.id')->all();
                    if ($source->user_id !== null) {
                        $editorIds[] = $source->user_id;
                    }
                    $copy->editors()->sync(array_diff(array_unique($editorIds), [$creatorId]));
                }

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

    private function source(string $scope, int $id, ?int $userId, bool $lock = false): Upload|UserUpload
    {
        $this->assertScope($scope, $userId);
        $query = $scope === 'personal' ? UserUpload::query() : Upload::query();
        $file = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        abort_unless($file->canEditOffice($userId), 403, 'Bạn không có quyền tách file này.');
        abort_if($file->expired_at && $file->expired_at->isPast(), 410, 'File gốc đã hết hạn.');
        abort_unless(strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION)) === 'pdf', 415, 'Chỉ hỗ trợ file PDF.');
        $disk = Storage::disk($this->disk($file));
        abort_unless($disk->exists($file->file_path), 404, 'Không tìm thấy file gốc.');
        abort_if($disk->size($file->file_path) > config('upload_limits.max_file_size'), 413, 'PDF gốc vượt quá 100 MB.');

        return $file;
    }

    private function disk(Upload|UserUpload $file): string
    {
        return $file instanceof Upload && $file->view_only ? 'local' : 'public';
    }
}
