<?php

namespace App\Services;

use App\Models\FileActivity;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OfficeActivity
{
    public function file(string $scope, int $id, Request $request): Upload|UserUpload
    {
        abort_unless(in_array($scope, ['shared', 'personal'], true), 404);
        $file = ($scope === 'shared' ? Upload::query() : UserUpload::query())->findOrFail($id);
        abort_if($scope === 'personal' && ! $file->canEditOffice($request->user()?->id), 403);
        abort_if($file->expired_at?->isPast(), 410);
        abort_unless(in_array(strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION)), ['docx', 'xlsx'], true), 415);
        $disk = $file instanceof Upload && $file->view_only ? 'local' : 'public';
        abort_unless(Storage::disk($disk)->exists($file->file_path), 404);

        return $file;
    }

    public function query(string $scope, int $id)
    {
        return FileActivity::where('scope', $scope)->where('file_id', $id);
    }

    public function identity(Request $request): string
    {
        return $request->user() ? (string) $request->user()->id : 'guest-'.hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }

    public function record(string $scope, int $id, array $data, string $hash, int $size): FileActivity
    {
        $actor = $data['users'][0] ?? null;
        $actor = is_string($actor) || is_int($actor) ? (string) $actor : null;
        $name = $actor && ctype_digit($actor) ? User::find($actor)?->name : null;
        $name ??= $actor && preg_match('/^guest-[a-f0-9]{64}$/D', $actor) ? 'Khách' : 'Không xác định';

        return FileActivity::create([
            'scope' => $scope, 'file_id' => $id,
            'event_key' => $this->eventKey($data, $hash),
            'content_hash' => $hash, 'actor_key' => $actor, 'actor_name' => $name,
            'file_size' => $size, 'save_status' => $data['status'], 'created_at' => now(),
        ]);
    }

    public function eventKey(array $data, string $hash): string
    {
        return hash('sha256', json_encode([$data['key'], $data['status'], $data['url'] ?? '', $hash]));
    }

    public function present(FileActivity $activity, string $viewer): array
    {
        return [
            'id' => $activity->id, 'actor_name' => $activity->actor_name,
            'is_self' => $activity->actor_key !== null && hash_equals($activity->actor_key, $viewer),
            'saved_at' => $activity->created_at->toIso8601String(), 'file_size' => $activity->file_size,
            'kind' => $activity->save_status === 6 ? 'force_save' : 'session_save',
        ];
    }
}
