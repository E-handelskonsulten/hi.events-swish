<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Jobs\Order\Swish\ProcessSwishRefundCallbackJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SwishRefundCallbackAction extends BaseAction
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Swish refund callback
     *
     * Receives the refund result from Swish. The payload only triggers processing; the authoritative
     * refund status is fetched from Swish over mTLS before the order ledger is updated.
     */
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload['id'])) {
            $this->logger->warning('Swish refund callback rejected: missing id', ['ip' => $request->ip()]);

            return $this->noContentResponse(HttpResponse::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Swish refund callback received', [
            'instruction_uuid' => $payload['id'],
            'remote_status' => $payload['status'] ?? null,
            'ip' => $request->ip(),
        ]);

        ProcessSwishRefundCallbackJob::dispatch($payload);

        return $this->noContentResponse(HttpResponse::HTTP_OK);
    }
}
