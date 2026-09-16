<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Marketing\Public;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Marketing\MarketingOptOutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MarketingOptOutActionPublic extends BaseAction
{
    public function __construct(private readonly MarketingOptOutHandler $handler) {}

    /**
     * Withdraw marketing consent for the buyer behind an opt-out link
     */
    public function __invoke(string $token): JsonResponse|Response
    {
        try {
            $status = $this->handler->optOut($token);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse(['data' => [
            'organizer_name' => $status->organizerName,
            'opted_in' => $status->optedIn,
        ]]);
    }
}
