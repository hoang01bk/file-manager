<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class OfficeFileSaved implements ShouldBroadcastNow
{
    public function __construct(public string $scope, public int $fileId, public int $activityId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('office.'.$this->scope.'.'.$this->fileId)];
    }

    public function broadcastAs(): string
    {
        return 'file.saved';
    }

    public function broadcastConnections(): array
    {
        return [in_array(config('broadcasting.default'), [null, 'null'], true) ? 'null' : 'reverb'];
    }

    public function broadcastWith(): array
    {
        // Fetch details through the authorized HTTP endpoint even on an existing subscription.
        return ['id' => $this->activityId];
    }
}
