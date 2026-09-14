<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

class SwishPayerAliasNormalizer
{
    public const PATTERN = '/^(?:\+?46|0)7\d{8}$/';

    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if (! preg_match(self::PATTERN, $digits)) {
            return null;
        }

        if (str_starts_with($digits, '+46')) {
            return substr($digits, 1);
        }

        if (str_starts_with($digits, '0')) {
            return '46'.substr($digits, 1);
        }

        return $digits;
    }

    public static function mask(?string $alias): ?string
    {
        if ($alias === null || strlen($alias) < 4) {
            return $alias;
        }

        return str_repeat('*', strlen($alias) - 2).substr($alias, -2);
    }
}
