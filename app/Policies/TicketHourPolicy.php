<?php

namespace App\Policies;

use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketHourPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return true; // Allow viewing timesheet data
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TicketHour  $ticketHour
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, TicketHour $ticketHour)
    {
        // User can view if they are the owner, ticket owner, responsible, or project member
        return $ticketHour->user_id === $user->id ||
               $ticketHour->ticket->owner_id === $user->id ||
               $ticketHour->ticket->responsible_id === $user->id ||
               $ticketHour->ticket->project->users()->where('users.id', $user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return true; // Allow creating time records
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TicketHour  $ticketHour
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, TicketHour $ticketHour)
    {
        // User can update if they are the owner of the time record or ticket owner/responsible
        return $ticketHour->user_id === $user->id ||
               $ticketHour->ticket->owner_id === $user->id ||
               $ticketHour->ticket->responsible_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TicketHour  $ticketHour
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TicketHour $ticketHour)
    {
        // User can delete if they are the owner of the time record or ticket owner/responsible
        return $ticketHour->user_id === $user->id ||
               $ticketHour->ticket->owner_id === $user->id ||
               $ticketHour->ticket->responsible_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TicketHour  $ticketHour
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, TicketHour $ticketHour)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TicketHour  $ticketHour
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, TicketHour $ticketHour)
    {
        //
    }
}
