<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\ApplicationSubmission;
use App\Services\ReviewScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->user()->role === Role::Verifier, 403);
        $submission = ApplicationSubmission::findOrFail($this->route('submission'));
        app(ReviewScope::class)->applications($this->user())->whereKey($submission->application_id)->firstOrFail();

        return true;
    }

    public function rules(): array
    {
        return [
            'result_type' => ['required', Rule::in(['note', 'request_revision', 'forward'])],
            'notes' => ['nullable', 'string', 'max:16000', 'required_if:result_type,request_revision'],
            'document_checklist' => ['present', 'array', 'max:100'],
            'document_checklist.*' => [function ($attribute, $value, $fail) {
                if (! is_bool($value)) {
                    $fail('Checklist harus berupa nilai boolean.');
                }
            }],
            'assignment_id' => ['prohibited'], 'verifier_id' => ['prohibited'],
            'anomaly_check_id' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }
}
