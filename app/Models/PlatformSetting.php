<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Small key/value store for settings the admin changes from the panel.
 */
class PlatformSetting extends Model
{
    /** Platform fee taken from each seller payment, in percent (0 = no fee). */
    public const PLATFORM_FEE_PERCENT = 'platform_fee_percent';

    /** Optional fixed platform fee per seller payment, in cents. */
    public const PLATFORM_FEE_FIXED_CENTS = 'platform_fee_fixed_cents';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("platform_setting:{$key}", fn () => static::where('key', $key)->value('value')) ?? $default;
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        Cache::forget("platform_setting:{$key}");
    }
}
