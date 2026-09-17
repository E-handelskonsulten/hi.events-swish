<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Refuses to send SMS to numbers on the blocklist, e.g. the Swish MSS test
 * payer alias (0701234567), so sandbox test data can never reach a stranger.
 */
class SmsRecipientGuard
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isBlocked(string $recipient): bool
    {
        if (! $this->config->get('sms.blocklist_enforced')) {
            return false;
        }

        $normalized = self::normalize($recipient);

        foreach ((array) $this->config->get('sms.blocked_recipients', []) as $blocked) {
            if ($normalized !== '' && $normalized === self::normalize((string) $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Logs and returns true when the recipient must be skipped.
     */
    public function refuse(string $recipient, array $context = []): bool
    {
        if (! $this->isBlocked($recipient)) {
            return false;
        }

        $this->logger->warning('SMS skipped: recipient is on the blocklist', $context + [
            'recipient' => $recipient,
        ]);

        return true;
    }

    public static function reason(): string
    {
        return 'Recipient is on the SMS blocklist (test number)';
    }

    /**
     * Compare on national digits so 0701234567, 46701234567 and +46701234567 all match.
     */
    private static function normalize(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (str_starts_with($digits, '46')) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }
}
