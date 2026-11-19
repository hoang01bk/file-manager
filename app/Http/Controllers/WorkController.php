<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemUpdate;
use App\Models\WorkProject;
use App\Services\UploadQuota;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class WorkController extends Controller
{
    public function index(Request $request, UploadQuota $quota)
    {
        $input = $request->validate([
            'project' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:200'],
            'item' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(WorkItem::STATUSES)], 'priority' => ['nullable', Rule::in(WorkItem::PRIORITIES)],
            'assignee' => ['nullable', 'string', 'max:20', 'regex:/^(unassigned|[1-9][0-9]*)$/'],
            'overdue' => ['nullable', 'boolean'], 'view' => ['nullable', Rule::in(['list', 'board'])], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $projects = WorkProject::forMember($request->user()->id)->withCount(['items', 'items as done_count' => fn ($query) => $query->where('status', 'done')])->orderBy('name')->get();
        $selectedItem = isset($input['item']) ? WorkItem::findOrFail((int) $input['item']) : null;
        $projectId = $input['project'] ?? $selectedItem?->work_project_id ?? $projects->first()?->id;
        $project = $projectId ? $this->project($request, (int) $projectId)->load('members:id,name,employee_code') : null;
        abort_if($selectedItem && (!$project || $selectedItem->work_project_id !== $project->id), 404);
        $filters = ['q' => trim($input['q'] ?? ''), 'status' => $input['status'] ?? null, 'priority' => $input['priority'] ?? null,
            'assignee' => $input['assignee'] ?? null, 'overdue' => (bool) ($input['overdue'] ?? false), 'view' => $input['view'] ?? 'list'];
        $today = now()->toDateString();
        $stats = ['total' => 0, 'todo' => 0, 'doing' => 0, 'blocked' => 0, 'done' => 0, 'overdue' => 0, 'mine' => 0];
        $items = new LengthAwarePaginator([], 0, 20);
        if ($project) {
            $counts = $project->items()->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            foreach (WorkItem::STATUSES as $status) $stats[$status] = (int) ($counts[$status] ?? 0);
            $stats['total'] = array_sum($counts->all());
            $stats['overdue'] = $project->items()->where('status', '!=', 'done')->where('due_date', '<', $today)->count();
            $stats['mine'] = $project->items()->where('status', '!=', 'done')->where('assignee_id', $request->user()->id)->count();
            $query = $project->items()->with(['assignee', 'creator'])->withCount('updates');
            if ($filters['q'] !== '') $query->where(fn ($query) => $query->whereLike('title', '%'.$filters['q'].'%')->orWhereLike('description', '%'.$filters['q'].'%'));
            if ($filters['status']) $query->where('status', $filters['status']);
            if ($filters['priority']) $query->where('priority', $filters['priority']);
            if ($filters['assignee']) $query->where('assignee_id', $filters['assignee'] === 'unassigned' ? null : (int) $filters['assignee']);
            if ($filters['overdue']) $query->where('status', '!=', 'done')->where('due_date', '<', $today);
            $perPage = $filters['view'] === 'board' ? 100 : 20;
            $total = (clone $query)->count();
            $page = min((int) ($input['page'] ?? 1), max(1, (int) ceil($total / $perPage)));
            $items = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page, $total)->withQueryString();
        }

        return Inertia::render('Work/Index', ['projects' => $projects, 'project' => $project, 'items' => $items,
            'stats' => $stats, 'filters' => $filters, 'today' => $today, 'selectedItemId' => $selectedItem?->id, 'storageQuota' => $quota->status($request->user()->id)]);
    }

    public function people(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($data['q'] ?? '');
        return response()->json(User::when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('employee_code', '%'.$search.'%')))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'employee_code']));
    }

    public function storeProject(Request $request)
    {
        $data = $this->projectData($request);
        $project = DB::transaction(function () use ($request, $data) {
            $project = WorkProject::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'owner_id' => $request->user()->id]);
            $project->members()->sync(array_unique([$request->user()->id, ...$data['member_ids']]));
            return $project;
        });
        return response()->json(['id' => $project->id], 201);
    }

    public function updateProject(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $project = $this->project($request, $id, true);
            abort_unless((int) $project->owner_id === $request->user()->id, 403);
            $data = $this->projectData($request);
            $members = array_unique([$project->owner_id, ...$data['member_ids']]);
            $removed = $project->members()->pluck('users.id')->diff($members)->all();
            foreach ($project->items()->whereIn('assignee_id', $removed)->with('assignee')->lockForUpdate()->get() as $item) {
                $changes = ['assignee' => ['from' => $item->assignee?->name, 'to' => null]];
                $item->assignee_id = null;
                $item->version++;
                $item->save();
                $item->updates()->create(['author_id' => $request->user()->id, 'changes' => $changes]);
            }
            $project->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $project->members()->sync($members);
        });
        return response()->json(['saved' => true]);
    }

    public function storeItem(Request $request, int $projectId)
    {
        $item = DB::transaction(function () use ($request, $projectId) {
            $project = $this->project($request, $projectId, true);
            $data = $this->itemData($request, $project);
            $item = $project->items()->create($data + ['creator_id' => $request->user()->id]);
            if ($item->status === 'done') { $item->completed_at = now(); $item->save(); }
            $item->updates()->create(['author_id' => $request->user()->id, 'changes' => ['created' => true]]);
            return $item;
        });
        return response()->json(['id' => $item->id], 201);
    }

    public function showItem(Request $request, int $id)
    {
        $item = WorkItem::with(['assignee', 'creator'])->findOrFail($id);
        $this->project($request, $item->work_project_id);
        $request->validate(['history_page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json(['item' => $item, 'updates' => $item->updates()->with('author')->orderByDesc('id')->paginate(20, ['*'], 'history_page')]);
    }

    public function updateItem(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $initial = WorkItem::findOrFail($id);
            $project = $this->project($request, $initial->work_project_id, true);
            $item = WorkItem::lockForUpdate()->findOrFail($id);
            $data = $this->itemData($request, $project, true);
            abort_unless($item->version === (int) $data['version'], 409, 'Công việc đã được người khác cập nhật. Hãy tải lại thông tin trước khi lưu.');
            unset($data['version']);
            $before = $item->getOriginal();
            $item->fill($data);
            $dirty = $item->getDirty();
            if (!$dirty) return;
            $changes = [];
            foreach ($dirty as $field => $value) {
                if ($field === 'assignee_id') {
                    $changes['assignee'] = ['from' => isset($before[$field]) ? User::find($before[$field])?->name : null, 'to' => $value ? User::find($value)?->name : null];
                } elseif ($field === 'description') {
                    $changes['description'] = true;
                } else {
                    $changes[$field] = ['from' => $field === 'due_date' && $before[$field] ? substr((string) $before[$field], 0, 10) : $before[$field], 'to' => $field === 'due_date' && $value ? substr((string) $value, 0, 10) : $value];
                }
            }
            if (array_key_exists('status', $dirty)) $item->completed_at = $item->status === 'done' ? now() : null;
            $item->version++;
            $item->save();
            $item->updates()->create(['author_id' => $request->user()->id, 'changes' => $changes]);
        });
        return response()->json(['saved' => true]);
    }

    public function comment(Request $request, int $id)
    {
        $update = DB::transaction(function () use ($request, $id) {
            $item = WorkItem::findOrFail($id);
            $this->project($request, $item->work_project_id, true);
            $item = WorkItem::findOrFail($id);
            $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
            return $item->updates()->create(['author_id' => $request->user()->id, 'body' => $data['body']])->load('author');
        });
        return response()->json(['update' => $update], 201);
    }

    public function destroyItem(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $item = WorkItem::findOrFail($id);
            $project = $this->project($request, $item->work_project_id, true);
            $item = WorkItem::findOrFail($id);
            abort_unless((int) $project->owner_id === $request->user()->id || (int) $item->creator_id === $request->user()->id, 403);
            $item->delete();
        });
        return response()->noContent();
    }

    private function project(Request $request, int $id, bool $lock = false): WorkProject
    {
        $query = WorkProject::forMember($request->user()->id);
        $project = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        if ($lock) abort_unless($project->members()->where('users.id', $request->user()->id)->exists(), 404);
        return $project;
    }

    private function projectData(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:5000'],
            'member_ids' => ['present', 'array', 'max:100'], 'member_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')]]);
    }

    private function itemData(Request $request, WorkProject $project, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'title' => [$required, 'required', 'string', 'max:200'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => [$required, Rule::in(WorkItem::STATUSES)], 'priority' => [$required, Rule::in(WorkItem::PRIORITIES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('work_project_members', 'user_id')->where('work_project_id', $project->id)],
            'version' => [$partial ? 'required' : 'prohibited', 'integer', 'min:1'],
        ]);
    }
}
