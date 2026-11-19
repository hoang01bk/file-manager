<?php

namespace App\Services;

use App\Models\WorkItemUpdate;
use App\Models\WorkNotification;
use Illuminate\Support\Str;

class WorkNotifications
{
    public function record(WorkItemUpdate $activity): void
    {
        $item = $activity->item()->with('project')->first();
        if (!$item || (!$activity->body && !$activity->changes)) return;
        $recipients = !empty($activity->changes['created']) ? [$item->assignee_id] : [$item->assignee_id, $item->creator_id];
        $recipients = array_values(array_unique(array_filter($recipients, fn ($id) => $id !== null && (int) $id !== (int) $activity->author_id)));
        if (!$recipients) return;
        $members = $item->project->members()->whereIn('users.id', $recipients)->pluck('users.id');
        $now = now();
        $rows = $members->map(fn ($id) => ['recipient_id' => $id, 'work_item_update_id' => $activity->id, 'created_at' => $now, 'updated_at' => $now])->all();
        if ($rows) WorkNotification::insertOrIgnore($rows);
    }

    public function present(WorkNotification $notification): array
    {
        $activity = $notification->activity;
        $item = $activity->item;
        $kind = $activity->body ? 'comment' : (!empty($activity->changes['created']) ? 'created' : 'updated');
        return [
            'id' => $notification->id, 'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $activity->created_at->toIso8601String(),
            'actor' => $activity->author?->name ?? 'Thành viên đã xóa',
            'action' => match ($kind) { 'created' => 'đã giao công việc cho bạn', 'comment' => 'đã bình luận', default => 'đã cập nhật công việc' },
            'kind' => $kind, 'item' => ['id' => $item->id, 'title' => $item->title],
            'project' => ['id' => $item->project->id, 'name' => $item->project->name],
            'summary' => $activity->body ? Str::limit($activity->body, 240) : $this->summary($activity->changes ?? []),
            'url' => '/work?project='.$item->work_project_id.'&item='.$item->id,
        ];
    }

    private function summary(array $changes): string
    {
        if (!empty($changes['created'])) return 'Bạn được phân công làm người phụ trách công việc này.';
        $labels = ['status' => 'Trạng thái', 'priority' => 'Ưu tiên', 'due_date' => 'Hạn hoàn thành', 'assignee' => 'Người phụ trách', 'title' => 'Tên công việc'];
        $parts = [];
        foreach ($changes as $field => $change) {
            if ($field === 'description') { $parts[] = 'Đã cập nhật mô tả'; continue; }
            if (!is_array($change)) continue;
            $parts[] = ($labels[$field] ?? $field).': '.$this->value($field, $change['from'] ?? null).' → '.$this->value($field, $change['to'] ?? null);
        }
        return implode(' · ', $parts);
    }

    private function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '') return $field === 'due_date' ? 'Chưa đặt hạn' : 'Chưa phân công';
        return match ($field) {
            'status' => ['todo' => 'Mới', 'doing' => 'Đang làm', 'blocked' => 'Tạm dừng', 'done' => 'Hoàn thành'][$value] ?? (string) $value,
            'priority' => ['low' => 'Thấp', 'normal' => 'Bình thường', 'high' => 'Cao', 'urgent' => 'Khẩn cấp'][$value] ?? (string) $value,
            'due_date' => implode('/', array_reverse(explode('-', substr((string) $value, 0, 10)))),
            default => (string) $value,
        };
    }
}
