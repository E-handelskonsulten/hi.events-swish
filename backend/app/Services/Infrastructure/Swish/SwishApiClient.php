<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Exceptions\Swish\SwishTransientException;
use HiEvents\Exceptions\Swish\SwishValidationException;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishCreatePaymentResponseDTO;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishPaymentRequestDTO;
use HiEvents\Services\Infrastructure\Utlitiy\Retry\Retrier;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishApiClient
{
    public const PAYMENT_REQUESTS_V2 = '/swish-cpcapi/api/v2/paymentrequests/';

    public const PAYMENT_REQUESTS_V1 = '/swish-cpcapi/api/v1/paymentrequests/';

    public const REFUNDS_V2 = '/swish-cpcapi/api/v2/refunds/';

    public const REFUNDS_V1 = '/swish-cpcapi/api/v1/refunds/';

    public const ERROR_CODE_NOT_CANCELLABLE = 'RP07';

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly SwishClientFactory $clientFactory,
        private readonly Retrier $retrier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function createPaymentRequest(
        SwishConnectionConfigDTO $connection,
        string $instructionUuid,
        array $payload,
        array $logContext = [],
    ): SwishCreatePaymentResponseDTO {
        $response = $this->send(
            connection: $connection,
            method: 'PUT',
            path: self::PAYMENT_REQUESTS_V2.$instructionUuid,
            options: [RequestOptions::JSON => $payload],
            logContext: $logContext + ['instruction_uuid' => $instructionUuid, 'operation' => 'create_payment_request'],
        );

        return new SwishCreatePaymentResponseDTO(
            location: $response->getHeaderLine('Location') ?: null,
            paymentRequestToken: $response->getHeaderLine('PaymentRequestToken') ?: null,
        );
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function getPaymentRequest(
        SwishConnectionConfigDTO $connection,
        string $instructionUuid,
        array $logContext = [],
    ): SwishPaymentRequestDTO {
        $response = $this->send(
            connection: $connection,
            method: 'GET',
            path: self::PAYMENT_REQUESTS_V1.$instructionUuid,
            options: [],
            logContext: $logContext + ['instruction_uuid' => $instructionUuid, 'operation' => 'get_payment_request'],
        );

        return SwishPaymentRequestDTO::fromSwishPayload($this->decode($response));
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function cancelPaymentRequest(
        SwishConnectionConfigDTO $connection,
        string $instructionUuid,
        array $logContext = [],
    ): SwishPaymentRequestDTO {
        $response = $this->send(
            connection: $connection,
            method: 'PATCH',
            path: self::PAYMENT_REQUESTS_V1.$instructionUuid,
            options: [
                RequestOptions::HEADERS => ['Content-Type' => 'application/json-patch+json'],
                RequestOptions::BODY => json_encode([
                    ['op' => 'replace', 'path' => '/status', 'value' => 'cancelled'],
                ], JSON_THROW_ON_ERROR),
            ],
            logContext: $logContext + ['instruction_uuid' => $instructionUuid, 'operation' => 'cancel_payment_request'],
        );

        return SwishPaymentRequestDTO::fromSwishPayload($this->decode($response));
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function createRefund(
        SwishConnectionConfigDTO $connection,
        string $instructionUuid,
        array $payload,
        array $logContext = [],
    ): string {
        $response = $this->send(
            connection: $connection,
            method: 'PUT',
            path: self::REFUNDS_V2.$instructionUuid,
            options: [RequestOptions::JSON => $payload],
            logContext: $logContext + ['instruction_uuid' => $instructionUuid, 'operation' => 'create_refund'],
        );

        return $response->getHeaderLine('Location');
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function getRefund(
        SwishConnectionConfigDTO $connection,
        string $instructionUuid,
        array $logContext = [],
    ): array {
        $response = $this->send(
            connection: $connection,
            method: 'GET',
            path: self::REFUNDS_V1.$instructionUuid,
            options: [],
            logContext: $logContext + ['instruction_uuid' => $instructionUuid, 'operation' => 'get_refund'],
        );

        return $this->decode($response);
    }

    /**
     * Performs a request that only needs to prove the mTLS handshake and merchant certificate work.
     *
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function verifyConnection(SwishConnectionConfigDTO $connection): void
    {
        $probeUuid = strtoupper(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()));

        try {
            $this->send(
                connection: $connection,
                method: 'GET',
                path: self::PAYMENT_REQUESTS_V1.$probeUuid,
                options: [],
                logContext: ['operation' => 'verify_connection'],
            );
        } catch (SwishApiException $exception) {
            if ($exception->getHttpStatus() === 404) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    private function send(
        SwishConnectionConfigDTO $connection,
        string $method,
        string $path,
        array $options,
        array $logContext,
    ): ResponseInterface {
        $client = $this->clientFactory->create($connection);
        $logContext += ['method' => $method, 'path' => $path, 'environment' => $connection->environment->value];

        try {
            /** @var ResponseInterface $response */
            $response = $this->retrier->retry(
                callableAction: function (int $attempt) use ($client, $method, $path, $options, $logContext) {
                    try {
                        $response = $client->request($method, $path, $options);
                    } catch (ClientException $exception) {
                        if ($exception->getResponse()->getStatusCode() === 429) {
                            throw new SwishTransientException(
                                message: __('Swish rate limit reached.'),
                                httpStatus: 429,
                                previous: $exception,
                            );
                        }

                        throw $exception;
                    }

                    $this->logger->info('Swish API call succeeded', $logContext + [
                        'attempt' => $attempt,
                        'http_status' => $response->getStatusCode(),
                    ]);

                    return $response;
                },
                maxAttempts: self::MAX_ATTEMPTS,
                baseDelayMs: 300,
                maxDelayMs: 2000,
                onFailure: function (int $attempt, Throwable $exception) use ($logContext) {
                    $this->logger->warning('Swish API call failed', $logContext + [
                        'attempt' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);
                },
                retryOn: [ConnectException::class, ServerException::class, SwishTransientException::class],
            );
        } catch (ClientException $exception) {
            throw $this->mapClientException($exception, $logContext);
        } catch (SwishTransientException $exception) {
            throw $exception;
        } catch (ServerException $exception) {
            throw new SwishTransientException(
                message: __('Swish is temporarily unavailable. Please try again.'),
                httpStatus: $exception->getResponse()->getStatusCode(),
                previous: $exception,
            );
        } catch (ConnectException|TransferException $exception) {
            throw new SwishTransientException(
                message: __('Could not reach Swish. Please try again.'),
                previous: $exception,
            );
        }

        return $response;
    }

    private function mapClientException(ClientException $exception, array $logContext): SwishApiException
    {
        $response = $exception->getResponse();
        $status = $response->getStatusCode();
        $errors = $this->decodeErrors($response);

        $this->logger->error('Swish API rejected request', $logContext + [
            'http_status' => $status,
            'errors' => $errors,
        ]);

        if ($status === 422) {
            return new SwishValidationException(
                message: $errors[0]['errorMessage'] ?? __('Swish rejected the request.'),
                httpStatus: $status,
                errors: $errors,
                previous: $exception,
            );
        }

        return new SwishApiException(
            message: match ($status) {
                401 => __('Swish rejected the merchant certificate.'),
                403 => __('The Swish number does not match the merchant certificate.'),
                404 => __('The Swish payment request was not found.'),
                default => __('Swish rejected the request.'),
            },
            httpStatus: $status,
            errors: $errors,
            previous: $exception,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeErrors(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['errorCode'])) {
            return [$decoded];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @throws SwishApiException
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwishApiException(
                message: __('Swish returned an unreadable response.'),
                httpStatus: $response->getStatusCode(),
                previous: $exception,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
