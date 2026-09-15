<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;

class OrganizerEmailBrandingService
{
    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
    ) {}

    public function logoUrl(OrganizerDomainObject $organizer): ?string
    {
        /** @var ImageDomainObject|null $logo */
        $logo = $this->imageRepository->findFirstWhere([
            ImageDomainObjectAbstract::ENTITY_ID => $organizer->getId(),
            ImageDomainObjectAbstract::ENTITY_TYPE => ImageType::ORGANIZER_LOGO->getEntityType(),
            ImageDomainObjectAbstract::TYPE => ImageType::ORGANIZER_LOGO->name,
        ]);

        return $logo ? Url::getCdnUrl($logo->getPath()) : null;
    }
}
