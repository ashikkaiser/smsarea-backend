<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'can_chat', 'can_campaign', 'status', 'approved_at', 'approved_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'can_chat' => 'boolean',
            'can_campaign' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function assignedPhoneNumbers(): BelongsToMany
    {
        return $this->belongsToMany(PhoneNumber::class, 'phone_number_user')
            ->withPivot(['id', 'assigned_by', 'assigned_at', 'unassigned_at', 'status'])
            ->withTimestamps();
    }

    public function ownedDevices(): HasMany
    {
        return $this->hasMany(Device::class, 'owner_user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deviceEntitlement(): HasOne
    {
        return $this->hasOne(UserDeviceEntitlement::class);
    }

    public function esims(): HasMany
    {
        return $this->hasMany(UserEsim::class);
    }
}
