<?php

namespace App\Enums;

use App\Exceptions\BusinessRuleException;

enum CodeMode: int
{
    case SMS = 1;
    case Email = 2;

    /**
     * Resolve the persisted system setting without leaking ValueError to API callers.
     */
    public static function fromConfiguration(mixed $value): self
    {
        $normalized = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^[12]$/D', $value) === 1 => (int) $value,
            default => null,
        };

        $mode = $normalized === null ? null : self::tryFrom($normalized);
        if ($mode === null) {
            throw new BusinessRuleException('CODE_MODE_INVALID', '验证码通道配置无效', 500);
        }

        return $mode;
    }
}
