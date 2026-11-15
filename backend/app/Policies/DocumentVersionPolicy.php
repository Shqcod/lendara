<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\DocumentVersion;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ReviewScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

class DocumentVersionPolicy
{
    public function view(User $user, DocumentVersion $document): Response
    {
        if (! $user->is_active || $user->role === Role::Admin) {
            return Response::deny();
        }
        if ($user->role === Role::Customer) {
            return LoanApplication::whereKey($document->application_id)->where('customer_id', $user->id)->exists()
                ? Response::allow() : Response::denyAsNotFound();
        }
        $allowed = app(ReviewScope::class)->applications($user)->whereKey($document->application_id)->exists()
            && DB::table('submission_documents as d')->join('application_submissions as s', 's.id', '=', 'd.submission_id')
                ->where('s.application_id', $document->application_id)->where('d.document_type_id', $document->document_type_id)
                ->where('d.document_version_id', $document->id)->exists();

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
