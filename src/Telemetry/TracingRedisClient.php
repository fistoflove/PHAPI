<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use PHAPI\Services\RedisClient;

/**
 * Decorator that wraps RedisClient to create spans for every Redis command.
 *
 * RedisClient extends SwooleRedisClient which is not `final`, but we use
 * composition to avoid tight coupling to the inheritance chain and to
 * ensure span lifecycle is clean (no accidental calls to parent methods
 * that skip instrumentation).
 */
final class TracingRedisClient
{
    private RedisClient $inner;
    private TracerInterface $tracer;

    public function __construct(RedisClient $inner, TracerInterface $tracer)
    {
        $this->inner = $inner;
        $this->tracer = $tracer;
    }

    public function get(string $key): ?string
    {
        return $this->traced('GET', $key, fn (): ?string => $this->inner->get($key));
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        return $this->traced('SET', $key, fn (): bool => $this->inner->set($key, $value, $ttl));
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->traced('EXPIRE', $key, fn (): bool => $this->inner->expire($key, $ttl));
    }

    /**
     * @param array<string, string> $data
     */
    public function hMSet(string $key, array $data): bool
    {
        return $this->traced('HMSET', $key, fn (): bool => $this->inner->hMSet($key, $data));
    }

    public function hSet(string $key, string $field, string $value): bool
    {
        return $this->traced('HSET', $key, fn (): bool => $this->inner->hSet($key, $field, $value));
    }

    public function hIncrBy(string $key, string $field, int $value): int
    {
        return $this->traced('HINCRBY', $key, fn (): int => $this->inner->hIncrBy($key, $field, $value));
    }

    public function hGet(string $key, string $field): string|false
    {
        /** @var string|false */
        return $this->traced('HGET', $key, fn (): string|false => $this->inner->hGet($key, $field));
    }

    public function zAdd(string $key, int $score, string $member): int
    {
        return $this->traced('ZADD', $key, fn (): int => $this->inner->zAdd($key, $score, $member));
    }

    public function zRemRangeByScore(string $key, int $min, int $max): int
    {
        return $this->traced('ZREMRANGEBYSCORE', $key, fn (): int => $this->inner->zRemRangeByScore($key, $min, $max));
    }

    /**
     * @return array<int, string>
     */
    public function zRangeByScore(string $key, int $min, int $max): array
    {
        return $this->traced('ZRANGEBYSCORE', $key, fn (): array => $this->inner->zRangeByScore($key, $min, $max));
    }

    public function del(string ...$keys): int
    {
        $keyList = implode(' ', $keys);
        return $this->traced('DEL', $keyList, fn (): int => $this->inner->del(...$keys));
    }

    public function exists(string ...$keys): int
    {
        $keyList = implode(' ', $keys);
        return $this->traced('EXISTS', $keyList, fn (): int => $this->inner->exists(...$keys));
    }

    /**
     * @param array<int, mixed> $args
     */
    public function command(string $command, array $args = []): mixed
    {
        $key = $args[0] ?? '';
        return $this->traced(strtoupper($command), (string) $key, fn (): mixed => $this->inner->command($command, $args));
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function traced(string $command, string $key, callable $call): mixed
    {
        $span = $this->tracer->spanBuilder('REDIS ' . $command)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('db.statement', $command . ' ' . $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            return $call();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
