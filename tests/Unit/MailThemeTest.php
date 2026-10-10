<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\Layout\MailTheme;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MailThemeTest extends TestCase
{
    public function test_default_brand_keeps_its_color(): void
    {
        $theme = MailTheme::fromBrand(null);

        $this->assertSame('#0f766e', $theme->brand);
        $this->assertSame('#0f766e', $theme->button());
        $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->button(), '#ffffff'));
    }

    public function test_light_brand_is_darkened_until_white_text_is_readable(): void
    {
        $theme = MailTheme::fromBrand('#facc15');

        $this->assertNotSame('#facc15', $theme->button());
        $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->button(), '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->link(), '#ffffff'));
    }

    public function test_white_and_black_brand_stay_usable(): void
    {
        foreach (['#ffffff', '#000000', '#facc15'] as $color) {
            $theme = MailTheme::fromBrand($color);

            $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->button(), '#ffffff'), $color);
            $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->link(), '#ffffff'), $color);
            $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->darkLink(), '#1c1917'), $color);
        }
    }

    public function test_invalid_color_falls_back_to_the_default_with_a_readable_log(): void
    {
        Log::spy();

        $this->assertSame('#0f766e', MailTheme::fromBrand('teal')->brand);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'teal') && str_contains($message, 'Einstellungen'),
        );
    }

    public function test_stricter_contrast_setting_darkens_further(): void
    {
        config(['swayy.mail.contrast_min' => 7.0]);

        $theme = MailTheme::fromBrand('#0f766e');

        $this->assertNotSame('#0f766e', $theme->button());
        $this->assertGreaterThanOrEqual(7.0, MailTheme::contrast($theme->button(), '#ffffff'));
    }

    public function test_tint_and_line_are_light_mixes_of_the_brand(): void
    {
        $theme = MailTheme::fromBrand('#0f766e');

        $this->assertSame(MailTheme::mix('#ffffff', '#0f766e', 0.055), $theme->tint());
        $this->assertSame(MailTheme::mix('#ffffff', '#0f766e', 0.16), $theme->line());
        $this->assertGreaterThan(12.0, MailTheme::contrast($theme->tint(), '#1c1917'));
        $this->assertSame(MailTheme::mix('#1c1917', '#0f766e', 0.16), $theme->darkTint());
    }
}
