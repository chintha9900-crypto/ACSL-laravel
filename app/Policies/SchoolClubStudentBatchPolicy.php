<?php

namespace App\Policies;

use App\Models\SchoolClubStudentBatch;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin review of School Club student batches: the same permissions as
 * membership application review (decide) and activation (approve, which
 * creates memberships). Admin passes via `Gate::before`. A representative's
 * own access to their batches is decided by ownership in the member
 * controller, never by this policy.
 */
class SchoolClubStudentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }

    public function view(User $user, SchoolClubStudentBatch $batch): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }

    public function review(User $user, SchoolClubStudentBatch $batch): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }

    public function approve(User $user, SchoolClubStudentBatch $batch): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications)
            && $user->hasPermission(Ability::ActivateMembership);
    }
}
