<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;

class LoanApplication extends Model
{
    protected $fillable = ['loan_amount', 'tenor_months', 'purpose', 'monthly_income', 'monthly_expenses', 'existing_installments'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'current_submission_id' => 'integer',
            'loan_amount' => 'decimal:2', 'monthly_income' => 'decimal:2',
            'monthly_expenses' => 'decimal:2', 'existing_installments' => 'decimal:2',
            'tenor_months' => 'integer',
        ];
    }
}
