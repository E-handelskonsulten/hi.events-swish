<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Marketing;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Marketing\DTO\MarketingOptOutStatusDTO;
use HiEvents\Services\Domain\Marketing\MarketingOptOutTokenService;
use Psr\Log\LoggerInterface;

class MarketingOptOutHandler
{
    public function __construct(
        private readonly MarketingOptOutTokenService $tokens,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function status(string $token): MarketingOptOutStatusDTO
    {
        $order = $this->orderForToken($token);

        return new MarketingOptOutStatusDTO(
            organizerName: $order->getEvent()->getOrganizer()->getName(),
            optedIn: $order->getOptedIntoMarketingAt() !== null,
        );
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function optOut(string $token): MarketingOptOutStatusDTO
    {
        $order = $this->orderForToken($token);
        $organizer = $order->getEvent()->getOrganizer();

        $revoked = $this->orderRepository->revokeMarketingConsent(
            organizerId: $organizer->getId(),
            email: $order->getEmail(),
            phone: $order->getPhone(),
        );

        $this->logger->info('Marketing consent revoked', [
            'order_id' => $order->getId(),
            'organizer_id' => $organizer->getId(),
            'orders_updated' => $revoked,
        ]);

        return new MarketingOptOutStatusDTO(organizerName: $organizer->getName(), optedIn: false);
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function orderForToken(string $token)
    {
        $orderId = $this->tokens->orderIdFromToken($token);
        $order = $orderId === null ? null : $this->orderRepository
            ->loadRelation(new Relationship(EventDomainObject::class, nested: [
                new Relationship(OrganizerDomainObject::class, name: 'organizer'),
            ], name: 'event'))
            ->findFirstWhere(['id' => $orderId]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        return $order;
    }
}
