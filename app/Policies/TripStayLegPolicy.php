<?php

namespace App\Policies;

use App\Models\User;
use App\Models\TripStayLeg;
use Illuminate\Auth\Access\HandlesAuthorization;

class TripStayLegPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('agency_admin')) {
            return true;
        }
        return null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_trip_stay_leg');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TripStayLeg $tripStayLeg): bool
    {
        return $user->can('view_trip_stay_leg');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_trip_stay_leg');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TripStayLeg $tripStayLeg): bool
    {
        return $user->can('update_trip_stay_leg');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TripStayLeg $tripStayLeg): bool
    {
        return $user->can('delete_trip_stay_leg');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_trip_stay_leg');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, TripStayLeg $tripStayLeg): bool
    {
        return $user->can('force_delete_trip_stay_leg');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_trip_stay_leg');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, TripStayLeg $tripStayLeg): bool
    {
        return $user->can('restore_trip_stay_leg');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_trip_stay_leg');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, TripStayLeg $tripStayLeg): bool
    {
        return $user->can('replicate_trip_stay_leg');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_trip_stay_leg');
    }
}
