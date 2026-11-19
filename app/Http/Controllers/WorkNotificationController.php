<?php

namespace App\Http\Controllers;

use App\Models\WorkNotification;
use App\Services\WorkNotifications;
use Illuminate\Http\Request;

class WorkNotificationController extends Controller
{
    public function index(Request $request, WorkNotifications $notifications)
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'unread' => ['sometimes', 'boolean']]);
        $query = WorkNotification::visibleTo($request->user()->id);
        if (!empty($input['unread'])) $query->whereNull('read_at');
        $total = (clone $query)->count();
        $page = min((int) ($input['page'] ?? 1), max(1, (int) ceil($total / 20)));
        $rows = $query->with(['activity.author', 'activity.item.project'])->orderByDesc('id')->paginate(20, ['*'], 'page', $page, $total)
            ->through(fn ($row) => $notifications->present($row));
        return response()->json(['notifications' => $rows, 'unread_count' => $this->unread($request)]);
    }

    public function count(Request $request) { return response()->json(['unread_count' => $this->unread($request)]); }

    public function read(Request $request, int $id)
    {
        $notification = WorkNotification::visibleTo($request->user()->id)->findOrFail($id);
        if (!$notification->read_at) { $notification->read_at = now(); $notification->save(); }
        return response()->json(['read_at' => $notification->read_at->toIso8601String(), 'unread_count' => $this->unread($request)]);
    }

    public function readAll(Request $request)
    {
        WorkNotification::visibleTo($request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['unread_count' => $this->unread($request)]);
    }

    private function unread(Request $request): int { return WorkNotification::visibleTo($request->user()->id)->whereNull('read_at')->count(); }
}
