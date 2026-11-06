<?php

namespace App\Http\Requests;

use App\Models\LoanApplication;
use Illuminate\Foundation\Http\FormRequest;

class SubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        LoanApplication::whereKey($this->route('application'))->where('customer_id', $this->user()->id)->firstOrFail();

        return true;
    }

    public function rules(): array
    {
        return [
            'expected_current_submission_id' => ['present', 'nullable', function ($attribute, $value, $fail) {
                if (! is_int($value) || $value < 1) {
                    $fail('Versi pengiriman harus berupa ID bilangan bulat positif atau null.');
                }
            }],
            'confirmed' => ['required', function ($attribute, $value, $fail) {
                if ($value !== true) {
                    $fail('Konfirmasi data dengan memilih persetujuan pengiriman.');
                }
            }],
            'customer_id' => ['prohibited'], 'status' => ['prohibited'], 'model_version_id' => ['prohibited'],
            'assignment_id' => ['prohibited'], 'submission_id' => ['prohibited'], 'score' => ['prohibited'],
        ];
    }
}
