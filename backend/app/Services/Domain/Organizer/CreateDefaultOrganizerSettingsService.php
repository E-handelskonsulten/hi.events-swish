<?php

namespace HiEvents\Services\Domain\Organizer;

use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\Enums\HomepageBackgroundType;
use HiEvents\DomainObjects\Enums\HomepageFontFamily;
use HiEvents\DomainObjects\Enums\OrganizerHomepageVisibility;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;

class CreateDefaultOrganizerSettingsService
{
    public const DEFAULT_THEME = [
        'accent' => '#8b5cf6',
        'background' => '#f5f3ff',
        'mode' => 'light',
        'background_type' => HomepageBackgroundType::COLOR->value,
        'font_family' => HomepageFontFamily::Outfit->value,
    ];

    public function __construct(
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository
    ) {}

    public function createOrganizerSettings(OrganizerDomainObject $organizer): void
    {
        $this->organizerSettingsRepository->create([
            'organizer_id' => $organizer->getId(),
            'homepage_visibility' => OrganizerHomepageVisibility::PUBLIC->name,
            'homepage_theme_settings' => self::DEFAULT_THEME,
            'default_attendee_details_collection_method' => AttendeeDetailsCollectionMethod::PER_ORDER->name,
            'default_pass_platform_fee_to_buyer' => config('app.saas_default_pass_platform_fee_to_buyer', true),
        ]);
    }
}
