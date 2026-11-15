<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'application_id' => $this->application_id, 'submission_id' => $this->submission_id,
            'assigned_to' => $this->assigned_to, 'assignee' => ['id' => $this->assigned_to, 'name' => $this->resource->getRelation('assignee')?->name],
            'stage' => $this->stage, 'claimed_at' => $this->claimed_at->utc()->toIso8601String(),
            'ended_at' => $this->ended_at?->utc()->toIso8601String(), 'end_type' => $this->end_type, 'end_reason' => $this->end_reason];
    }
}
