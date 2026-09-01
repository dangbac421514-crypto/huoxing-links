<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SysConfig extends Model
{
    protected $table = 'sys_configs';

    public $incrementing = false;

    protected $fillable = ['slug', 'value', 'desc'];

    protected $primaryKey = 'slug';

    /**
     * 修改器.
     */
    public function value(): Attribute
    {
        return new Attribute(
            get: function ($value) {
                if (! is_string($value) || $value === '') {
                    return $value;
                }

                try {
                    return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    Log::debug('System config value is not valid JSON.');

                    return $value;
                }
            },
            set: function ($value) {
                if (is_null($value) || is_string($value) || is_numeric($value)) {
                    return $value;
                } elseif (is_array($value)) {
                    return json_encode(self::sortAssociativeMaps($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                }

                return null;
            }
        );
    }

    private static function sortAssociativeMaps(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::sortAssociativeMaps($item) : $item, $value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? self::sortAssociativeMaps($item) : $item;
        }

        ksort($value);

        return $value;
    }
}
