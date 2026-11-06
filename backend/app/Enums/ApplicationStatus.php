<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case RevisionRequired = 'revision_required';
    case PendingApprovalOne = 'pending_approval_1';
    case PendingApprovalTwo = 'pending_approval_2';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function editable(): bool
    {
        return in_array($this, [self::Draft, self::RevisionRequired], true);
    }

    public function active(): bool
    {
        return ! in_array($this, [self::Approved, self::Rejected], true);
    }
}
