<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Organizers\Settings;

use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class OrganizerHomepageThemeTest extends SwishFeatureTestCase
{
    private const THEME = [
        'accent' => '#e11d48',
        'background' => '#0f172a',
        'mode' => 'dark',
        'background_type' => 'COLOR',
        'font_family' => 'Inter',
    ];

    public function test_a_new_organizer_starts_with_a_theme_the_designer_can_read(): void
    {
        $organizerId = $this->createOrganizerThroughApi();

        $theme = $this->getJson("/organizers/{$organizerId}/settings", $this->authHeaders())
            ->assertOk()
            ->json('data.homepage_theme_settings');

        $this->assertSame(['accent', 'background', 'background_type', 'font_family', 'mode'], $this->sortedKeys($theme));
        $this->assertSame('COLOR', $theme['background_type']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $theme['accent']);
    }

    public function test_a_saved_theme_is_read_back_by_the_designer_and_the_public_page(): void
    {
        $organizerId = $this->createOrganizerThroughApi();

        $saved = $this->patchJson("/organizers/{$organizerId}/settings", [
            'homepage_theme_settings' => self::THEME,
        ], $this->authHeaders())->assertOk()->json('data.homepage_theme_settings');
        $this->assertThemeEquals(self::THEME, $saved);

        $this->assertThemeEquals(
            self::THEME,
            $this->getJson("/organizers/{$organizerId}/settings", $this->authHeaders())->assertOk()->json('data.homepage_theme_settings'),
        );

        $this->flushSession();
        $this->assertThemeEquals(
            self::THEME,
            $this->getJson("/public/organizers/{$organizerId}")->assertOk()->json('data.settings.homepage_theme_settings'),
        );
    }

    public function test_an_invalid_theme_value_is_rejected_instead_of_silently_dropped(): void
    {
        $organizerId = $this->createOrganizerThroughApi();

        $this->patchJson("/organizers/{$organizerId}/settings", [
            'homepage_theme_settings' => ['accent' => 'red'] + self::THEME,
        ], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['homepage_theme_settings.accent']);
    }

    private function createOrganizerThroughApi(): int
    {
        return $this->postJson('/organizers', [
            'name' => 'Fresh Org',
            'email' => 'fresh-'.uniqid().'@example.test',
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
        ], $this->authHeaders())->assertCreated()->json('data.id');
    }

    private function assertThemeEquals(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    private function sortedKeys(array $theme): array
    {
        $keys = array_keys($theme);
        sort($keys);

        return $keys;
    }
}
