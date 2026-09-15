<?php

namespace Tests\Unit\Services\Application\Locale;

use HiEvents\Services\Application\Locale\LocaleService;
use Illuminate\Config\Repository;
use Tests\TestCase;

class LocaleServiceTest extends TestCase
{
    private LocaleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LocaleService(new Repository(['app' => ['locale' => 'se']]));
    }

    public function test_exact_supported_locale_is_returned(): void
    {
        $this->assertSame('de', $this->service->getLocaleOrDefault('de'));
        $this->assertSame('pt-br', $this->service->getLocaleOrDefault('pt-br'));
    }

    public function test_swedish_browser_tags_resolve_to_se(): void
    {
        $this->assertSame('se', $this->service->getLocaleOrDefault('sv'));
        $this->assertSame('se', $this->service->getLocaleOrDefault('sv-SE'));
        $this->assertSame('se', $this->service->getLocaleOrDefault('sv_SE'));
    }

    public function test_regional_tags_match_on_base_language(): void
    {
        $this->assertSame('de', $this->service->getLocaleOrDefault('de-AT'));
        $this->assertSame('en', $this->service->getLocaleOrDefault('en_GB'));
    }

    public function test_unknown_locale_falls_back_to_the_configured_default(): void
    {
        $this->assertSame('se', $this->service->getLocaleOrDefault('xx'));
        $this->assertSame('se', $this->service->getLocaleOrDefault(null));
    }
}
