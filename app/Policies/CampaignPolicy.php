<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $user->role === 'admin' || $campaign->user_id === $user->id;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->role === 'admin' || $campaign->user_id === $user->id;
    }

    public function assignNumber(User $user, Campaign $campaign): bool
    {
        return $user->can_campaign && ($user->role === 'admin' || $campaign->user_id === $user->id);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->role === 'admin' || $campaign->user_id === $user->id;
    }
}
