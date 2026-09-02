<?php

declare(strict_types=1);

namespace HexaLite\Tests\Auth;

use HexaLite\Auth\Services\PasswordPolicy;
use HexaLite\Http\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testStrongPasswordPasses(): void
    {
        $this->assertTrue((new PasswordPolicy())->passes('Contra5eña!'));
    }

    /** @return array<string, array{string, string}> */
    public static function weakPasswords(): array
    {
        return [
            'muy corta'    => ['Ab1!', 'al menos 8 caracteres'],
            'sin mayúscula' => ['contraseña1!', 'mayúsculas y minúsculas'],
            'sin número'   => ['Contraseña!', 'al menos un número'],
            'sin símbolo'  => ['Contrasena1', 'carácter especial'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function testWeakPasswordsAreRejected(string $password, string $expectedFragment): void
    {
        $error = (new PasswordPolicy())->check($password);

        $this->assertNotNull($error);
        $this->assertStringContainsString($expectedFragment, $error);
    }

    public function testPolicyCanBeRelaxed(): void
    {
        $policy = new PasswordPolicy(
            minLength: 6,
            requireMixedCase: false,
            requireNumber: false,
            requireSymbol: false,
        );

        $this->assertTrue($policy->passes('simple'));
    }

    public function testLengthCountsCharactersNotBytes(): void
    {
        // 8 caracteres, pero más de 8 bytes en UTF-8: contarlos como bytes daría
        // por buena una contraseña más corta de lo que exige la política.
        $policy = new PasswordPolicy(minLength: 10, requireMixedCase: false, requireNumber: false, requireSymbol: false);

        $this->assertFalse($policy->passes('ñññññññi'));
    }

    public function testAssertThrowsHttpException(): void
    {
        $this->expectException(HttpException::class);
        (new PasswordPolicy())->assert('corta');
    }

    public function testHashIsVerifiable(): void
    {
        $policy = new PasswordPolicy();
        $hash   = $policy->hash('Contra5eña!');

        $this->assertTrue(password_verify('Contra5eña!', $hash));
        $this->assertFalse(password_verify('otra', $hash));
    }
}
