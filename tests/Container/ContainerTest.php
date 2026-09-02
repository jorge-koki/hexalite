<?php

declare(strict_types=1);

namespace HexaLite\Tests\Container;

use HexaLite\Container\Container;
use HexaLite\Container\CircularDependencyException;
use HexaLite\Container\NotFoundException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testResolvesClassWithoutDependencies(): void
    {
        $c = new Container();
        $service = $c->get(NoDeps::class);

        $this->assertInstanceOf(NoDeps::class, $service);
    }

    public function testAutowiresNestedDependencies(): void
    {
        $c = new Container();
        $service = $c->get(NeedsDep::class);

        $this->assertInstanceOf(NeedsDep::class, $service);
        $this->assertInstanceOf(NoDeps::class, $service->dep);
    }

    public function testResolvesSameSingletonInstance(): void
    {
        $c = new Container();

        $this->assertSame($c->get(NoDeps::class), $c->get(NoDeps::class));
    }

    public function testSetRegistersConcreteInstance(): void
    {
        $c = new Container();
        $instance = new NoDeps();
        $c->set(NoDeps::class, $instance);

        $this->assertSame($instance, $c->get(NoDeps::class));
    }

    public function testFactoryIsLazyAndCached(): void
    {
        $c = new Container();
        $calls = 0;
        $c->setFactory(NoDeps::class, function () use (&$calls) {
            $calls++;
            return new NoDeps();
        });

        $first  = $c->get(NoDeps::class);
        $second = $c->get(NoDeps::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls, 'La factoría singleton solo debe ejecutarse una vez');
    }

    public function testTransientReturnsFreshInstances(): void
    {
        $c = new Container();
        $c->transient(NoDeps::class, fn () => new NoDeps());

        $this->assertNotSame($c->get(NoDeps::class), $c->get(NoDeps::class));
    }

    public function testBindResolvesInterfaceToImplementation(): void
    {
        $c = new Container();
        $c->bind(GreeterInterface::class, EnglishGreeter::class);

        $greeter = $c->get(GreeterInterface::class);

        $this->assertInstanceOf(EnglishGreeter::class, $greeter);
        $this->assertSame('hello', $greeter->greet());
    }

    public function testHasReturnsTrueForResolvableClass(): void
    {
        $c = new Container();

        $this->assertTrue($c->has(NoDeps::class));
        $this->assertFalse($c->has('Totally\\Missing\\Class'));
    }

    public function testThrowsOnMissingClass(): void
    {
        $c = new Container();

        $this->expectException(NotFoundException::class);
        $c->get('Totally\\Missing\\Class');
    }

    public function testDetectsCircularDependency(): void
    {
        $c = new Container();

        $this->expectException(CircularDependencyException::class);
        $c->get(CircularA::class);
    }
}

// ── Fixtures ─────────────────────────────────────────────────────────────────

class NoDeps
{
}

class NeedsDep
{
    public function __construct(public NoDeps $dep)
    {
    }
}

interface GreeterInterface
{
    public function greet(): string;
}

class EnglishGreeter implements GreeterInterface
{
    public function greet(): string
    {
        return 'hello';
    }
}

class CircularA
{
    public function __construct(public CircularB $b)
    {
    }
}

class CircularB
{
    public function __construct(public CircularA $a)
    {
    }
}
