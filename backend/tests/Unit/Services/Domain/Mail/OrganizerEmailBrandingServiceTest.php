<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\Mail\OrganizerEmailBrandingService;
use Mockery as m;
use Tests\TestCase;

class OrganizerEmailBrandingServiceTest extends TestCase
{
    public function test_returns_the_cdn_url_of_the_organizer_logo(): void
    {
        $organizer = (new OrganizerDomainObject)->setId(7);
        $logo = (new ImageDomainObject)->setPath('organizer_logo/abc.png');

        $repository = m::mock(ImageRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'entity_id' => 7,
                'entity_type' => ImageType::ORGANIZER_LOGO->getEntityType(),
                'type' => ImageType::ORGANIZER_LOGO->name,
            ])
            ->andReturn($logo);

        $url = (new OrganizerEmailBrandingService($repository))->logoUrl($organizer);

        $this->assertStringEndsWith('/organizer_logo/abc.png', $url);
    }

    public function test_returns_null_when_the_organizer_has_no_logo(): void
    {
        $repository = m::mock(ImageRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->once()->andReturnNull();

        $this->assertNull((new OrganizerEmailBrandingService($repository))->logoUrl((new OrganizerDomainObject)->setId(7)));
    }
}
