<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    public function test_mail_settings_have_their_defaults(): void
    {
        $this->assertSame('du', config('swayy.mail.owner_address'));
        $this->assertTrue(config('swayy.mail.powered_by'));
        $this->assertSame(200, config('swayy.mail.logo_max_kb'));
        $this->assertSame(44, config('swayy.mail.logo_height'));
        $this->assertSame(4.5, config('swayy.mail.contrast_min'));
        $this->assertSame(2, config('swayy.mail.details_min_rows'));
        $this->assertSame(40, config('swayy.mail.label_max'));
        $this->assertSame(60, config('swayy.mail.button_label_max'));
        $this->assertSame(60, config('swayy.guest_auth.login_minutes'));
        $this->assertSame(1440, config('swayy.guest_auth.verify_minutes'));
        $this->assertSame(72, config('swayy.billing_request.confirm_hours'));
        $this->assertSame('#ffffff', config('swayy.mail.palette.on_brand'));
    }

    public function test_every_mail_variable_is_documented_in_env_example(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'SWAYY_MAIL_OWNER_ADDRESS', 'SWAYY_MAIL_POWERED_BY', 'SWAYY_MAIL_LOGO_MAX_KB', 'SWAYY_MAIL_LOGO_HEIGHT',
            'SWAYY_MAIL_CONTRAST_MIN', 'SWAYY_MAIL_DETAILS_MIN_ROWS', 'SWAYY_MAIL_LABEL_MAX', 'SWAYY_MAIL_BUTTON_LABEL_MAX',
            'SWAYY_GUEST_LOGIN_LINK_MINUTES', 'SWAYY_GUEST_VERIFY_LINK_MINUTES', 'SWAYY_BILLING_CONFIRM_HOURS',
        ] as $variable) {
            $this->assertStringContainsString($variable.'=', $example, $variable);
        }
    }
}
