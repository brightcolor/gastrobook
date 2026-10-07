<?php

namespace Tests\Unit;

use App\Services\Mail\PostalSignatureVerifier;
use Tests\TestCase;

class PostalSignatureVerifierTest extends TestCase
{
    // Festes Test-Schluesselpaar: openssl_pkey_new braucht auf manchen Hosts
    // eine openssl.cnf und scheitert dort. Signieren/Pruefen mit festem PEM
    // kommt ohne Schluesselerzeugung aus und laeuft ueberall gleich.
    private const PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCx8NU4DaI3KEuK
        dz1JXPfoHUzDlrhskgrWN6CGtHvkrhkwuUBhbNeLUi0OcFOAU9aj++Y9jN+9jW97
        4qNBCW4fm//teL0cQSh8v1WMpByo9MDGjJajrZRPfM7xmRp1MlgXftwp0yxR7Jnx
        MkUc3efh3hcT5s0TlqH1YlQh2tHkpXlcAcdbFtP27rng8dQnmN/8+dU0mJrfkMYD
        xEPb8//PfJdZl8flMq4rzukgm8QVUwRWV/XwBMqnXioFFRUl5ADEWxcf6noYJFQp
        9y70SZDqxQlMILQi6hWyl3+szHd40ir8m0XPBXTfqlOhQAwEu8UhgK5bQmzi7Rdg
        cbX17sGNAgMBAAECggEAF9+F28Vd1CSgg0KdgwWUGnIukGHc+xlaQYSpEX7kIw7b
        QQGPClh9+qqFx6rOU9NBDYiISrhnHu6mMcAlwwiu26gkXV9A5VTgnQwBQE3sV8au
        mR+CPmeAFGyxnxG5eQEjdyjar58uEmJkrPWKTDssFyOi2QAILO6daryh06ugqW+e
        AX2WRiHPPjR08EFV7fiNKLbyasJxG0X9fuljGCM1e2QrvLNu5rbLwMBHVfjO/GxB
        QfkJY0uFD+M4Uo0goJBRl/w4vRZ71KRXVhFbQ9GeUkeJdlCWs/Ye1VUjU5Z84B06
        drrPIWnvZgLS3UKJy/sTXHCkBlDc9Lj4kLN5GitJiQKBgQDeuXP/1raUHFSOQlm+
        73gppcxr9pOhRGMgH+sPRUdUZvM64NHFX9kVMwjk+MA2vY4ElO7nVl4tInAYAMUz
        hJsblSd5BCm1K/VVsILP4/LRHxCQ3bbjf1DV5zCUdImiiCgXORsgzSHIQ+8pqKJh
        ZZ3xxj5frYk6f8Jv8Q2PC5pMDwKBgQDMhomX+HIu45kM1XBnOk6fhCXE9q6Bwcq7
        KBuHlKWigEQNvQYw9ws5rmmVLkc2J9RIuxzpDSdGSb2FAk803pzR67LZaDF5936i
        Zn15hj++f4s2STPiK3cBivYWipqrIfGRNmQK89ge+WrRxOfDUMVzfA/iv/teIWNL
        SRMU6cZsowKBgQDCNh6TeWwNrMKCphLR7sjuMBgYEJRc7GAvdAWpdDSlwXvY3I+u
        t0x5Mt5PoyUg1puPHTtRWDuYyc3K8GkE6l3CaIZZ/SpNQ76TcO4wT0m91oPAfsTq
        jWs0insPCKu3oVisH2yrZpRNqAdVYSnvGgfm+oILNixSaXNn319+W5S5OwKBgF1V
        +GzWAKXNUAc/UHCLd13snJ/qQ3EL00zd3NJez8f86RGr9ata0lCce6qM2Aqq2oHm
        gicIzaeR918/0o26Ga7i9Vep6QpUHAJY62IOFgEFi65WccsBMuoNVIis8DCw6ODw
        BW/KIBLimBDq3ymPLsypDUbZfglTC1FMI90jYl4pAoGAUW9zYTL3SrfagjL3I5LB
        df5VsSWZG9PggMc8To5FDs5rLutMIec0FJQJAEKfeqc+9t4JTGK9FuyOGun9bPbj
        C2QElBF+3xdJnXtC/y1wi8fW+fkfPgfjA1/xNQbNxhDLRM13bWK6TkRUFeST7nXl
        bwU3TgtayseBAv+cshyV8rQ=
        -----END PRIVATE KEY-----
        PEM;

