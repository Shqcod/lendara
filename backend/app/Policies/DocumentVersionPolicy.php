<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentVersionPolicy
{
    public function view(User $user, DocumentVersion $document): Response
    {
        if (! $user->is_active || $user->role !== Role::Customer) {
            return Response::deny();
        }

        return LoanApplication::whereKey($document->application_id)->where('customer_id', $user->id)->exists()
            ? Response::allow() : Response::denyAsNotFound();
    }
}
