<?php

declare(strict_types=1);

namespace HexaLite\Tests\Validation;

use HexaLite\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidDataProducesNoErrors(): void
    {
        $validator = new Validator([
            'email'    => 'ada@example.com',
            'password' => 'supersecret',
        ]);

        $errors = $validator->validate([
            'email'    => 'required|email',
            'password' => 'required|min:8',
        ]);

        $this->assertSame([], $errors);
    }

    public function testRequiredFailsOnMissingField(): void
    {
        $validator = new Validator([]);
        $errors = $validator->validate(['name' => 'required']);

        $this->assertArrayHasKey('name', $errors);
    }

    public function testRequiredFailsOnEmptyString(): void
    {
        $validator = new Validator(['name' => '']);
        $errors = $validator->validate(['name' => 'required']);

        $this->assertArrayHasKey('name', $errors);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $validator = new Validator(['email' => 'not-an-email']);
        $errors = $validator->validate(['email' => 'required|email']);

        $this->assertArrayHasKey('email', $errors);
    }

    public function testMinRuleUsesStringLength(): void
    {
        $validator = new Validator(['password' => 'short']);
        $errors = $validator->validate(['password' => 'min:8']);

        $this->assertArrayHasKey('password', $errors);
    }

    public function testMaxRuleUsesStringLength(): void
    {
        $validator = new Validator(['bio' => str_repeat('a', 300)]);
        $errors = $validator->validate(['bio' => 'max:255']);

        $this->assertArrayHasKey('bio', $errors);
    }

    public function testNullableSkipsRemainingRulesWhenNull(): void
    {
        $validator = new Validator(['nickname' => null]);
        $errors = $validator->validate(['nickname' => 'nullable|min:3']);

        $this->assertSame([], $errors);
    }

    public function testCollectsAllErrorsForAField(): void
    {
        // 'string' pasa para numérico, así que forzamos dos fallos reales:
        // email inválido + longitud mínima incumplida.
        $validator = new Validator(['handle' => 'no']);
        $errors = $validator->validate(['handle' => 'email|min:5']);

        $this->assertArrayHasKey('handle', $errors);
        $this->assertCount(2, $errors['handle']);
    }

    public function testInRuleAcceptsAllowedValueAndRejectsOthers(): void
    {
        $ok = (new Validator(['role' => 'admin']))->validate(['role' => 'in:admin,editor']);
        $ko = (new Validator(['role' => 'guest']))->validate(['role' => 'in:admin,editor']);

        $this->assertSame([], $ok);
        $this->assertArrayHasKey('role', $ko);
    }

    public function testCustomMessageIsUsed(): void
    {
        $validator = new Validator([]);
        $errors = $validator->validate(
            ['name' => 'required'],
            ['name' => ['required' => 'El nombre es obligatorio']],
        );

        $this->assertSame(['El nombre es obligatorio'], $errors['name']);
    }
}
