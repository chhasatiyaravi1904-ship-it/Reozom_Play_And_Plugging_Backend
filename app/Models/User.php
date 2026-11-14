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

    protected $guard_name = 'web';

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
        if ($this->role === UserRole::Admin) {
            return true;
        }

        $roleVal = $this->role instanceof UserRole ? $this->role->value : (string) $this->role;
        if (strtolower($roleVal) === 'admin') {
            return true;
        }

        if (method_exists($this, 'hasRole')) {
            try {
                if ($this->hasRole('admin')) {
                    return true;
                }
            } catch (\Throwable) {}
        }

        return false;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent || ($this->role instanceof UserRole ? $this->role->value === 'agent' : (string) $this->role === 'agent');
    }

    public function isSeller(): bool
    {
        return $this->role === UserRole::Seller || ($this->role instanceof UserRole ? $this->role->value === 'seller' : (string) $this->role === 'seller');
    }

    public function isBuyer(): bool
    {
        return $this->role === UserRole::Buyer || ($this->role instanceof UserRole ? $this->role->value === 'buyer' : (string) $this->role === 'buyer');
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
