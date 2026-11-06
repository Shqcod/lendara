<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\CustomerProfile;
use App\Models\LoanApplication;
use App\Models\User;
use App\Rules\DecimalMoney;
use App\Rules\UnsignedInteger;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SubmissionData
{
    public const PROFILE = ['name', 'identity_number', 'birth_date', 'address', 'phone', 'occupation_type', 'workplace_name', 'work_duration_months'];

    public const FINANCE = ['loan_amount', 'tenor_months', 'purpose', 'monthly_income', 'monthly_expenses', 'existing_installments'];

    public function snapshot(User $user, ?CustomerProfile $profile, LoanApplication $application, $types, $documents): array
    {
        $customer = ['name' => $user->name];
        foreach (array_diff(self::PROFILE, ['name']) as $field) {
            $customer[$field] = $field === 'birth_date' ? $profile?->birth_date?->format('Y-m-d') : $profile?->getAttribute($field);
        }
        $data = ['profile' => $customer] + $application->only(self::FINANCE);
        $validator = Validator::make($data, [
            'profile.name' => ['required', 'string', 'max:150'],
            'profile.identity_number' => ['required', 'string', 'regex:/\A[0-9]{16}\z/'],
            'profile.birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'profile.address' => ['required', 'string', 'max:16000'], 'profile.phone' => ['required', 'string', 'max:30'],
            'profile.occupation_type' => ['required', 'string', 'max:100'], 'profile.workplace_name' => ['required', 'string', 'max:150'],
            'profile.work_duration_months' => ['required', new UnsignedInteger],
            'loan_amount' => ['required', new DecimalMoney(true)], 'monthly_income' => ['required', new DecimalMoney(true)],
            'monthly_expenses' => ['required', new DecimalMoney], 'existing_installments' => ['required', new DecimalMoney],
            'tenor_months' => ['required', new UnsignedInteger(1)], 'purpose' => ['required', 'string', 'max:16000'],
        ]);
        $errors = $validator->errors()->toArray();
        foreach ($types as $type) {
            $document = $documents->get($type->id);
            if (($type->is_required && ! $document) || ($document && ! Storage::disk('local')->exists($document->storage_path))) {
                $errors['documents.'.$type->code] = ['Unggah berkas '.$type->name.' yang dapat dibaca sebelum mengirim.'];
            }
        }
        if ($errors) {
            throw new DomainError('SUBMISSION_INCOMPLETE', 'Lengkapi dan simpan data serta dokumen sebelum mengirim.', 422, $errors);
        }

        return $customer;
    }
}