    private const PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsfDVOA2iNyhLinc9SVz3
        6B1Mw5a4bJIK1jeghrR75K4ZMLlAYWzXi1ItDnBTgFPWo/vmPYzfvY1ve+KjQQlu
        H5v/7Xi9HEEofL9VjKQcqPTAxoyWo62UT3zO8ZkadTJYF37cKdMsUeyZ8TJFHN3n
        4d4XE+bNE5ah9WJUIdrR5KV5XAHHWxbT9u654PHUJ5jf/PnVNJia35DGA8RD2/P/
        z3yXWZfH5TKuK87pIJvEFVMEVlf18ATKp14qBRUVJeQAxFsXH+p6GCRUKfcu9EmQ
        6sUJTCC0IuoVspd/rMx3eNIq/JtFzwV036pToUAMBLvFIYCuW0Js4u0XYHG19e7B
        jQIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.guest_mail_relay.inbound_public_key' => $this->normalise(self::PUBLIC_KEY)]);
        config(['swayy.guest_mail_relay.inbound_signature_algo' => 'sha256']);
    }

    private function normalise(string $pem): string
    {
        // Heredoc ist eingerückt; führende Leerzeichen je Zeile entfernen.
        return implode("\n", array_map('trim', explode("\n", trim($pem))));
    }

    private function sign(string $payload): string
    {
        openssl_sign($payload, $sig, $this->normalise(self::PRIVATE_KEY), OPENSSL_ALGO_SHA256);

        return base64_encode($sig);
    }

    public function test_valid_signature_passes(): void
    {
        $payload = '{"rcpt_to":"x"}';
        $this->assertTrue((new PostalSignatureVerifier)->verify($payload, $this->sign($payload)));
    }

    public function test_tampered_payload_fails(): void
    {
        $this->assertFalse((new PostalSignatureVerifier)->verify('{"rcpt_to":"y"}', $this->sign('{"rcpt_to":"x"}')));
    }

    public function test_without_key_fails(): void
    {
        config(['swayy.guest_mail_relay.inbound_public_key' => '']);
        $this->assertFalse((new PostalSignatureVerifier)->verify('x', 'x'));
    }

    /** PEM ohne Rahmen und Umbrüche: der p=-Wert, wie Postal ihn ausgibt. */
    private function bareBase64(): string
    {
        $zeilen = array_filter(
            array_map('trim', explode("\n", trim(self::PUBLIC_KEY))),
            fn (string $zeile) => $zeile !== '' && ! str_starts_with($zeile, '-----'),
        );

        return implode('', $zeilen);
    }

    public function test_bare_p_value_from_postal_is_accepted(): void
    {
        config(['swayy.guest_mail_relay.inbound_public_key' => $this->bareBase64()]);
        $payload = '{"rcpt_to":"x"}';

        $this->assertTrue((new PostalSignatureVerifier)->verify($payload, $this->sign($payload)));
    }

    public function test_full_dkim_record_is_accepted(): void
    {
        config(['swayy.guest_mail_relay.inbound_public_key' => 'v=DKIM1; t=s; h=sha256; p='.$this->bareBase64()]);
        $payload = '{"rcpt_to":"x"}';

        $this->assertTrue((new PostalSignatureVerifier)->verify($payload, $this->sign($payload)));
    }

    public function test_unreadable_key_fails(): void
    {
        config(['swayy.guest_mail_relay.inbound_public_key' => 'kein-schluessel']);

        $this->assertFalse((new PostalSignatureVerifier)->verify('x', base64_encode('x')));
    }

    public function test_header_follows_the_algorithm(): void
    {
        $this->assertSame('X-Postal-Signature-256', (new PostalSignatureVerifier)->headerName());

        config(['swayy.guest_mail_relay.inbound_signature_algo' => 'sha1']);
        $this->assertSame('X-Postal-Signature', (new PostalSignatureVerifier)->headerName());

        config(['swayy.guest_mail_relay.inbound_signature_header' => 'X-Eigene-Signatur']);
        $this->assertSame('X-Eigene-Signatur', (new PostalSignatureVerifier)->headerName());
    }
}
