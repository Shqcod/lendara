<?php

namespace App\Http\Requests;

use App\Services\ReviewScope;
use Illuminate\Foundation\Http\FormRequest;

class AssignmentClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(ReviewScope::class)->applications($this->user())->whereKey($this->route('application'))->firstOrFail();

        return true;
    }

    public function rules(): array
    {
        return ['expected_current_submission_id' => ['required', function ($attribute, $value, $fail) {
            if (! is_int($value) || $value < 1) {
                $fail('Versi pengiriman wajib berupa ID bilangan bulat positif.');
            }
        }], 'assigned_to' => ['prohibited'], 'stage' => ['prohibited'], 'status' => ['prohibited'], 'assignment_id' => ['prohibited']];
    }
}
