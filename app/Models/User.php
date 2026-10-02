<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'first_name', 'last_name', 'email', 'phone', 'password', 'role',
    'street_address', 'city', 'state', 'zip',
    'company', 'office_number', 'extension', 'profile_finished', 'is_active',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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
            'role' => UserRole::class,
            'profile_finished' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent;
    }

    public function isSeller(): bool
    {
        return $this->role === UserRole::Seller;
    }

    public function isBuyer(): bool
    {
        return $this->role === UserRole::Buyer;
    }

    /**
     * Professional roles (agents) carry company/office details on their
     * profile; buyers/sellers do not.
     */
    public function isProfessionalRole(): bool
    {
        return $this->role === UserRole::Agent;
    }

    public function getFullAddressAttribute(): ?string
    {
        if (! $this->street_address) {
            return null;
        }

        return trim("{$this->street_address}, {$this->city}, {$this->state} {$this->zip}");
    }

    public function loginLogs(): HasMany
    {
        return $this->hasMany(UserLoginLog::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function agentPackages(): HasMany
    {
        return $this->hasMany(AgentPackage::class);
    }

    public function listingProcesses(): HasMany
    {
        return $this->hasMany(ListingProcess::class, 'agent_id');
    }

    /**
     * The agent's most recent package selection that hasn't expired yet.
     * Packages recur (each selection/renewal creates a new row rather than
     * updating one), so "current" is simply the latest row still in date —
     * no separate status column to keep in sync.
     */
    public function currentAgentPackage(): HasOne
    {
        return $this->hasOne(AgentPackage::class)
            ->where('expires_at', '>', now())
            ->latestOfMany('started_at');
    }

    public function hasActivePackage(): bool
    {
        return $this->currentAgentPackage()->exists();
    }

    /**
     * Send the REOZOM-branded verification email instead of the framework's
     * generic default (same signed link, different copy/theme).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }
}
