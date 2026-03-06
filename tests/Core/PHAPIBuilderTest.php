<?php

declare(strict_types=1);

namespace PHAPI\Tests\Core;

use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;
use PHPUnit\Framework\TestCase;

final class PHAPIBuilderTest extends TestCase
{
    public function testBuildReturnsPhapi(): void
    {
        $api = PHAPI::builder()->build();
        self::assertInstanceOf(PHAPI::class, $api);
    }

    public function testBuilderSetsHostAndPort(): void
    {
        $api = PHAPI::builder()
            ->host('127.0.0.1')
            ->port(8080)
            ->build();

        self::assertSame('127.0.0.1', $api->config()['host']);
        self::assertSame(8080, $api->config()['port']);
    }

    public function testBuilderSetsDebug(): void
    {
        $api = PHAPI::builder()->debug(true)->build();
        self::assertTrue($api->config()['debug']);
    }

    public function testBuilderSetsMysqlConfig(): void
    {
        $mysql = ['host' => '10.0.0.1', 'database' => 'test'];
        $api = PHAPI::builder()->mysql($mysql)->build();
        self::assertSame('10.0.0.1', $api->config()['mysql']['host']);
        self::assertSame('test', $api->config()['mysql']['database']);
    }

    public function testBuilderSetsRedisConfig(): void
    {
        $redis = ['host' => 'redis.local', 'port' => 6380];
        $api = PHAPI::builder()->redis($redis)->build();
        self::assertSame('redis.local', $api->config()['redis']['host']);
        self::assertSame(6380, $api->config()['redis']['port']);
    }

    public function testBuilderSetsOpenfgaConfig(): void
    {
        $openfga = ['api_url' => 'http://fga:8080', 'store_id' => 's1'];
        $api = PHAPI::builder()->openfga($openfga)->build();
        self::assertSame('http://fga:8080', $api->config()['openfga']['api_url']);
    }

    public function testBuilderSetsTelemetryConfig(): void
    {
        $telemetry = ['enabled' => true, 'service_name' => 'test'];
        $api = PHAPI::builder()->telemetry($telemetry)->build();
        self::assertTrue($api->config()['telemetry']['enabled']);
    }

    public function testBuilderSetsProviders(): void
    {
        $api = PHAPI::builder()->providers([])->build();
        self::assertSame([], $api->config()['providers']);
    }

    public function testBuilderSetsSwooleSettings(): void
    {
        $settings = ['worker_num' => 4];
        $api = PHAPI::builder()->swooleSettings($settings)->build();
        self::assertSame(4, $api->config()['swoole_settings']['worker_num']);
    }

    public function testBuilderSetsWebSockets(): void
    {
        $api = PHAPI::builder()->enableWebSockets()->build();
        self::assertTrue($api->config()['enable_websockets']);
    }

    public function testBuilderSetsDefaultEndpoints(): void
    {
        $api = PHAPI::builder()->defaultEndpoints(false)->build();
        self::assertFalse($api->config()['default_endpoints']);
    }

    public function testBuilderGenericConfigEscapeHatch(): void
    {
        $api = PHAPI::builder()
            ->config('custom_key', 'custom_value')
            ->build();
        self::assertSame('custom_value', $api->config()['custom_key']);
    }

    public function testInvalidPortTooLowThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid port');

        PHAPI::builder()->port(0)->build();
    }

    public function testInvalidPortTooHighThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid port');

        PHAPI::builder()->port(70000)->build();
    }

    public function testFluentChainingWorks(): void
    {
        $api = PHAPI::builder()
            ->host('0.0.0.0')
            ->port(9501)
            ->debug(false)
            ->mysql(['host' => '127.0.0.1', 'database' => 'app'])
            ->redis(['host' => '127.0.0.1'])
            ->openfga(['api_url' => 'http://localhost:8080'])
            ->providers([])
            ->swooleSettings(['worker_num' => 2])
            ->enableWebSockets(false)
            ->defaultEndpoints(false)
            ->build();

        self::assertInstanceOf(PHAPI::class, $api);
        self::assertSame('0.0.0.0', $api->config()['host']);
        self::assertSame(9501, $api->config()['port']);
    }
}
