<?php

namespace Tests\Unit\Helper;

use Carbon\Carbon;
use HiEvents\Helper\LocaleHelper;
use Tests\TestCase;

class LocaleHelperTest extends TestCase
{
    public function test_resolve_alias_maps_browser_swedish_tags_to_se(): void
    {
        $this->assertSame('se', LocaleHelper::resolveAlias('sv'));
        $this->assertSame('se', LocaleHelper::resolveAlias('sv-SE'));
        $this->assertSame('se', LocaleHelper::resolveAlias('sv_SE'));
        $this->assertSame('se', LocaleHelper::resolveAlias('sv-FI'));
    }

    public function test_resolve_alias_leaves_other_tags_untouched(): void
    {
        $this->assertSame('de-at', LocaleHelper::resolveAlias('de-AT'));
        $this->assertSame('en', LocaleHelper::resolveAlias('en'));
        $this->assertNull(LocaleHelper::resolveAlias(null));
    }

    public function test_icu_locale_treats_se_as_swedish_not_northern_sami(): void
    {
        $this->assertSame('sv_SE', LocaleHelper::toIcuLocale('se'));
        $this->assertSame('sv', LocaleHelper::toCarbonLocale('se'));
        $this->assertSame('en_US', LocaleHelper::toIcuLocale('unknown'));
    }

    public function test_icu_locale_defaults_to_the_application_locale(): void
    {
        $this->app->setLocale('se');

        $this->assertSame('sv_SE', LocaleHelper::toIcuLocale());
    }

    public function test_swedish_dates_use_swedish_names_and_24_hour_clock(): void
    {
        $date = Carbon::parse('2026-10-03 21:30:00');

        $this->assertSame('3 oktober 2026', LocaleHelper::formatDate($date, 'se'));
        $this->assertSame('21:30', LocaleHelper::formatTime($date, 'se'));
        $this->assertSame('lör 3 okt 2026 · 21:30', LocaleHelper::formatDateTimeShort($date, 'se'));
        $this->assertSame('2026-10-03', LocaleHelper::formatNumericDate($date, 'se'));
        $this->assertSame('dddd D MMMM · HH:mm', LocaleHelper::pattern('dayAndTime', 'se'));
    }

    public function test_english_dates_keep_the_us_style(): void
    {
        $date = Carbon::parse('2026-10-03 21:30:00');

        $this->assertSame('October 3, 2026', LocaleHelper::formatDate($date, 'en'));
        $this->assertSame('9:30 PM', LocaleHelper::formatTime($date, 'en'));
        $this->assertSame('Sat, Oct 3, 2026 · 9:30 PM', LocaleHelper::formatDateTimeShort($date, 'en'));
        $this->assertSame('03/10/2026', LocaleHelper::formatNumericDate($date, 'en'));
    }

    public function test_other_locales_fall_back_to_24_hour_day_first_formats(): void
    {
        $date = Carbon::parse('2026-10-03 21:30:00');

        $this->assertSame('3 Oktober 2026', LocaleHelper::formatDate($date, 'de'));
        $this->assertSame('21:30', LocaleHelper::formatTime($date, 'de'));
    }
}
