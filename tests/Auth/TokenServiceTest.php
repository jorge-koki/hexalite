<?php

declare(strict_types=1);

namespace HexaLite\Tests\Auth;

use HexaLite\Auth\Exceptions\ExpiredTokenException;
use HexaLite\Auth\Exceptions\InvalidTokenException;
use HexaLite\Auth\Services\TokenService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenServiceTest extends TestCase
{
    private function service(int $accessTtl = 900): TokenService
    {
        return new TokenService('clave-de-acceso', 'clave-de-refresco', accessTtl: $accessTtl);
    }

    public function testAccessTokenRoundTrip(): void
    {
        $service = $this->service();
        $payload = $service->validateAccessToken($service->createAccessToken(42));

        $this->assertSame(42, $payload->sub);
        $this->assertTrue($payload->isAccessToken());
    }

    public function testCustomClaimsSurviveTheRoundTrip(): void
    {
        $service = $this->service();
        $token   = $service->createAccessToken(7, ['tenant_id' => 99, 'plan' => 'pro']);

        $payload = $service->validateAccessToken($token);

        $this->assertSame(99, $payload->get('tenant_id'));
        $this->assertSame('pro', $payload->get('plan'));
    }

    public function testRefreshTokenIsNotAcceptedAsAccessToken(): void
    {
        $service = $this->service();

        $this->expectException(InvalidTokenException::class);
        $service->validateAccessToken($service->createRefreshToken(1));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $service = $this->service();
        [$header, $payload, $signature] = explode('.', $service->createAccessToken(1));

        // Se cambia el `sub` a 999 conservando la firma original.
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'iss' => 'hexalite', 'iat' => time(), 'exp' => time() + 900, 'sub' => 999, 'type' => 'access',
        ])), '+/', '-_'), '=');

        $this->expectException(InvalidTokenException::class);
        $service->validateAccessToken("$header.$forged.$signature");
    }

    public function testAlgNoneIsRejected(): void
    {
        $service = $this->service();

        $b64 = static fn(array $data): string => rtrim(
            strtr(base64_encode((string) json_encode($data)), '+/', '-_'),
            '='
        );

        $token = $b64(['typ' => 'JWT', 'alg' => 'none'])
            . '.' . $b64(['sub' => 1, 'type' => 'access', 'exp' => time() + 900])
            . '.';

        $this->expectException(InvalidTokenException::class);
        $service->validateAccessToken($token);
    }

    public function testExpiredTokenRaisesExpiredException(): void
    {
        // TTL negativo y por debajo de la tolerancia de reloj (30 s).
        $service = new TokenService('a', 'b', accessTtl: -120);

        $this->expectException(ExpiredTokenException::class);
        $service->validateAccessToken($service->createAccessToken(1));
    }

    public function testAccessSecretCannotValidateRefreshToken(): void
    {
        $service = $this->service();
        $refresh = $service->createRefreshToken(1);

        // Firmado con la clave de refresco: la de acceso no debe poder validarlo.
        $this->expectException(InvalidTokenException::class);
        $service->validateAccessToken($refresh);
    }

    public function testEmptySecretsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new TokenService('', '');
    }

    public function testIdenticalSecretsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new TokenService('la-misma', 'la-misma');
    }
}
