<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Auth\AuthManager;
use PHAPI\Auth\GuardInterface;
use PHPUnit\Framework\TestCase;

class AuthManagerTest extends TestCase
{
    public function testRolesHelpers(): void
    {
        $guard = new class () implements GuardInterface {
            public function user(): ?array
            {
                return ['id' => 1, 'roles' => ['admin', 'editor']];
            }

            public function check(): bool
            {
                return true;
            }

            public function id(): ?string
            {
                return '1';
            }
        };

        $auth = new AuthManager('custom');
        $auth->addGuard('custom', $guard);

        $this->assertTrue($auth->check());
        $this->assertSame('1', $auth->id());
        $this->assertSame(['id' => 1, 'roles' => ['admin', 'editor']], $auth->user());
        $this->assertTrue($auth->hasRole('admin'));
        $this->assertTrue($auth->hasRole(['viewer', 'editor']));
        $this->assertFalse($auth->hasRole('nonexistent'));
        $this->assertFalse($auth->hasAllRoles(['admin', 'viewer']));
        $this->assertTrue($auth->hasAllRoles(['admin', 'editor']));
    }

    public function testUserDelegatesToCorrectGuard(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->expects($this->once())->method('user')->willReturn(['id' => 42]);

        $auth = new AuthManager('api');
        $auth->addGuard('api', $guard);

        $this->assertSame(['id' => 42], $auth->user());
    }

    public function testCheckDelegatesToCorrectGuard(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->expects($this->once())->method('check')->willReturn(true);

        $auth = new AuthManager('api');
        $auth->addGuard('api', $guard);

        $this->assertTrue($auth->check());
    }

    public function testIdDelegatesToCorrectGuard(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->expects($this->once())->method('id')->willReturn('99');

        $auth = new AuthManager('api');
        $auth->addGuard('api', $guard);

        $this->assertSame('99', $auth->id());
    }

    public function testNamedGuardOverridesDefault(): void
    {
        $default = $this->createMock(GuardInterface::class);
        $default->method('id')->willReturn('default-id');

        $named = $this->createMock(GuardInterface::class);
        $named->expects($this->once())->method('id')->willReturn('named-id');

        $auth = new AuthManager('default');
        $auth->addGuard('default', $default);
        $auth->addGuard('named', $named);

        $this->assertSame('named-id', $auth->id('named'));
    }

    public function testUnregisteredGuardThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Auth guard 'missing' is not registered");

        (new AuthManager())->guard('missing');
    }

    public function testHasRoleReturnsFalseWithNoUser(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->method('user')->willReturn(null);

        $auth = new AuthManager('test');
        $auth->addGuard('test', $guard);

        $this->assertFalse($auth->hasRole('admin'));
        $this->assertFalse($auth->hasAllRoles(['admin']));
    }

    public function testSetDefaultChangesActiveGuard(): void
    {
        $first = $this->createMock(GuardInterface::class);
        $first->method('id')->willReturn('first');

        $second = $this->createMock(GuardInterface::class);
        $second->method('id')->willReturn('second');

        $auth = new AuthManager('first');
        $auth->addGuard('first', $first);
        $auth->addGuard('second', $second);

        $this->assertSame('first', $auth->id());

        $auth->setDefault('second');
        $this->assertSame('second', $auth->id());
    }
}
