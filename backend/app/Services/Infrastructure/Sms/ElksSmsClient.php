<?php

namespace HiEvents\Services\Infrastructure\Sms;

use HiEvents\Exceptions\Sms\SmsDeliveryException;
use HiEvents\Services\Infrastructure\Sms\DTO\SentSmsDTO;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

class ElksSmsClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly Repository $config,
    ) {}

    /**
     * @throws SmsDeliveryException
     */
    public function send(string $to, string $from, string $message): SentSmsDTO
    {
        $username = $this->config->get('sms.elks.username');
        $password = $this->config->get('sms.elks.password');

        if (! $username || ! $password) {
            throw new SmsDeliveryException('46elks credentials are not configured');
        }

        $payload = ['to' => $to, 'from' => $from, 'message' => $message];

        if ($this->config->get('sms.dry_run')) {
            $payload['dryrun'] = 'yes';
        }

        try {
            $response = $this->http
                ->withBasicAuth($username, $password)
                ->timeout((int) $this->config->get('sms.elks.timeout_seconds', 10))
                ->asForm()
                ->post(rtrim($this->config->get('sms.elks.base_url'), '/').'/sms', $payload);
        } catch (Throwable $exception) {
            throw new SmsDeliveryException('Could not reach 46elks: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new SmsDeliveryException(sprintf('46elks rejected the SMS (HTTP %d): %s', $response->status(), trim($response->body())));
        }

        $body = $response->json();

        return new SentSmsDTO(
            providerMessageId: (string) ($body['id'] ?? ''),
            status: (string) ($body['status'] ?? 'created'),
            parts: (int) ($body['parts'] ?? 1),
            dryRun: (bool) $this->config->get('sms.dry_run'),
        );
    }
}
