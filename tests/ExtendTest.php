<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\Container;
use PHAPI\PHAPI;

final class ExtendTest extends SwooleTestCase
{
    public function testExtendRegistersSingletonByDefault(): void
    {
        $api = new PHAPI();

        $api->container()->singleton('cache', function (Container $container): object {
            return new \stdClass();
        });

        $first = $api->container()->get('cache');
        $second = $api->container()->get('cache');

        self::assertSame($first, $second);
    }

    public function testExtendCanRegisterTransient(): void
    {
        $api = new PHAPI();

        $api->container()->bind('transient', function (Container $container): object {
            return new \stdClass();
        }, false);

        $first = $api->container()->get('transient');
        $second = $api->container()->get('transient');

        self::assertNotSame($first, $second);
    }
}
