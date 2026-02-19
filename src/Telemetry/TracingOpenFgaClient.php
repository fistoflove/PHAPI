<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use PHAPI\Services\OpenFgaClient;

/**
 * Decorator that wraps OpenFgaClient to add FGA-specific semantic spans.
 *
 * The underlying OpenFgaHttpClient already delegates to HttpClient, which
 * is instrumented by TracingHttpClient. This decorator adds a higher-level
 * span with FGA domain attributes (user, relation, object) so traces
 * show both the logical FGA operation and the physical HTTP call.
 */
final class TracingOpenFgaClient implements OpenFgaClient
{
    private OpenFgaClient $inner;
    private TracerInterface $tracer;

    public function __construct(OpenFgaClient $inner, TracerInterface $tracer)
    {
        $this->inner = $inner;
        $this->tracer = $tracer;
    }

    public function check(string $user, string $relation, string $object): bool
    {
        return $this->traced('FGA check', ['fga.user' => $user, 'fga.relation' => $relation, 'fga.object' => $object], fn (): bool => $this->inner->check($user, $relation, $object));
    }

    /**
     * @param array<int, array{user: string, relation: string, object: string, correlation_id: string}> $checks
     * @return array<string, bool>
     */
    public function batchCheck(array $checks): array
    {
        return $this->traced('FGA batchCheck', ['fga.batch_size' => count($checks)], function () use ($checks): array {
            return $this->inner->batchCheck($checks);
        });
    }

    /**
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     */
    public function writeTuples(array $writes): void
    {
        $this->traced('FGA writeTuples', ['fga.tuple_count' => count($writes)], function () use ($writes): bool {
            $this->inner->writeTuples($writes);
            return true;
        });
    }

    /**
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     */
    public function deleteTuples(array $deletes): void
    {
        $this->traced('FGA deleteTuples', ['fga.tuple_count' => count($deletes)], function () use ($deletes): bool {
            $this->inner->deleteTuples($deletes);
            return true;
        });
    }

    /**
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    public function readTuples(?string $user, ?string $relation, ?string $object): array
    {
        $attrs = array_filter([
            'fga.user' => $user,
            'fga.relation' => $relation,
            'fga.object' => $object,
        ], static fn (mixed $v): bool => $v !== null);

        return $this->traced('FGA readTuples', $attrs, fn (): array => $this->inner->readTuples($user, $relation, $object));
    }

    /**
     * @return array<int, string>
     */
    public function listObjects(string $user, string $relation, string $type): array
    {
        return $this->traced('FGA listObjects', [
            'fga.user' => $user,
            'fga.relation' => $relation,
            'fga.object_type' => $type,
        ], fn (): array => $this->inner->listObjects($user, $relation, $type));
    }

    /**
     * @return array<int, string>
     */
    public function listUsers(string $object, string $relation, string $userType): array
    {
        return $this->traced('FGA listUsers', [
            'fga.object' => $object,
            'fga.relation' => $relation,
            'fga.user_type' => $userType,
        ], fn (): array => $this->inner->listUsers($object, $relation, $userType));
    }

    /**
     * @return array<string, mixed>
     */
    public function expand(string $relation, string $object): array
    {
        return $this->traced('FGA expand', [
            'fga.relation' => $relation,
            'fga.object' => $object,
        ], fn (): array => $this->inner->expand($relation, $object));
    }

    /**
     * @param array<int, array<string, mixed>> $typeDefinitions
     */
    public function writeAuthorizationModel(array $typeDefinitions, string $schemaVersion): string
    {
        return $this->traced('FGA writeAuthorizationModel', [], fn (): string => $this->inner->writeAuthorizationModel($typeDefinitions, $schemaVersion));
    }

    /**
     * @return array<string, mixed>
     */
    public function readAuthorizationModel(?string $id = null): array
    {
        return $this->traced('FGA readAuthorizationModel', [], fn (): array => $this->inner->readAuthorizationModel($id));
    }

    /**
     * @template T
     * @param non-empty-string $spanName
     * @param array<string, mixed> $attributes
     * @param callable(): T $call
     * @return T
     */
    private function traced(string $spanName, array $attributes, callable $call): mixed
    {
        $spanBuilder = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT);

        foreach ($attributes as $key => $value) {
            if (\is_string($value) || \is_int($value)) {
                $spanBuilder->setAttribute($key, $value);
            }
        }

        $span = $spanBuilder->startSpan();
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
