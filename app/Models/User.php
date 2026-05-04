<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['phone', 'email', 'password', 'referral_code', 'referred_by', 'active', 'blocked'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

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
            'active' => 'boolean',
            'blocked' => 'boolean',
        ];
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function walletAccounts()
    {
        return $this->hasMany(WalletAccount::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function bets()
    {
        return $this->hasMany(Bet::class);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function kycDocuments()
    {
        return $this->hasMany(UserKycDocument::class);
    }

    public function securitySettings()
    {
        return $this->hasOne(UserSecurity::class);
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }

    public function vipStatus()
    {
        return $this->hasOne(UserVipStatus::class);
    }

    public function rewards()
    {
        return $this->hasMany(UserReward::class);
    }

    public function promotionClaims()
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
}
