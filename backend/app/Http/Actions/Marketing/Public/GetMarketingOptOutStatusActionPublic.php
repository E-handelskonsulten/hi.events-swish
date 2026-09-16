<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Marketing\Public;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Marketing\MarketingOptOutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetMarketingOptOutStatusActionPublic extends BaseAction
{
    public function __construct(private readonly MarketingOptOutHandler $handler) {}

    /**
     * Show who a marketing opt-out link belongs to
     */
    public function __invoke(string $token): JsonResponse|Response
    {
        try {
            $status = $this->handler->status($token);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse(['data' => [
            'organizer_name' => $status->organizerName,
            'opted_in' => $status->optedIn,
        ]]);
    }
}
