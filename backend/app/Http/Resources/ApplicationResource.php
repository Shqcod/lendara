<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'status' => $this->status->value,
            'current_submission_id' => $this->current_submission_id,
            'loan_amount' => $this->loan_amount,
            'tenor_months' => $this->tenor_months,
            'purpose' => $this->purpose,
            'monthly_income' => $this->monthly_income,
            'monthly_expenses' => $this->monthly_expenses,
            'existing_installments' => $this->existing_installments,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
