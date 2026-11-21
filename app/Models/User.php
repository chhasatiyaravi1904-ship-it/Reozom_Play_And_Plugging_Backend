<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
#[Hidden(['password', 'remember_token', 'stripe_account_id', 'two_factor_code'])]
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
            'profile_finished' => 'boolean',
            'is_active' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'stripe_requirements_due' => 'integer',
            'stripe_account_updated_at' => 'datetime',
        ];
    }

    /**
     * Stripe Connect payout account status:
     * not_started | pending (onboarding or verification in progress) | ready | restricted.
     */
    public function payoutStatus(): string
    {
        if (! $this->stripe_account_id) {
            return 'not_started';
        }
        if ($this->stripe_charges_enabled && $this->stripe_payouts_enabled) {
            return 'ready';
        }
        if ($this->stripe_details_submitted && $this->stripe_disabled_reason && ! str_contains($this->stripe_disabled_reason, 'pending')) {
            return 'restricted';
        }

        return 'pending';
    }

    /** Agent can be paid (sellers can buy the agent's plans). */
    public function canReceivePayments(): bool
    {
        return $this->payoutStatus() === 'ready';
    }

    protected function role(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value instanceof UserRole ? $value : UserRole::fromValue($value),
            set: fn ($value) => $value instanceof UserRole ? $value->value : UserRole::fromValue($value)->value,
        );
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

    public function isSellerBuyer(): bool
    {
        if ($this->role === UserRole::SellerBuyer) {
            return true;
        }

        $roleVal = $this->role instanceof UserRole ? $this->role->value : (string) $this->role;
        return in_array(strtolower((string) $roleVal), ['seller_buyer', 'seller/buyer', 'buyer_seller', 'client', 'seller', 'buyer'], true);
    }

    public function isClient(): bool
    {
        return $this->isSellerBuyer();
    }

    public function isSeller(): bool
    {
        return $this->isSellerBuyer();
    }

    public function isBuyer(): bool
    {
        return $this->isSellerBuyer();
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
    /** Where an admin sends this agent's withdrawals (manual bank transfer). */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(AgentBankAccount::class, 'agent_id');
    }

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
