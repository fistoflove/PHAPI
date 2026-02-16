<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\Container;
use PHAPI\Exceptions\ContainerException;
use PHAPI\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testGetUnregisteredIdThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Service 'nonexistent' not found");

        (new Container())->get('nonexistent');
    }

    public function testSetAndGet(): void
    {
        $container = new Container();
        $container->set('key', 'value');

        $this->assertTrue($container->has('key'));
        $this->assertSame('value', $container->get('key'));
    }

    public function testBindOverwritesPrevious(): void
    {
        $container = new Container();
        $container->set('key', 'first');
        $container->bind('key', fn () => 'second');

        $this->assertSame('second', $container->get('key'));
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton('counter', fn () => new \stdClass());

        $a = $container->get('counter');
        $b = $container->get('counter');

        $this->assertSame($a, $b);
    }

    public function testTransientReturnsNewInstance(): void
    {
        $container = new Container();
        $container->bind('counter', fn () => new \stdClass(), false);

        $a = $container->get('counter');
        $b = $container->get('counter');

        $this->assertNotSame($a, $b);
    }

    public function testRequestScopeClearsOnEnd(): void
    {
        $container = new Container();
        $container->request('req', fn () => new \stdClass());

        $container->beginRequestScope();
        $a = $container->get('req');
        $this->assertSame($a, $container->get('req'));

        $container->endRequestScope();
        $container->beginRequestScope();
        $b = $container->get('req');

        $this->assertNotSame($a, $b);
    }

    public function testHasReturnsFalseForUnknown(): void
    {
        $container = new Container();
        $this->assertFalse($container->has('unknown_service_xyz'));
    }

    public function testHasReturnsTrueForExistingClass(): void
    {
        $container = new Container();
        $this->assertTrue($container->has(\stdClass::class));
    }

    public function testAutowireFailsForBuiltinWithoutDefault(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot autowire');

        $container = new Container();
        $container->get(NeedsBuiltinParam::class);
    }
}

/** @internal test helper */
class NeedsBuiltinParam
{
    public function __construct(int $value)
    {
    }
}
