<?php

declare(strict_types=1);

namespace HexaLite\Tests;

use HexaLite\Http\Response;
use HexaLite\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testEnvReturnsDefaultWhenUnset(): void
    {
        $this->assertSame('fallback', env('HEXALITE_DEFINITELY_UNSET_VAR', 'fallback'));
    }

    public function testEnvCastsLiterals(): void
    {
        putenv('HEXALITE_TEST_BOOL=true');
        putenv('HEXALITE_TEST_FALSE=false');
        putenv('HEXALITE_TEST_NULL=null');

        try {
            $this->assertTrue(env('HEXALITE_TEST_BOOL'));
            $this->assertFalse(env('HEXALITE_TEST_FALSE'));
            $this->assertNull(env('HEXALITE_TEST_NULL'));
        } finally {
            putenv('HEXALITE_TEST_BOOL');
            putenv('HEXALITE_TEST_FALSE');
            putenv('HEXALITE_TEST_NULL');
        }
    }

    public function testEnvReturnsRawStringValue(): void
    {
        putenv('HEXALITE_TEST_STR=hola');
        try {
            $this->assertSame('hola', env('HEXALITE_TEST_STR'));
        } finally {
            putenv('HEXALITE_TEST_STR');
        }
    }

    public function testResponseWithDataReturnsJsonResponse(): void
    {
        $response = response(['ok' => true], 201);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testResponseWithoutArgumentsReturnsFactory(): void
    {
        $this->assertInstanceOf(ResponseFactory::class, response());
    }
}
