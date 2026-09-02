<?php

declare(strict_types=1);

namespace HexaLite\Tests\Http\DTO;

use HexaLite\Http\DTO\Dtos;
use HexaLite\Http\DTO\Attributes\IsEmail;
use HexaLite\Http\DTO\Attributes\IsInt;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Attributes\Nullable;
use HexaLite\Http\ValidationException;
use PHPUnit\Framework\TestCase;

final class DtosTest extends TestCase
{
    public function testHydratesFromValidArray(): void
    {
        $dto = SampleUserDto::fromArray([
            'name'  => 'Grace',
            'email' => 'grace@example.com',
        ]);

        $this->assertSame('Grace', $dto->name);
        $this->assertSame('grace@example.com', $dto->email);
    }

    public function testCastsBuiltinTypes(): void
    {
        $dto = SampleUserDto::fromArray([
            'name'  => 'Grace',
            'email' => 'grace@example.com',
            'age'   => '42',           // llega como string, debe castear a int
        ]);

        $this->assertSame(42, $dto->age);
    }

    public function testNullableFieldDefaultsToNull(): void
    {
        $dto = SampleUserDto::fromArray([
            'name'  => 'Grace',
            'email' => 'grace@example.com',
        ]);

        $this->assertNull($dto->age);
        $this->assertFalse($dto->has('age'));
    }

    public function testThrowsOnInvalidData(): void
    {
        $this->expectException(ValidationException::class);

        SampleUserDto::fromArray([
            'name'  => 'x',                 // incumple min:2
            'email' => 'not-an-email',      // email inválido
        ]);
    }

    public function testRejectsUnknownFields(): void
    {
        $this->expectException(ValidationException::class);

        SampleUserDto::fromArray([
            'name'    => 'Grace',
            'email'   => 'grace@example.com',
            'is_admin' => true,             // campo no declarado en el DTO
        ]);
    }

    public function testToArrayOnlyAndExcept(): void
    {
        $dto = SampleUserDto::fromArray([
            'name'  => 'Grace',
            'email' => 'grace@example.com',
            'age'   => '30',
        ]);

        $this->assertSame(
            ['name' => 'Grace', 'email' => 'grace@example.com', 'age' => 30],
            $dto->toArray(),
        );
        $this->assertSame(['email' => 'grace@example.com'], $dto->only(['email']));
        $this->assertArrayNotHasKey('email', $dto->except(['email']));
    }

    public function testIsDtoClass(): void
    {
        $this->assertTrue(Dtos::isDtoClass(SampleUserDto::class));
        $this->assertFalse(Dtos::isDtoClass(self::class));
    }
}

// ── Fixture DTO ──────────────────────────────────────────────────────────────

final class SampleUserDto extends Dtos
{
    #[IsRequired]
    #[IsString]
    #[Min(2)]
    public string $name;

    #[IsRequired]
    #[IsEmail]
    public string $email;

    #[Nullable]
    #[IsInt]
    public ?int $age;
}
