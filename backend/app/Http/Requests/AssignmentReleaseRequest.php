<?php

namespace App\Http\Requests;

use App\Models\Assignment;
use App\Services\ReviewScope;
use Illuminate\Foundation\Http\FormRequest;

class AssignmentReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = Assignment::findOrFail($this->route('assignment'));
        app(ReviewScope::class)->applications($this->user())->whereKey($assignment->application_id)->firstOrFail();
        abort_unless($assignment->assigned_to === $this->user()->id, 403);

        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:16000'], 'assigned_to' => ['prohibited'], 'stage' => ['prohibited'], 'status' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Alasan pelepasan tugas wajib diisi.', 'reason.max' => 'Alasan maksimal 16000 karakter.'];
    }
}
