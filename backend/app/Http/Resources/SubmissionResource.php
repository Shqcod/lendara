<?php

namespace App\Http\Resources;

use App\Services\SubmissionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version_number' => $this->version_number,
            'customer_snapshot' => Arr::only($this->customer_snapshot, SubmissionData::PROFILE),
            ...$this->resource->only(SubmissionData::FINANCE), 'submitted_at' => $this->submitted_at->utc()->toIso8601String(),
            'documents' => DocumentVersionResource::collection($this->resource->getRelation('documents'))];
    }
}
