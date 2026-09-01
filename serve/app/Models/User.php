<?php

namespace App\Models;

use App\Casts\InstantCast;
use App\Contracts\ReferralCodeGenerator;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Ugly\Base\Casts\Amount;
use Ugly\Base\Traits\SearchModel;
use Ugly\Base\Traits\SerializeDate;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SearchModel, SerializeDate;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'tokens'];

    protected $casts = [
        'type' => UserType::class,
        'credit' => Amount::class.':4',
        'accumulate_credit' => Amount::class.':4',
        'commission' => Amount::class,
        'accumulate_commission' => Amount::class,
        'start_at' => InstantCast::class,
        'end_at' => InstantCast::class,
        'must_change_password' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Production account creation goes through UserAccountCreator; keep this
        // fallback for legacy and direct model creates outside those flows.
        self::creating(function (User $user) {
            if ($user->referral_code !== null && $user->referral_code !== '') {
                return;
            }

            $generator = app(ReferralCodeGenerator::class);
            do {
                $code = $generator->generate();
            } while (self::query()->where('referral_code', $code)->exists());

            $user->referral_code = $code;
        });

        self::updating(function (User $user): void {
            foreach (['parent_id', 'referral_code'] as $attribute) {
                if ($user->isDirty($attribute)) {
                    $user->setAttribute($attribute, $user->getOriginal($attribute));
                }
            }
        });
    }

    // vip套餐
    public function vipPackage(): BelongsTo
    {
        return $this->belongsTo(VipPackage::class, 'vip_id');
    }

    // 推荐人
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // 下级
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // 佣金记录
    public function commissionLogs(): HasMany
    {
        return $this->hasMany(CommissionLog::class, 'user_id');
    }

    // 获取权益配置
    public function getPackageConfig(?string $key = null)
    {
        $package = null;
        if ($this->vip_id) {
            $package = $this->vipPackage;
        }

        return $key ? data_get($package, 'config.'.$key) : $package;
    }
}
