<?php

declare(strict_types=1);

namespace HexaLite\Tests\Security;

use HexaLite\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionServiceTest extends TestCase
{
    private const SECRET = 'una-clave-cualquiera-de-pruebas';

    public function testRoundTrip(): void
    {
        $enc = new EncryptionService(self::SECRET);

        $this->assertSame('app-password-de-gmail', $enc->decrypt($enc->encrypt('app-password-de-gmail')));
    }

    public function testRoundTripOfEmptyAndUnicode(): void
    {
        $enc = new EncryptionService(self::SECRET);

        $this->assertSame('', $enc->decrypt($enc->encrypt('')));
        $this->assertSame('ñÑ áé 🔐', $enc->decrypt($enc->encrypt('ñÑ áé 🔐')));
    }

    public function testCiphertextIsDifferentEveryTime(): void
    {
        $enc = new EncryptionService(self::SECRET);

        // Nonce aleatorio: dos filas con el mismo secreto no se delatan por ser iguales.
        $this->assertNotSame($enc->encrypt('mismo'), $enc->encrypt('mismo'));
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $enc = new EncryptionService(self::SECRET);

        $raw = base64_decode($enc->encrypt('secreto'), true);
        self::assertIsString($raw);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';

        $this->assertNull($enc->decrypt(base64_encode($raw)));
    }

    public function testDecryptRejectsGarbage(): void
    {
        $enc = new EncryptionService(self::SECRET);

        $this->assertNull($enc->decrypt('no-es-base64-!!'));
        $this->assertNull($enc->decrypt(''));
        $this->assertNull($enc->decrypt(base64_encode('corto')));
    }

    public function testDecryptWithAnotherKeyReturnsNull(): void
    {
        $cipher = (new EncryptionService(self::SECRET))->encrypt('secreto');

        $this->assertNull((new EncryptionService('otra-clave-distinta'))->decrypt($cipher));
    }

    public function testReadsSecretFromEnvironment(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . self::SECRET);

        try {
            $this->assertSame('hola', (new EncryptionService())->decrypt(
                (new EncryptionService(self::SECRET))->encrypt('hola')
            ));
        } finally {
            putenv('APP_ENCRYPTION_KEY');
        }
    }

    public function testFailsLoudlyWithoutSecret(): void
    {
        putenv('APP_ENCRYPTION_KEY');
        putenv('JWT_SECRET');
        unset($_ENV['APP_ENCRYPTION_KEY'], $_ENV['JWT_SECRET'], $_SERVER['APP_ENCRYPTION_KEY'], $_SERVER['JWT_SECRET']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENCRYPTION_KEY');

        new EncryptionService();
    }
}
