<?php

namespace PHAPI\Tests;

use PHAPI\Auth\TokenGuard;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHPUnit\Framework\TestCase;

class TokenGuardTest extends TestCase
{
    public function testResetsPerRequest(): void
    {
        $resolver = function (string $token) {
            return ['id' => $token];
        };

        $guard = new TokenGuard($resolver);

        $req1 = new Request('GET', '/', [], ['authorization' => 'Bearer alpha']);
        RequestContext::set($req1);
        $this->assertSame('alpha', $guard->id());

        $req2 = new Request('GET', '/', [], ['authorization' => 'Bearer beta']);
        RequestContext::set($req2);
        $this->assertSame('beta', $guard->id());

        RequestContext::clear();
    }
}
