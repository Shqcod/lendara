<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\ApplicationSubmission;
use App\Services\ReviewScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(in_array($this->user()->role, [Role::ApproverOne, Role::ApproverTwo], true), 403);
        $snapshot = ApplicationSubmission::findOrFail($this->route('submission'));
        app(ReviewScope::class)->applications($this->user())->whereKey($snapshot->application_id)->firstOrFail();

        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:16000', 'required_if:decision,rejected'],
            'level' => ['prohibited'], 'approver_id' => ['prohibited'], 'assignment_id' => ['prohibited'],
            'anomaly_check_id' => ['prohibited'], 'status' => ['prohibited']];
    }
}
