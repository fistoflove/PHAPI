<?php

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

        $this->assertTrue($auth->hasRole('admin'));
        $this->assertTrue($auth->hasRole(['viewer', 'editor']));
        $this->assertFalse($auth->hasAllRoles(['admin', 'viewer']));
        $this->assertTrue($auth->hasAllRoles(['admin', 'editor']));
    }
}
