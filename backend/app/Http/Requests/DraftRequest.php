<?php

namespace App\Http\Requests;

use App\Exceptions\DomainError;
use App\Models\LoanApplication;
use App\Rules\DecimalMoney;
use App\Rules\UnsignedInteger;
use Illuminate\Foundation\Http\FormRequest;

class DraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($id = $this->route('application')) {
            $application = LoanApplication::where('customer_id', $this->user()->id)->whereKey($id)->firstOrFail();
            if (! $application->status->editable()) {
                throw new DomainError('APPLICATION_NOT_EDITABLE', 'Pengajuan hanya dapat diubah saat draf atau diminta revisi.');
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'loan_amount' => ['sometimes', 'nullable', new DecimalMoney(true)],
            'monthly_income' => ['sometimes', 'nullable', new DecimalMoney(true)],
            'monthly_expenses' => ['sometimes', 'nullable', new DecimalMoney],
            'existing_installments' => ['sometimes', 'nullable', new DecimalMoney],
            'tenor_months' => ['sometimes', 'nullable', new UnsignedInteger(1)],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'id' => ['prohibited'], 'customer_id' => ['prohibited'], 'status' => ['prohibited'],
            'application_number' => ['prohibited'], 'current_submission_id' => ['prohibited'],
            'active_customer_id' => ['prohibited'], 'score' => ['prohibited'], 'is_anomaly' => ['prohibited'],
        ];
    }
}
