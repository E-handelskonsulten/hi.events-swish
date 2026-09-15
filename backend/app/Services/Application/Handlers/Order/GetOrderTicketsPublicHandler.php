<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;

class GetOrderTicketsPublicHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(string $orderShortId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(
                domainObject: AttendeeDomainObject::class,
                nested: [
                    new Relationship(domainObject: ProductDomainObject::class, nested: [
                        new Relationship(domainObject: ProductPriceDomainObject::class),
                    ], name: 'product'),
                    new Relationship(domainObject: EventOccurrenceDomainObject::class, nested: [
                        new Relationship(domainObject: EventLocationDomainObject::class, nested: [
                            new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
                        ], name: 'event_location'),
                    ], name: 'event_occurrence'),
                ],
            ))
            ->findFirstWhere([OrderDomainObjectAbstract::SHORT_ID => $orderShortId]);

        if ($order === null || $order->getStatus() === OrderStatus::RESERVED->name) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        return $order;
    }
}
