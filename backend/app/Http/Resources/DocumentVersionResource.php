<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'document_type_id' => $this->document_type_id,
            'version_number' => $this->version_number, 'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type, 'file_size_bytes' => $this->file_size_bytes,
            'uploaded_at' => $this->uploaded_at->utc()->toIso8601String(),
        ];
    }
}
