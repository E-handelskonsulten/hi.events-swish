<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Jobs\Order\Swish\ProcessSwishPaymentCallbackJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SwishPaymentCallbackAction extends BaseAction
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Swish payment callback
     *
     * Receives the payment request result from Swish. The payload is only used as a trigger; the
     * authoritative status is fetched from Swish over mTLS before the order is updated.
     */
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload['id'])) {
            $this->logger->warning('Swish payment callback rejected: missing id', [
                'ip' => $request->ip(),
            ]);

            return $this->noContentResponse(HttpResponse::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Swish payment callback received', [
            'instruction_uuid' => $payload['id'],
            'remote_status' => $payload['status'] ?? null,
            'payee_payment_reference' => $payload['payeePaymentReference'] ?? null,
            'ip' => $request->ip(),
        ]);

        ProcessSwishPaymentCallbackJob::dispatch($payload);

        return $this->noContentResponse(HttpResponse::HTTP_OK);
    }
}
