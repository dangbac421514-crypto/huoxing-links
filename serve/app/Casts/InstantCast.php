<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Persist an instant through an explicitly configured MySQL session timezone
 * and expose it in the product timezone. MySQL TIMESTAMP values have no
 * offset in their wire format, so both sides of the boundary must agree on
 * the same timezone explicitly.
 */
final class InstantCast implements CastsAttributes
{
    private const DATABASE_TIMEZONE = 'Asia/Shanghai';

    private const PRODUCT_TIMEZONE = 'Asia/Shanghai';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, self::DATABASE_TIMEZONE)
            ->setTimezone(self::productTimezone());
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, self::productTimezone());

        return $instant->setTimezone(self::DATABASE_TIMEZONE)->format('Y-m-d H:i:s');
    }

    public static function productTimezone(): string
    {
        return (string) config('app.timezone', self::PRODUCT_TIMEZONE);
    }
}
