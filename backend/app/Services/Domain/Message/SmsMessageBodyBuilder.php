<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Message;

use HiEvents\DomainObjects\Enums\MessagePurpose;
use HiEvents\Services\Domain\Marketing\MarketingOptOutTokenService;

class SmsMessageBodyBuilder
{
    public function __construct(private readonly MarketingOptOutTokenService $optOutTokens) {}

    public function build(string $body, MessagePurpose $purpose, int $orderId): string
    {
        $body = trim($body);

        if ($purpose !== MessagePurpose::MARKETING) {
            return $body;
        }

        return $body."\n".$this->optOutSuffix($this->optOutTokens->urlForOrder($orderId));
    }

    public function previewSuffixLength(): int
    {
        return mb_strlen("\n".$this->optOutSuffix($this->optOutTokens->urlForOrder(PHP_INT_MAX)));
    }

    private function optOutSuffix(string $url): string
    {
        return __('Unsubscribe: :url', ['url' => $url], 'se');
    }
}
