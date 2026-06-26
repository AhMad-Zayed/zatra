<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Auth\Access\HandlesAuthorization;

class ActivityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_activity') || $user->hasRole('super_admin') || $user->hasRole('admin');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('view_activity') || $user->hasRole('super_admin') || $user->hasRole('admin');
    }
}
