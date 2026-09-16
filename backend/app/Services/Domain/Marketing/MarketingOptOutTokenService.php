<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Marketing;

use HiEvents\Helper\Url;
use Illuminate\Config\Repository;

class MarketingOptOutTokenService
{
    private const SIGNATURE_LENGTH = 10;

    public function __construct(private readonly Repository $config) {}

    public function tokenForOrder(int $orderId): string
    {
        return $orderId.'.'.$this->signature($orderId);
    }

    public function urlForOrder(int $orderId): string
    {
        return sprintf(Url::getFrontEndUrlFromConfig(Url::MARKETING_OPT_OUT), $this->tokenForOrder($orderId));
    }

    public function orderIdFromToken(string $token): ?int
    {
        [$orderId, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! ctype_digit($orderId) || $signature === '') {
            return null;
        }

        return hash_equals($this->signature((int) $orderId), $signature) ? (int) $orderId : null;
    }

    private function signature(int $orderId): string
    {
        $key = (string) $this->config->get('app.key');
        $digest = hash_hmac('sha256', 'marketing-opt-out:'.$orderId, $key, true);

        return substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, self::SIGNATURE_LENGTH);
    }
}
