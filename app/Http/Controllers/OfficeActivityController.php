<?php

namespace App\Http\Controllers;

use App\Services\OfficeActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class OfficeActivityController extends Controller
{
    public function index(Request $request, string $scope, int $id, OfficeActivity $activity)
    {
        $activity->file($scope, $id, $request);
        $input = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $query = $activity->query($scope, $id);
        $rows = isset($input['after'])
            ? $query->where('id', '>', $input['after'])->orderBy('id')->limit(100)->get()
            : $query->latest('id')->limit(50)->get()->reverse()->values();
        $viewer = $activity->identity($request);

        return response()->json([
            'activities' => $rows->map(fn ($row) => $activity->present($row, $viewer)),
            'has_more' => isset($input['after']) && $rows->count() === 100,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function authorizeChannel(Request $request, OfficeActivity $activity)
    {
        $input = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+$/D'],
            'channel_name' => ['required', 'string', 'max:100', 'regex:/^private-office\.(shared|personal)\.([1-9][0-9]*)$/D'],
        ]);
        preg_match('/^private-office\.(shared|personal)\.([1-9][0-9]*)$/D', $input['channel_name'], $parts);
        $activity->file($parts[1], (int) $parts[2], $request);

        return response(Broadcast::connection('reverb')->getPusher()->authorizeChannel($input['channel_name'], $input['socket_id']))
            ->header('Content-Type', 'application/json')->header('Cache-Control', 'private, no-store');
    }
}
