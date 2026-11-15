<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReviewScope
{
    public function context(User $user): array
    {
        abort_unless($user->is_active, 403);

        return match ($user->role) {
            Role::Verifier => ['status' => 'pending_verification', 'stage' => 'verification'],
            Role::ApproverOne => ['status' => 'pending_approval_1', 'stage' => 'approval_1'],
            Role::ApproverTwo => ['status' => 'pending_approval_2', 'stage' => 'approval_2'],
            default => abort(403),
        };
    }

    public function applications(User $user): Builder
    {
        return LoanApplication::where('status', $this->context($user)['status'])->whereNotNull('current_submission_id');
    }
}
