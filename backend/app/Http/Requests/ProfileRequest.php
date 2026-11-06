<?php

namespace App\Http\Requests;

use App\Rules\UnsignedInteger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'identity_number' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9]{16}\z/', Rule::unique('customer_profiles')->ignore($this->user()->id, 'user_id')],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'address' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'occupation_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'workplace_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'work_duration_months' => ['sometimes', 'nullable', new UnsignedInteger],
            'user_id' => ['prohibited'], 'id' => ['prohibited'], 'role' => ['prohibited'], 'is_active' => ['prohibited'],
        ];
    }
}
