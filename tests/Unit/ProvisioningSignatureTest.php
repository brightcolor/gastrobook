<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Provisioning\Signature;
use PHPUnit\Framework\TestCase;

/**
 * Die Prüfwerte des Vertrags (Skill kundenpanel-saas, vertrag-v1.md),
 * gerechnet mit modules/Saas/Signature.php des Panels und gegengeprüft mit
 * Python hmac. Weicht swayy ab, passt kein Aufruf des Panels mehr.
 */
final class ProvisioningSignatureTest extends TestCase
{
    private const KEY = 'pruefschluessel-0123456789abcdef0123456789abcdef';

    public function test_signatur_eines_aufrufs_ohne_koerper(): void
    {
        $this->assertSame(
            'fc85f825d2185536e562ceaa8bebbcb97e73f2e8050c53ff2a5ac868ade86048',
            Signature::request(self::KEY, '1760000000', 'GET', '/provisioning/v1/info', '')
        );
    }

    public function test_signatur_mit_leerem_objekt(): void
    {
        $this->assertSame(
            '7fbe40fedbbaf1a4b133c17f06feb0fdbaaf7b5c4c820f2c57ed28016a8f3253',
            Signature::request(self::KEY, '1760000000', 'post', '/provisioning/v1/accounts/fbtest-42/approve', '{}')
        );
    }

    public function test_signatur_mit_koerper(): void
    {
        $this->assertSame(
            '8cdc4101e3a6293abdb5813b0e4ba4f79750626f337d2c038998f778c95fb39a',
            Signature::request(self::KEY, '1760000000', 'POST', '/provisioning/v1/accounts/fbtest-42/cancel', '{"contract":"fbtest-42","reason":"customer"}')
        );
    }

    public function test_signatur_des_rueckwegs(): void
    {
        $this->assertSame(
            '4025d4adb1ed7ff812dc93f3e5863b8eefb2724dc080a12e2d5b39a2838fef3b',
            Signature::back(self::KEY, '1760000000', '0123456789abcdef0123456789abcdef', 'swayy', 'fbtest-42')
        );
    }

    public function test_ein_anderer_koerper_gibt_eine_andere_signatur(): void
    {
        $this->assertNotSame(
            Signature::request(self::KEY, '1760000000', 'POST', '/provisioning/v1/accounts/fbtest-42/cancel', '{"contract":"fbtest-42","reason":"customer"}'),
            Signature::request(self::KEY, '1760000000', 'POST', '/provisioning/v1/accounts/fbtest-42/cancel', '{"reason":"customer","contract":"fbtest-42"}')
        );
    }
}
