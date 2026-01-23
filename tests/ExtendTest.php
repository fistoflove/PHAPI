<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\Container;
use PHAPI\PHAPI;

final class ExtendTest extends SwooleTestCase
{
    public function testExtendRegistersSingletonByDefault(): void
    {
        $api = new PHAPI(['runtime' => 'swoole']);

        $api->extend('cache', function (Container $container): object {
            return new \stdClass();
        });

        $first = $api->resolve('cache');
        $second = $api->container()->get('cache');

        self::assertSame($first, $second);
    }

    public function testExtendCanRegisterTransient(): void
    {
        $api = new PHAPI(['runtime' => 'swoole']);

        $api->extend('transient', function (Container $container): object {
            return new \stdClass();
        }, false);

        $first = $api->resolve('transient');
        $second = $api->resolve('transient');

        self::assertNotSame($first, $second);
    }
}
